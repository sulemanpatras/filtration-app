<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\ShopifyService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyAuthController extends Controller
{
    protected ShopifyService $shopify;

    public function __construct(ShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    /**
     * Start OAuth flow.
     */
    public function install(Request $request)
    {
        $shopDomain = $request->query('shop');

        if (empty($shopDomain)) {
            return redirect()->route('dashboard')->with('error', 'Shop domain is required to start installation.');
        }

        $shopDomain = $this->shopify->sanitizeShopDomain($shopDomain);
        $state = Str::random(32);
        session(['shopify_oauth_state' => $state, 'shopify_shop_domain' => $shopDomain]);

        $authUrl = $this->shopify->buildAuthUrl($shopDomain, $state);

        return redirect()->away($authUrl);
    }

    /**
     * OAuth callback from Shopify.
     */
    public function callback(Request $request)
    {
        $params = $request->all();

        if (!$this->shopify->verifyHmac($params)) {
            return redirect()->route('dashboard')->with('error', 'HMAC validation failed. Request may have been tampered with.');
        }

        $shopDomain = $this->shopify->sanitizeShopDomain($request->query('shop', ''));
        $code = $request->query('code');

        if (empty($shopDomain) || empty($code)) {
            return redirect()->route('dashboard')->with('error', 'Missing code or shop parameter.');
        }

        try {
            $tokenData = $this->shopify->exchangeAccessToken($shopDomain, $code);
            $accessToken = $tokenData['access_token'] ?? null;
            $scope = $tokenData['scope'] ?? '';

            if (!$accessToken) {
                throw new Exception('No access token received from Shopify.');
            }

            $shop = Shop::updateOrCreate(
                ['shop_domain' => $shopDomain],
                [
                    'access_token' => $accessToken,
                    'scope' => $scope,
                    'is_active' => true,
                ]
            );

            // Fetch shop details and default filter setting
            $this->shopify->syncShopDetails($shop);
            $shop->getOrCreateFilterSetting();

            // Initial collection sync
            $this->shopify->syncCollections($shop);

            session(['active_shop' => $shop->shop_domain]);

            return redirect()->route('dashboard', ['shop' => $shop->shop_domain])
                ->with('success', "Successfully connected to {$shopDomain}!");
        } catch (Exception $e) {
            Log::error("Shopify OAuth Callback Error: " . $e->getMessage());
            return redirect()->route('dashboard')->with('error', 'OAuth Error: ' . $e->getMessage());
        }
    }

    /**
     * Webhook receiver for real-time catalog updates.
     */
    public function webhook(Request $request)
    {
        $hmac = $request->header('X-Shopify-Hmac-Sha256');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $topic = $request->header('X-Shopify-Topic');
        $data = $request->getContent();

        $secret = config('shopify.webhook_secret') ?: config('shopify.client_secret');

        if ($secret && $hmac) {
            $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $secret, true));
            if (!hash_equals($hmac, $calculatedHmac)) {
                Log::warning("Invalid webhook HMAC for {$shopDomain} on topic {$topic}");
                return response('Unauthorized', 401);
            }
        }

        $payload = json_decode($data, true);
        if (!$payload) {
            return response('Bad Request', 400);
        }

        $shop = Shop::where('shop_domain', $shopDomain)->first();
        if (!$shop) {
            return response('Shop not found', 200);
        }

        switch ($topic) {
            case 'products/create':
            case 'products/update':
                $this->shopify->saveProductPayload($shop, $payload);
                break;

            case 'products/delete':
                $shopifyId = (string)($payload['id'] ?? '');
                $shop->products()->where('shopify_id', $shopifyId)->delete();
                break;

            case 'app/uninstalled':
                $shop->update(['is_active' => false, 'access_token' => null]);
                break;
        }

        return response('Webhook Handled', 200);
    }
}
