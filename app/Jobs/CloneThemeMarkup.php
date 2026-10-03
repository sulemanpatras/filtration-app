<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\Shopify\ThemeMarkupCloner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clones the theme's real filter markup once and caches it. Dispatched after a catalog
 * sync finishes (so a donor collection's product/facet data actually exists) and whenever
 * `themes/publish` fires; the cloner itself skips the work if the theme hasn't changed.
 */
class CloneThemeMarkup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Shop $shop) {}

    public function uniqueId(): string
    {
        return (string) $this->shop->id;
    }

    public function handle(): void
    {
        if (! $this->shop->isInstalled()) {
            return;
        }

        try {
            (new ThemeMarkupCloner($this->shop))->clone();
        } catch (Throwable $e) {
            // Non-fatal: the storefront keeps using the styled generic UI (or the live
            // in-browser color detection) if no cloned markup is available.
            Log::warning('Theme markup cloning failed', ['shop' => $this->shop->domain, 'error' => $e->getMessage()]);
        }
    }
}
