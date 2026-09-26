<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\FilterEngineService;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontApiController extends Controller
{
    protected FilterEngineService $filterEngine;
    protected ShopifyService $shopify;

    public function __construct(FilterEngineService $filterEngine, ShopifyService $shopify)
    {
        $this->filterEngine = $filterEngine;
        $this->shopify = $shopify;
    }

    /**
     * Get dynamic facets (price, vendors, types, tags, availability) for collection.
     */
    public function getFacets(Request $request): JsonResponse
    {
        $shop = $this->resolveShop($request);
        if (!$shop) {
            return $this->corsResponse(['error' => 'Store not found or inactive'], 404);
        }

        $collectionHandle = $request->query('collection_handle');
        $appliedFilters = $request->query('filters', []);

        $facets = $this->filterEngine->getFacets($shop, $collectionHandle, $appliedFilters);

        return $this->corsResponse($facets);
    }

    /**
     * Get filtered, sorted and paginated products.
     */
    public function getProducts(Request $request): JsonResponse
    {
        $shop = $this->resolveShop($request);
        if (!$shop) {
            return $this->corsResponse(['error' => 'Store not found or inactive'], 404);
        }

        $collectionHandle = $request->query('collection_handle');
        $sortBy = $request->query('sort_by', 'featured');
        $page = max(1, (int)$request->query('page', 1));
        
        $settings = $shop->getOrCreateFilterSetting();
        $perPage = max(1, (int)$request->query('limit', $settings->per_page ?: 24));

        $filters = [
            'price_min' => $request->query('price_min'),
            'price_max' => $request->query('price_max'),
            'vendors' => (array)$request->query('vendors', []),
            'types' => (array)$request->query('types', []),
            'tags' => (array)$request->query('tags', []),
            'availability' => $request->query('availability'),
        ];

        // Clean up empty filter values
        $filters = array_filter($filters, fn($val) => !is_null($val) && $val !== '' && (!is_array($val) || !empty($val)));

        $result = $this->filterEngine->getFilteredProducts(
            $shop,
            $collectionHandle,
            $filters,
            $sortBy,
            $page,
            $perPage
        );

        return $this->corsResponse($result);
    }

    /**
     * Get storefront configuration and theme settings.
     */
    public function getConfig(Request $request): JsonResponse
    {
        $shop = $this->resolveShop($request);
        if (!$shop) {
            return $this->corsResponse(['error' => 'Store not found or inactive'], 404);
        }

        $settings = $shop->getOrCreateFilterSetting();

        return $this->corsResponse([
            'shop' => $shop->shop_domain,
            'currency_symbol' => $shop->currency_symbol ?: '$',
            'settings' => $settings,
        ]);
    }

    /**
     * Helper to resolve shop from request.
     */
    protected function resolveShop(Request $request): ?Shop
    {
        $shopDomain = $request->query('shop') ?: $request->header('X-Shop-Domain');

        if (!$shopDomain) {
            // Fallback to first active shop if available
            return Shop::where('is_active', true)->first();
        }

        $sanitized = $this->shopify->sanitizeShopDomain($shopDomain);

        return Shop::where('shop_domain', $sanitized)->first()
            ?: Shop::where('shop_domain', 'LIKE', "%{$shopDomain}%")->first();
    }

    /**
     * Return JSON response with CORS headers.
     */
    protected function corsResponse(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Shop-Domain, Authorization, X-Requested-With',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
