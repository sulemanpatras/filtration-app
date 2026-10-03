<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Shop;
use App\Services\Shopify\AdminApi;
use App\Services\Shopify\CatalogImporter;
use App\Services\Shopify\ProductQueries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-fetches a single product after a products/create or products/update webhook.
 * Fetching via GraphQL (instead of trusting the REST webhook payload) keeps the
 * data shape identical to the bulk import and includes smart-collection membership.
 */
class SyncProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public Shop $shop, public int $productId) {}

    public function handle(): void
    {
        if (! $this->shop->isInstalled()) {
            return;
        }

        $data = (new AdminApi($this->shop))->graphql(ProductQueries::single(), [
            'id' => "gid://shopify/Product/{$this->productId}",
        ]);
        $node = $data['product'] ?? null;

        if (! $node) {
            Product::where('shop_id', $this->shop->id)->where('shopify_id', $this->productId)->delete();

            return;
        }

        (new CatalogImporter($this->shop))->upsert(
            $node,
            $node['variants']['nodes'] ?? [],
            $node['collections']['nodes'] ?? [],
        );
    }
}
