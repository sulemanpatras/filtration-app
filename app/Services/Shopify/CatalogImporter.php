<?php

namespace App\Services\Shopify;

use App\Models\Collection;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CatalogImporter
{
    /** @var array<int, int> Shopify collection id => local collection id */
    private array $collectionIds = [];

    public function __construct(private readonly Shop $shop) {}

    public static function numericId(string $gid): int
    {
        return (int) substr($gid, strrpos($gid, '/') + 1);
    }

    /**
     * Upsert one product with its tags, variant options and collection membership.
     *
     * @param  array<string, mixed>  $node  Product fields from ProductQueries
     * @param  array<int, array<string, mixed>>  $variants
     * @param  array<int, array{id: string, handle: string, title: string}>  $collections
     */
    public function upsert(array $node, array $variants, array $collections): Product
    {
        $variants = collect($variants);
        $prices = $variants->map(fn ($v) => (float) $v['price']);
        $cheapest = $variants->sortBy(fn ($v) => (float) $v['price'])->first();
        $compareAt = isset($cheapest['compareAtPrice']) && (float) $cheapest['compareAtPrice'] > (float) $cheapest['price']
            ? (float) $cheapest['compareAtPrice']
            : null;
        $image = $node['featuredMedia']['preview']['image'] ?? null;

        return DB::transaction(function () use ($node, $variants, $collections, $prices, $compareAt, $image) {
            $product = Product::updateOrCreate(
                ['shop_id' => $this->shop->id, 'shopify_id' => self::numericId($node['id'])],
                [
                    'handle' => $node['handle'],
                    'title' => mb_substr($node['title'], 0, 255),
                    'vendor' => ($node['vendor'] ?? '') ?: null,
                    'product_type' => ($node['productType'] ?? '') ?: null,
                    'image_url' => $image['url'] ?? null,
                    'image_alt' => isset($image['altText']) ? mb_substr($image['altText'], 0, 255) : null,
                    'price_min' => $prices->min() ?? 0,
                    'price_max' => $prices->max() ?? 0,
                    'compare_at_price' => $compareAt,
                    'available' => $variants->contains(fn ($v) => (bool) ($v['availableForSale'] ?? false)),
                    // publishedAt is set when the product is published to the Online Store (onlineStoreUrl is null on password-protected stores).
                    'published' => ($node['status'] ?? null) === 'ACTIVE' && ! empty($node['publishedAt']),
                    'shopify_created_at' => isset($node['createdAt']) ? Carbon::parse($node['createdAt']) : null,
                ],
            );

            DB::table('product_tags')->where('product_id', $product->id)->delete();
            DB::table('product_tags')->insert(
                collect($node['tags'] ?? [])
                    ->map(fn ($t) => mb_substr(trim($t), 0, 255))
                    ->filter()
                    ->unique()
                    ->map(fn ($t) => ['product_id' => $product->id, 'tag' => $t])
                    ->values()
                    ->all()
            );

            DB::table('product_options')->where('product_id', $product->id)->delete();
            DB::table('product_options')->insert(
                $variants
                    ->flatMap(fn ($v) => $v['selectedOptions'] ?? [])
                    // Shopify's placeholder option on single-variant products.
                    ->reject(fn ($o) => $o['name'] === 'Title' && $o['value'] === 'Default Title')
                    ->map(fn ($o) => [
                        'product_id' => $product->id,
                        'name' => mb_substr($o['name'], 0, 255),
                        'value' => mb_substr($o['value'], 0, 255),
                    ])
                    ->unique(fn ($o) => $o['name']."\0".$o['value'])
                    ->values()
                    ->all()
            );

            $product->collections()->sync(array_map(fn ($c) => $this->collectionId($c), $collections));

            return $product;
        });
    }

    public function upsertCollection(int $shopifyId, string $handle, string $title): Collection
    {
        $collection = Collection::updateOrCreate(
            ['shop_id' => $this->shop->id, 'shopify_id' => $shopifyId],
            ['handle' => $handle, 'title' => mb_substr($title, 0, 255)],
        );
        $this->collectionIds[$shopifyId] = $collection->id;

        return $collection;
    }

    /**
     * Stream a bulk-operation JSONL file into the database.
     *
     * Child rows (variants, collections) reference their product via __parentId and
     * follow the product line, so a product is complete once the next product starts.
     *
     * @return array<int, int> Shopify ids of all imported products
     */
    public function importJsonl(string $path): array
    {
        $handle = fopen($path, 'r');
        $seen = [];
        $current = null;

        $flush = function () use (&$current, &$seen) {
            if ($current) {
                $this->upsert($current['node'], $current['variants'], $current['collections']);
                $seen[] = self::numericId($current['node']['id']);
            }
            $current = null;
        };

        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            if (! isset($row['__parentId'])) {
                $flush();
                $current = ['node' => $row, 'variants' => [], 'collections' => []];
            } elseif ($current && $row['__parentId'] === $current['node']['id']) {
                if (str_contains($row['id'], '/ProductVariant/')) {
                    $current['variants'][] = $row;
                } elseif (str_contains($row['id'], '/Collection/')) {
                    $current['collections'][] = $row;
                }
            }
        }
        $flush();
        fclose($handle);

        return $seen;
    }

    /** @param array<int, int> $keepShopifyIds */
    public function deleteProductsExcept(array $keepShopifyIds): void
    {
        $keep = array_flip($keepShopifyIds);

        Product::where('shop_id', $this->shop->id)
            ->select(['id', 'shopify_id'])
            ->lazyById()
            ->reject(fn ($p) => isset($keep[$p->shopify_id]))
            ->chunk(500)
            ->each(fn ($chunk) => Product::whereIn('id', $chunk->pluck('id'))->delete());
    }

    /** @param array{id: string, handle: string, title: string} $collection */
    private function collectionId(array $collection): int
    {
        $shopifyId = self::numericId($collection['id']);

        return $this->collectionIds[$shopifyId]
            ??= $this->upsertCollection($shopifyId, $collection['handle'], $collection['title'])->id;
    }
}
