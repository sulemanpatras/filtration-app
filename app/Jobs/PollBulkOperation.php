<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\Shopify\AdminApi;
use App\Services\Shopify\CatalogImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Waits for the bulk operation to finish, then streams the JSONL result into the database.
 */
class PollBulkOperation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 3;

    public function __construct(public Shop $shop, public int $polls = 0) {}

    public function handle(): void
    {
        $shop = $this->shop->fresh();
        if (! $shop?->isInstalled() || ! $shop->bulk_operation_id) {
            return;
        }

        $data = (new AdminApi($shop))->graphql(
            'query Op($id: ID!) { node(id: $id) { ... on BulkOperation { status errorCode url partialDataUrl objectCount } } }',
            ['id' => $shop->bulk_operation_id],
        );
        $op = $data['node'] ?? null;

        if (! $op) {
            throw new RuntimeException('Bulk operation not found.');
        }

        if (in_array($op['status'], ['CREATED', 'RUNNING'], true)) {
            if ($this->polls >= 720) { // ~3 hours
                throw new RuntimeException('Bulk operation timed out.');
            }
            self::dispatch($shop, $this->polls + 1)->delay(now()->addSeconds(15));

            return;
        }

        if ($op['status'] !== 'COMPLETED') {
            throw new RuntimeException("Bulk operation {$op['status']}: ".($op['errorCode'] ?? 'unknown error'));
        }

        $importer = new CatalogImporter($shop);
        $seen = [];

        // A null url means the shop has no products.
        if ($op['url']) {
            $path = tempnam(sys_get_temp_dir(), 'bulk');
            try {
                Http::timeout(600)->sink($path)->get($op['url'])->throw();
                $seen = $importer->importJsonl($path);
            } finally {
                @unlink($path);
            }
        }

        $importer->deleteProductsExcept($seen);

        $shop->update([
            'sync_status' => 'completed',
            'bulk_operation_id' => null,
            'synced_at' => now(),
            'sync_error' => null,
        ]);

        // Now that collection/product data exists, we can pick a real donor collection
        // to clone the theme's filter markup from.
        CloneThemeMarkup::dispatch($shop);
    }

    public function failed(Throwable $e): void
    {
        $this->shop->update(['sync_status' => 'failed', 'sync_error' => $e->getMessage()]);
    }
}
