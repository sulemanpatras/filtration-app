<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Shopify requests authenticate with session tokens, HMACs and proxy signatures instead of CSRF cookies.
        $middleware->validateCsrfTokens(except: ['api/*', 'webhooks', 'proxy/*']);
        // Tunnels (Cloudflare via `shopify app dev`) terminate TLS in front of the app.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'proxy/*', 'webhooks') || $request->expectsJson(),
        );
    })->create();
