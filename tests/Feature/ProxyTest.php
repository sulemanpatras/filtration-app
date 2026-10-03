<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Shopify\CatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProxyTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['domain' => 'demo.myshopify.com', 'access_token' => 'x']);
        $importer = new CatalogImporter($this->shop);
        $big = ['id' => 'gid://shopify/Collection/10', 'handle' => 'big', 'title' => 'Big'];
        $other = ['id' => 'gid://shopify/Collection/11', 'handle' => 'other', 'title' => 'Other'];

        $importer->upsert(self::node(1, 'Red Shoe', 'Nike', 'Shoes', ['summer', 'sale']), [
            self::variant('50.00', true, ['Color' => 'Red', 'Size' => '9'], '80.00'),
            self::variant('55.00', false, ['Color' => 'Red', 'Size' => '10']),
        ], [$big]);
        $importer->upsert(self::node(2, 'Blue Shoe', 'Adidas', 'Shoes', ['summer']), [
            self::variant('120.00', true, ['Color' => 'Blue', 'Size' => '9']),
        ], [$big]);
        $importer->upsert(self::node(3, 'Red Shirt', 'Nike', 'Shirts', ['winter']), [
            self::variant('20.00', false, ['Color' => 'Red', 'Size' => 'M']),
        ], [$big, $other]);
        $importer->upsert(self::node(4, 'Draft', 'Nike', 'Shoes', [], 'DRAFT'), [
            self::variant('10.00', true, []),
        ], [$big]);
    }

    /** @param array<string, string> $query */
    public static function sign(array $query): array
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = "$k=$v";
        }
        sort($pairs);

        return $query + ['signature' => hash_hmac('sha256', implode('', $pairs), 'test-secret')];
    }

    private function proxy(array $params): TestResponse
    {
        $query = self::sign($params + [
            'shop' => 'demo.myshopify.com',
            'path_prefix' => '/apps/big-filters',
            'timestamp' => (string) time(),
        ]);

        return $this->getJson('/proxy/products?'.http_build_query($query));
    }

    public function test_rejects_unsigned_requests(): void
    {
        $this->getJson('/proxy/products?shop=demo.myshopify.com&collection=big')->assertUnauthorized();
    }

    public function test_lists_published_products_with_facets(): void
    {
        $response = $this->proxy(['collection' => 'big', 'sort' => 'price-ascending'])->assertOk();

        $response->assertJsonPath('total', 3)
            ->assertJsonPath('products.0.title', 'Red Shirt')
            ->assertJsonPath('products.0.price_min', 2000)
            ->assertJsonPath('products.1.compare_at_price', 8000)
            ->assertJsonPath('facets.vendor', [['value' => 'Nike', 'count' => 2], ['value' => 'Adidas', 'count' => 1]])
            ->assertJsonPath('facets.available', ['in_stock' => 2, 'out_of_stock' => 1])
            ->assertJsonPath('facets.price', ['min' => 20, 'max' => 120]);

        $color = collect($response->json('facets.options'))->firstWhere('name', 'Color');
        $this->assertSame([['value' => 'Red', 'count' => 2], ['value' => 'Blue', 'count' => 1]], $color['values']);
    }

    public function test_filters_are_disjunctive_within_a_facet(): void
    {
        $response = $this->proxy([
            'collection' => 'big',
            'f' => json_encode(['vendor' => ['Nike'], 'options' => ['Color' => ['Red']], 'available' => true]),
        ])->assertOk();

        $response->assertJsonPath('total', 1)->assertJsonPath('collection_total', 3)->assertJsonPath('products.0.title', 'Red Shoe');

        // Vendor counts ignore the vendor selection but respect the other filters.
        $response->assertJsonPath('facets.vendor', [['value' => 'Nike', 'count' => 1]]);
        // Color counts ignore the color selection: Nike + in stock -> only Red Shoe.
        $color = collect($response->json('facets.options'))->firstWhere('name', 'Color');
        $this->assertSame([['value' => 'Red', 'count' => 1]], $color['values']);
        $response->assertJsonPath('facets.available', ['in_stock' => 1, 'out_of_stock' => 1]);
    }

    public function test_tags_price_and_collections(): void
    {
        $this->proxy(['collection' => 'big', 'f' => json_encode(['tag' => ['summer'], 'price' => ['min' => 60]])])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.title', 'Blue Shoe');

        $this->proxy(['collection' => 'other'])->assertJsonPath('total', 1);
        $this->proxy(['collection' => 'all'])->assertJsonPath('total', 3);
        $this->proxy(['collection' => 'missing'])->assertJsonPath('total', 0);
    }

    public function test_imports_bulk_jsonl(): void
    {
        $lines = [
            self::node(1, 'Red Shoe v2', 'Nike', 'Shoes', ['summer']),
            ['id' => 'gid://shopify/ProductVariant/100', '__parentId' => 'gid://shopify/Product/1'] + self::variant('45.00', true, ['Color' => 'Green']),
            ['id' => 'gid://shopify/Collection/11', 'handle' => 'other', 'title' => 'Other', '__parentId' => 'gid://shopify/Product/1'],
            self::node(5, 'New Hat', 'Puma', 'Hats', []),
            ['id' => 'gid://shopify/ProductVariant/101', '__parentId' => 'gid://shopify/Product/5'] + self::variant('15.00', true, []),
        ];
        $path = tempnam(sys_get_temp_dir(), 'jsonl');
        file_put_contents($path, implode("\n", array_map('json_encode', $lines))."\n");

        $importer = new CatalogImporter($this->shop);
        $seen = $importer->importJsonl($path);
        $importer->deleteProductsExcept($seen);
        unlink($path);

        $this->assertSame([1, 5], $seen);
        $this->proxy(['collection' => 'all', 'sort' => 'title-ascending'])
            ->assertJsonPath('total', 2)
            ->assertJsonPath('products.0.title', 'New Hat')
            ->assertJsonPath('products.1.price_min', 4500);
        $this->proxy(['collection' => 'other'])->assertJsonPath('products.0.title', 'Red Shoe v2');
        $this->proxy(['collection' => 'big'])->assertJsonPath('total', 0);
    }

    private static function node(int $id, string $title, string $vendor, string $type, array $tags, string $status = 'ACTIVE'): array
    {
        return [
            'id' => "gid://shopify/Product/$id",
            'handle' => str($title)->slug()->toString(),
            'title' => $title,
            'vendor' => $vendor,
            'productType' => $type,
            'tags' => $tags,
            'status' => $status,
            'publishedAt' => $status === 'ACTIVE' ? now()->toIso8601String() : null,
            'createdAt' => now()->subDays($id)->toIso8601String(),
            'featuredMedia' => ['preview' => ['image' => ['url' => "https://cdn.shopify.com/$id.jpg", 'altText' => null]]],
        ];
    }

    private static function variant(string $price, bool $available, array $options, ?string $compareAt = null): array
    {
        return [
            'price' => $price,
            'compareAtPrice' => $compareAt,
            'availableForSale' => $available,
            'selectedOptions' => collect($options)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all()
                ?: [['name' => 'Title', 'value' => 'Default Title']],
        ];
    }
}
