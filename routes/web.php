<?php

use App\Http\Controllers\Shopify\AdminController;
use App\Http\Controllers\Shopify\ProxyController;
use App\Http\Controllers\Shopify\WebhookController;
use App\Http\Middleware\EmbeddedAppHeaders;
use App\Http\Middleware\VerifyAppProxy;
use App\Http\Middleware\VerifySessionToken;
use App\Http\Middleware\VerifyWebhook;
use Illuminate\Support\Facades\Route;

// Embedded admin (loaded inside Shopify admin).
Route::get('/', [AdminController::class, 'index'])->middleware(EmbeddedAppHeaders::class);

Route::prefix('api')->middleware(VerifySessionToken::class)->group(function () {
    Route::get('status', [AdminController::class, 'status']);
    Route::post('sync', [AdminController::class, 'sync']);
});

// Webhooks declared in shopify.app.toml.
Route::post('webhooks', WebhookController::class)->middleware(VerifyWebhook::class);

// Storefront App Proxy: https://{shop}/apps/big-filters/* -> /proxy/*
Route::get('proxy/products', [ProxyController::class, 'products'])->middleware(VerifyAppProxy::class);
Route::get('proxy/theme-style', [ProxyController::class, 'themeStyle'])->middleware(VerifyAppProxy::class);
Route::get('proxy/theme-markup', [ProxyController::class, 'themeMarkup'])->middleware(VerifyAppProxy::class);
