<?php

namespace App\Http\Controllers;

use App\Models\FilterSetting;
use App\Models\Shop;
use App\Services\ShopifyService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    protected ShopifyService $shopify;

    public function __construct(ShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    /**
     * App entry point. There is no admin UI page for this app anymore —
     * product listings are rendered entirely by the Shopify theme itself
     * (see public/storefront/shopify-filtration.js), using the real
     * catalog synced from the connected store. This endpoint exists only
     * so the app has a valid root route after OAuth install/callback.
     */
    public function index(Request $request): View
    {
        $shopDomain = $request->query('shop') ?: session('active_shop');

        $shop = null;
        if ($shopDomain) {
            $shopDomain = $this->shopify->sanitizeShopDomain($shopDomain);
            $shop = Shop::where('shop_domain', $shopDomain)->first();
        }
        if (!$shop) {
            $shop = Shop::first();
        }

        return view('admin.dashboard', [
            'shop' => $shop,
            'settings' => $shop?->getOrCreateFilterSetting(),
            'collections' => $shop ? $shop->collections()->orderBy('title')->get() : collect(),
        ]);
    }

    /**
     * Save filter display settings (used by the Shopify theme editor / API
     * callers now that there is no dashboard form for this).
     */
    public function updateSettings(Request $request)
    {
        $shopId = $request->input('shop_id');
        $shop = Shop::findOrFail($shopId);

        $validated = $request->validate([
            'enable_price' => 'nullable|boolean',
            'enable_vendor' => 'nullable|boolean',
            'enable_type' => 'nullable|boolean',
            'enable_tags' => 'nullable|boolean',
            'enable_availability' => 'nullable|boolean',
            'per_page' => 'required|integer|min:8|max:100',
            'theme_accent_color' => 'required|string|max:20',
            'filter_layout' => 'required|in:sidebar,drawer',
            'show_product_count' => 'nullable|boolean',
            'auto_mount_on_large_collections_only' => 'nullable|boolean',
        ]);

        $settings = $shop->getOrCreateFilterSetting();
        $settings->update([
            'enable_price' => $request->boolean('enable_price'),
            'enable_vendor' => $request->boolean('enable_vendor'),
            'enable_type' => $request->boolean('enable_type'),
            'enable_tags' => $request->boolean('enable_tags'),
            'enable_availability' => $request->boolean('enable_availability'),
            'per_page' => (int)$validated['per_page'],
            'theme_accent_color' => $validated['theme_accent_color'],
            'filter_layout' => $validated['filter_layout'],
            'show_product_count' => $request->boolean('show_product_count'),
            'auto_mount_on_large_collections_only' => $request->boolean('auto_mount_on_large_collections_only'),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Trigger manual catalog sync from the real connected Shopify store.
     */
    public function triggerSync(Request $request)
    {
        $shopId = $request->input('shop_id');
        $shop = Shop::findOrFail($shopId);

        if (empty($shop->access_token)) {
            return response()->json(['status' => 'error', 'message' => 'Store is not connected with an active OAuth access token.'], 422);
        }

        try {
            $collectionsSynced = $this->shopify->syncCollections($shop);
            $productsSynced = $this->shopify->syncProducts($shop, 250);

            return response()->json([
                'status' => 'ok',
                'message' => "Synced {$productsSynced} products and {$collectionsSynced} collections.",
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Sync failed: ' . $e->getMessage()], 500);
        }
    }
}
