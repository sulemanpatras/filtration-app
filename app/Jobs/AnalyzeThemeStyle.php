<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\Shopify\ThemeStyleAnalyzer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyzes the shop's active theme CSS and caches the result. Dispatched on install and
 * whenever the `themes/publish` webhook fires; the analyzer itself skips the work if the
 * published theme hasn't actually changed since the last analysis.
 */
class AnalyzeThemeStyle implements ShouldBeUnique, ShouldQueue
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
            (new ThemeStyleAnalyzer($this->shop))->analyze();
        } catch (Throwable $e) {
            // Non-fatal: the storefront falls back to live in-browser detection, or the
            // merchant's manual accent color, if no cached tokens are available.
            Log::warning('Theme style analysis failed', ['shop' => $this->shop->domain, 'error' => $e->getMessage()]);
        }
    }
}
