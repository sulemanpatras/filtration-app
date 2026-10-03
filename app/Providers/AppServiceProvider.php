<?php

namespace App\Providers;

use App\Services\Shopify\ShopifyAuth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShopifyAuth::class, fn () => new ShopifyAuth(
            (string) config('shopify.api_key'),
            (string) config('shopify.api_secret'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
