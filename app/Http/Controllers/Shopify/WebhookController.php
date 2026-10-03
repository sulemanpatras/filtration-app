<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeThemeStyle;
use App\Jobs\CloneThemeMarkup;
use App\Jobs\SyncProduct;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $topic = (string) $request->header('X-Shopify-Topic');
        $shop = Shop::where('domain', $request->header('X-Shopify-Shop-Domain'))->first();
        $payload = $request->json()->all();

        // Compliance webhooks: no customer data is stored, so only shop/redact needs work.
        if (! $shop || in_array($topic, ['customers/data_request', 'customers/redact'], true)) {
            return response()->noContent();
        }

        match ($topic) {
            'products/create', 'products/update' => SyncProduct::dispatch($shop, (int) $payload['id']),
            'products/delete' => $shop->products()->where('shopify_id', (int) $payload['id'])->delete(),
            'collections/update' => $shop->collections()
                ->where('shopify_id', (int) $payload['id'])
                ->update(['handle' => $payload['handle'], 'title' => mb_substr($payload['title'], 0, 255)]),
            'collections/delete' => $shop->collections()->where('shopify_id', (int) $payload['id'])->delete(),
            // Re-analyze whenever the merchant switches or updates their published theme.
            'themes/publish' => [AnalyzeThemeStyle::dispatch($shop), CloneThemeMarkup::dispatch($shop)],
            'app/uninstalled' => $this->uninstall($shop),
            'shop/redact' => $shop->delete(),
            default => null,
        };

        return response()->noContent();
    }

    private function uninstall(Shop $shop): void
    {
        $shop->products()->delete();
        $shop->collections()->delete();
        $shop->update([
            'access_token' => null,
            'uninstalled_at' => now(),
            'sync_status' => 'idle',
            'bulk_operation_id' => null,
            'synced_at' => null,
        ]);
    }
}
