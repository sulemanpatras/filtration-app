<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Variant;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $apiVersion;
    protected string $appUrl;
    protected string $scopes;

    public function __construct()
    {
        $this->clientId = config('shopify.client_id', '');
        $this->clientSecret = config('shopify.client_secret', '');
        $this->apiVersion = config('shopify.api_version', '2024-04');
        $this->appUrl = rtrim(config('shopify.app_url', 'https://subscribe-blinks-doorpost.ngrok-free.dev'), '/');
        $this->scopes = config('shopify.scopes', 'read_products,read_product_listings,read_collections');
    }

    /**
     * Sanitize shop domain.
     */
    public function sanitizeShopDomain(string $shop): string
    {
        $shop = trim($shop);
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = rtrim($shop, '/');

        if (!str_contains($shop, '.myshopify.com')) {
            $shop .= '.myshopify.com';
        }

        return strtolower($shop);
    }

    /**
     * Generate OAuth authorization URL.
     */
    public function buildAuthUrl(string $shopDomain, string $state): string
    {
        $shopDomain = $this->sanitizeShopDomain($shopDomain);
        $redirectUri = urlencode($this->appUrl . '/auth/shopify/callback');

        return "https://{$shopDomain}/admin/oauth/authorize?client_id={$this->clientId}&scope={$this->scopes}&redirect_uri={$redirectUri}&state={$state}";
    }

    /**
     * Verify HMAC from Shopify request.
     */
    public function verifyHmac(array $queryParams): bool
    {
        if (empty($queryParams['hmac']) || empty($this->clientSecret)) {
            return false;
        }

        $hmac = $queryParams['hmac'];
        unset($queryParams['hmac'], $queryParams['signature']);
        ksort($queryParams);

        $computedHmac = hash_hmac('sha256', http_build_query($queryParams), $this->clientSecret);

        return hash_equals($hmac, $computedHmac);
    }

    /**
     * Exchange authorization code for permanent access token.
     */
    public function exchangeAccessToken(string $shopDomain, string $code): array
    {
        $shopDomain = $this->sanitizeShopDomain($shopDomain);

        $response = Http::asJson()->post("https://{$shopDomain}/admin/oauth/access_token", [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
        ]);

        if (!$response->successful()) {
            throw new Exception("Shopify token exchange failed: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Perform GraphQL query against Shopify Admin API.
     */
    public function graphql(Shop $shop, string $query, array $variables = []): array
    {
        $url = "https://{$shop->shop_domain}/admin/api/{$this->apiVersion}/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if (!$response->successful()) {
            throw new Exception("GraphQL request failed: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Perform REST request against Shopify Admin API.
     */
    public function rest(Shop $shop, string $endpoint, string $method = 'GET', array $params = []): array
    {
        $endpoint = ltrim($endpoint, '/');
        $url = "https://{$shop->shop_domain}/admin/api/{$this->apiVersion}/{$endpoint}";

        $request = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type' => 'application/json',
        ]);

        $response = match (strtoupper($method)) {
            'POST' => $request->post($url, $params),
            'PUT' => $request->put($url, $params),
            'DELETE' => $request->delete($url, $params),
            default => $request->get($url, $params),
        };

        if (!$response->successful()) {
            throw new Exception("REST API request to {$endpoint} failed: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Fetch store details and currency.
     */
    public function syncShopDetails(Shop $shop): void
    {
        if (empty($shop->access_token)) {
            return;
        }

        try {
            $data = $this->rest($shop, 'shop.json');
            if (!empty($data['shop'])) {
                $shopData = $data['shop'];
                $shop->update([
                    'name' => $shopData['name'] ?? $shop->name,
                    'currency' => $shopData['currency'] ?? 'USD',
                    'currency_symbol' => $shopData['money_format'] ?? '$',
                ]);
            }
        } catch (Exception $e) {
            Log::warning("Could not sync shop details for {$shop->shop_domain}: " . $e->getMessage());
        }
    }

    /**
     * Sync all collections for a shop.
     */
    public function syncCollections(Shop $shop): int
    {
        $count = 0;
        $endpoints = ['custom_collections.json', 'smart_collections.json'];

        foreach ($endpoints as $endpoint) {
            try {
                $data = $this->rest($shop, $endpoint . '?limit=250');
                $key = str_replace('.json', '', $endpoint);
                $items = $data[$key] ?? [];

                foreach ($items as $item) {
                    Collection::updateOrCreate(
                        [
                            'shop_id' => $shop->id,
                            'shopify_id' => (string)$item['id'],
                        ],
                        [
                            'title' => $item['title'],
                            'handle' => $item['handle'],
                            'products_count' => $item['products_count'] ?? 0,
                        ]
                    );
                    $count++;
                }
            } catch (Exception $e) {
                Log::warning("Error syncing {$endpoint} for {$shop->shop_domain}: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Sync products and variants from Shopify Admin REST API.
     */
    public function syncProducts(Shop $shop, int $limit = 250): int
    {
        $totalSynced = 0;
        $sinceId = 0;

        do {
            $params = [
                'limit' => $limit,
                'since_id' => $sinceId,
                'fields' => 'id,title,handle,vendor,product_type,tags,variants,images,published_at,status',
            ];

            $response = $this->rest($shop, 'products.json', 'GET', $params);
            $products = $response['products'] ?? [];

            if (empty($products)) {
                break;
            }

            foreach ($products as $item) {
                $this->saveProductPayload($shop, $item);
                $sinceId = max($sinceId, (int)$item['id']);
                $totalSynced++;
            }

            // Sleep slightly to respect Shopify leaky bucket rate limit (2 req/s)
            usleep(250000);
        } while (count($products) >= $limit);

        $shop->update(['last_synced_at' => now()]);

        return $totalSynced;
    }

    /**
     * Save/Update a single product payload from Shopify (API or Webhook).
     */
    public function saveProductPayload(Shop $shop, array $item): Product
    {
        $tags = [];
        if (!empty($item['tags'])) {
            if (is_array($item['tags'])) {
                $tags = $item['tags'];
            } else {
                $tags = array_values(array_filter(array_map('trim', explode(',', $item['tags']))));
            }
        }

        $featuredImage = null;
        if (!empty($item['images'][0]['src'])) {
            $featuredImage = $item['images'][0]['src'];
        } elseif (!empty($item['image']['src'])) {
            $featuredImage = $item['image']['src'];
        }

        $variants = $item['variants'] ?? [];
        $prices = array_column($variants, 'price');
        $minPrice = !empty($prices) ? min($prices) : 0.00;
        $maxPrice = !empty($prices) ? max($prices) : 0.00;

        $comparePrices = array_filter(array_column($variants, 'compare_at_price'));
        $compareAtPrice = !empty($comparePrices) ? min($comparePrices) : null;

        $isAvailable = false;
        foreach ($variants as $variant) {
            if (($variant['inventory_quantity'] ?? 1) > 0 || ($variant['inventory_policy'] ?? '') === 'continue') {
                $isAvailable = true;
                break;
            }
        }

        $product = Product::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_id' => (string)$item['id'],
            ],
            [
                'title' => $item['title'] ?? 'Untitled',
                'handle' => $item['handle'] ?? ('product-' . $item['id']),
                'vendor' => $item['vendor'] ?? '',
                'product_type' => $item['product_type'] ?? '',
                'tags' => $tags,
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
                'compare_at_price' => $compareAtPrice,
                'featured_image' => $featuredImage,
                'is_available' => $isAvailable,
                'status' => $item['status'] ?? 'active',
                'published_at' => !empty($item['published_at']) ? date('Y-m-d H:i:s', strtotime($item['published_at'])) : null,
            ]
        );

        // Save variants
        foreach ($variants as $v) {
            Variant::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'shopify_id' => (string)$v['id'],
                ],
                [
                    'title' => $v['title'] ?? 'Default Title',
                    'price' => $v['price'] ?? 0.00,
                    'compare_at_price' => $v['compare_at_price'] ?? null,
                    'sku' => $v['sku'] ?? null,
                    'option1' => $v['option1'] ?? null,
                    'option2' => $v['option2'] ?? null,
                    'option3' => $v['option3'] ?? null,
                    'inventory_quantity' => $v['inventory_quantity'] ?? 0,
                    'is_available' => ($v['inventory_quantity'] ?? 1) > 0 || ($v['inventory_policy'] ?? '') === 'continue',
                ]
            );
        }

        return $product;
    }
}
