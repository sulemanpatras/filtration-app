<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\Shopify\AdminApi;
use App\Services\Shopify\ProductQueries;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Kicks off a Shopify bulk operation that exports the whole product catalog.
 */
class StartCatalogSync implements ShouldBeUnique, ShouldQueue
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

        $data = (new AdminApi($this->shop))->graphql(
            'mutation Run($query: String!) {
              bulkOperationRunQuery(query: $query) {
                bulkOperation { id status }
                userErrors { field message }
              }
            }',
            ['query' => ProductQueries::bulk()],
        );

        $result = $data['bulkOperationRunQuery'];
        if (! empty($result['userErrors'])) {
            throw new RuntimeException('Bulk operation rejected: '.json_encode($result['userErrors']));
        }

        $this->shop->update([
            'sync_status' => 'running',
            'bulk_operation_id' => $result['bulkOperation']['id'],
            'sync_error' => null,
        ]);

        PollBulkOperation::dispatch($this->shop)->delay(now()->addSeconds(10));
    }

    public function failed(Throwable $e): void
    {
        $this->shop->update(['sync_status' => 'failed', 'sync_error' => $e->getMessage()]);
    }
}
