<?php

namespace App\Http\Middleware;

use App\Jobs\AnalyzeThemeStyle;
use App\Jobs\StartCatalogSync;
use App\Models\Shop;
use App\Services\Shopify\ShopifyAuth;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from the embedded admin UI with the App Bridge session token.
 *
 * With Shopify managed installation there is no OAuth redirect: the first authenticated
 * request exchanges the session token for an offline access token and starts a sync.
 */
class VerifySessionToken
{
    public function __construct(private readonly ShopifyAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        try {
            $claims = $this->auth->decodeSessionToken((string) $token);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 401, [
                'X-Shopify-Retry-Invalid-Session-Request' => '1',
            ]);
        }

        $shop = Shop::firstOrNew(['domain' => $claims['shop']]);

        if (! $shop->isInstalled()) {
            $grant = $this->auth->exchangeForOfflineToken($shop->domain, $token);
            $shop->fill([
                'access_token' => $grant['access_token'],
                'scopes' => $grant['scope'],
                'uninstalled_at' => null,
            ])->save();

            StartCatalogSync::dispatch($shop);
            AnalyzeThemeStyle::dispatch($shop);
        }

        $request->attributes->set('shop', $shop);

        return $next($request);
    }
}
