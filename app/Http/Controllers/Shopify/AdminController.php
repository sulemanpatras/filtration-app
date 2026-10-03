<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Jobs\StartCatalogSync;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminController extends Controller
{
    /** Embedded admin shell; data is loaded through the session-token protected API. */
    public function index(): View
    {
        return view('admin', ['apiKey' => config('shopify.api_key')]);
    }

    public function status(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $threshold = config('shopify.filter_threshold');

        $largeCollections = DB::table('collections')
            ->join('collection_product', 'collection_product.collection_id', '=', 'collections.id')
            ->join('products', 'products.id', '=', 'collection_product.product_id')
            ->where('collections.shop_id', $shop->id)
            ->where('products.published', true)
            ->groupBy('collections.id', 'collections.handle', 'collections.title')
            ->havingRaw('count(*) > ?', [$threshold])
            ->orderByDesc('products_count')
            ->limit(50)
            ->get(['collections.handle', 'collections.title', DB::raw('count(*) as products_count')]);

        // /collections/all is a virtual collection containing every published product.
        $published = $shop->products()->where('published', true)->count();
        if ($published > $threshold) {
            $largeCollections->prepend((object) ['handle' => 'all', 'title' => 'All products', 'products_count' => $published]);
        }

        return response()->json([
            'shop' => $shop->domain,
            'sync_status' => $shop->sync_status,
            'sync_error' => $shop->sync_error,
            'synced_at' => $shop->synced_at?->toIso8601String(),
            'products' => $shop->products()->count(),
            'published_products' => $published,
            'collections' => $shop->collections()->count(),
            'threshold' => $threshold,
            'large_collections' => $largeCollections,
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        if ($shop->sync_status === 'running') {
            return response()->json(['message' => 'A sync is already running.'], 409);
        }

        StartCatalogSync::dispatch($shop);
        $shop->update(['sync_status' => 'running', 'sync_error' => null]);

        return response()->json(['message' => 'Sync started.']);
    }
}
