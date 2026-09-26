<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\ShopifyAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
Route::post('/settings', [AdminDashboardController::class, 'updateSettings'])->name('dashboard.settings');
Route::post('/sync', [AdminDashboardController::class, 'triggerSync'])->name('dashboard.sync');

// Shopify OAuth Routes
Route::prefix('auth/shopify')->group(function () {
    Route::get('/', [ShopifyAuthController::class, 'install'])->name('shopify.install');
    Route::get('/callback', [ShopifyAuthController::class, 'callback'])->name('shopify.callback');
});
