<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\ProductFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Storefront filter API, reached through the App Proxy at /apps/big-filters/products.
 */
class ProxyController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $handle = (string) $request->query('collection', 'all');
        if (! preg_match('/^[a-z0-9\-_]{1,255}$/i', $handle)) {
            return response()->json(['error' => 'Invalid collection'], 422);
        }

        $filter = new ProductFilter($shop, $handle, ProductFilter::parseFilters($request->query('f')));

        $results = $filter->results(
            (string) $request->query('sort', 'created-descending'),
            max(1, (int) $request->query('page', 1)),
            min(48, max(1, (int) $request->query('per_page', 24))),
        );

        return response()->json($results)
            ->header('Cache-Control', 'public, max-age=30');
    }

    /**
     * Cached design tokens extracted from the shop's active theme CSS (see
     * ThemeStyleAnalyzer). A plain cache lookup — the analysis itself runs separately,
     * on install and on the `themes/publish` webhook, never on this request path.
     */
    public function themeStyle(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        return response()->json(['tokens' => $shop->themeStyle?->tokens ?? (object) []])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Cached, cloned filter-sidebar markup (see ThemeMarkupCloner) — a plain cache
     * lookup, same as themeStyle() above.
     */
    public function themeMarkup(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        return response()->json(['templates' => $shop->themeMarkup?->templates ?? (object) []])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
