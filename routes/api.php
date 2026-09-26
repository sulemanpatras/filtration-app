<?php

use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\StorefrontApiController;
use Illuminate\Support\Facades\Route;

// Public Storefront Endpoints for Shopify Theme
Route::prefix('storefront')->group(function () {
    Route::get('/facets', [StorefrontApiController::class, 'getFacets']);
    Route::get('/products', [StorefrontApiController::class, 'getProducts']);
    Route::get('/config', [StorefrontApiController::class, 'getConfig']);
});

// Shopify Webhook endpoint
Route::post('/webhooks/shopify', [ShopifyAuthController::class, 'webhook']);
