<?php

namespace App\Http\Middleware;

use App\Services\Shopify\ShopifyAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the admin page to be framed by Shopify admin only.
 */
class EmbeddedAppHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $shop = $request->query('shop');
        $ancestors = ShopifyAuth::isValidShopDomain($shop)
            ? "https://{$shop} https://admin.shopify.com"
            : 'https://admin.shopify.com';

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors};");

        return $response;
    }
}
