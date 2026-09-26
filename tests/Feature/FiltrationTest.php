<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Product;
use App\Models\Shop;
use App\Services\FilterEngineService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiltrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_successfully(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
    }

    public function test_can_generate_over_5000_products_and_filter_them(): void
    {
        $shop = Shop::create([
            'shop_domain' => 'mega-test-store.myshopify.com',
            'name' => 'Mega Test Store',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'is_active' => true,
        ]);

        $collection = Collection::create([
            'shop_id' => $shop->id,
            'shopify_id' => 'gid://shopify/Collection/999999999',
            'title' => 'Huge Test Catalog',
            'handle' => 'huge-catalog',
            'products_count' => 5200,
        ]);

        $vendors = ['Nike', 'Adidas', 'Puma', 'Under Armour', 'New Balance'];
        $types = ['Sneakers', 'Hoodies', 'T-Shirts', 'Jackets', 'Pants'];
        $now = now()->toDateTimeString();
        $productIds = [];

        foreach (range(1, 5200) as $j) {
            $product = Product::create([
                'shop_id' => $shop->id,
                'shopify_id' => "test_{$j}",
                'title' => "Test Product #{$j}",
                'handle' => "test-product-{$j}",
                'vendor' => $vendors[$j % count($vendors)],
                'product_type' => $types[($j * 3) % count($types)],
                'tags' => [],
                'min_price' => round(15 + (($j * 17) % 350), 2),
                'max_price' => round(15 + (($j * 17) % 350), 2),
                'is_available' => true,
                'status' => 'active',
                'published_at' => $now,
            ]);
            $productIds[] = $product->id;
        }
        $collection->products()->sync($productIds);

        // Verify product count exceeds 5,000
        $count = Product::where('shop_id', $shop->id)->count();
        $this->assertGreaterThanOrEqual(5000, $count);

        // Test Storefront Facets API
        $facetResponse = $this->getJson("/api/storefront/facets?shop={$shop->shop_domain}&collection_handle=huge-catalog");
        $facetResponse->assertStatus(200);
        $facetData = $facetResponse->json();

        $this->assertGreaterThanOrEqual(5000, $facetData['total_products']);
        $this->assertArrayHasKey('facets', $facetData);
        $this->assertTrue($facetData['facets']['price']['enabled']);
        $this->assertNotEmpty($facetData['facets']['vendors']['items']);
        $this->assertNotEmpty($facetData['facets']['types']['items']);
        $this->assertNotEmpty($facetData['facets']['availability']['items']);

        // Test Storefront Products Filtering by Vendor
        $firstVendor = $facetData['facets']['vendors']['items'][0]['value'];
        $productResponse = $this->getJson("/api/storefront/products?shop={$shop->shop_domain}&collection_handle=huge-catalog&vendors[]={$firstVendor}&limit=20");
        $productResponse->assertStatus(200);
        $productData = $productResponse->json();

        $this->assertNotEmpty($productData['products']);
        foreach ($productData['products'] as $item) {
            $this->assertEquals($firstVendor, $item['vendor']);
        }

        // Test Storefront Products Filtering by Price Range
        $priceFilteredResponse = $this->getJson("/api/storefront/products?shop={$shop->shop_domain}&collection_handle=huge-catalog&price_min=50&price_max=100&limit=20");
        $priceFilteredResponse->assertStatus(200);
        $priceData = $priceFilteredResponse->json();

        $this->assertNotEmpty($priceData['products']);
        foreach ($priceData['products'] as $item) {
            $this->assertGreaterThanOrEqual(50, $item['min_price']);
            $this->assertLessThanOrEqual(100, $item['min_price']);
        }
    }

    public function test_hmac_verification(): void
    {
        config(['shopify.client_secret' => 'test_secret_12345']);

        $service = new ShopifyService();
        $params = [
            'shop' => 'example.myshopify.com',
            'timestamp' => '1711111111',
            'code' => 'xyzabc',
        ];

        // Compute valid HMAC
        ksort($params);
        $validHmac = hash_hmac('sha256', http_build_query($params), 'test_secret_12345');
        $params['hmac'] = $validHmac;

        $this->assertTrue($service->verifyHmac($params));

        // Invalid HMAC
        $params['hmac'] = 'invalid_hmac_value';
        $this->assertFalse($service->verifyHmac($params));
    }
}
