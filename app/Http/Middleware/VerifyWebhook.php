<?php

namespace App\Http\Middleware;

use App\Services\Shopify\ShopifyAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhook
{
    public function __construct(private readonly ShopifyAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->auth->verifyWebhook($request->getContent(), $request->header('X-Shopify-Hmac-Sha256'))) {
            return response('Invalid HMAC', 401);
        }

        return $next($request);
    }
}
