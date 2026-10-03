<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates storefront requests forwarded by Shopify's App Proxy (/apps/big-filters/*).
 */
class VerifyAppProxy
{
    public function __construct(private readonly ShopifyAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->auth->verifyProxySignature($request->query())) {
            Log::warning('App proxy: invalid signature', ['query' => $request->query()]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $shop = Shop::where('domain', $request->query('shop'))->first();
        if (! $shop?->isInstalled()) {
            Log::warning('App proxy: shop not installed', ['shop' => $request->query('shop')]);

            return response()->json(['error' => 'Shop not installed'], 404);
        }

        Log::info('App proxy request', ['shop' => $shop->domain, 'collection' => $request->query('collection')]);

        $request->attributes->set('shop', $shop);

        return $next($request);
    }
}
