<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AdminApi
{
    public function __construct(private readonly Shop $shop) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        $version = config('shopify.api_version');

        $response = Http::withHeaders(['X-Shopify-Access-Token' => $this->shop->access_token])
            ->acceptJson()
            ->retry(3, 1000, throw: false)
            ->post("https://{$this->shop->domain}/admin/api/{$version}/graphql.json", [
                'query' => $query,
                'variables' => (object) $variables,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Shopify API HTTP {$response->status()}: {$response->body()}");
        }

        if ($errors = $response->json('errors')) {
            throw new RuntimeException('Shopify GraphQL error: '.json_encode($errors));
        }

        return $response->json('data') ?? [];
    }

    /** Active (published) theme's GraphQL ID, or null if it can't be determined. */
    public function activeThemeId(): ?string
    {
        $data = $this->graphql('query { themes(first: 1, roles: [MAIN]) { nodes { id } } }');

        return $data['themes']['nodes'][0]['id'] ?? null;
    }

    /**
     * Reads theme files (read-only) via the Admin API. Requires the `read_themes` scope.
     *
     * @param  string[]  $filenames
     * @return array<string, string> filename => text content (missing/binary files omitted)
     */
    public function themeFiles(string $themeId, array $filenames): array
    {
        $data = $this->graphql(
            'query Files($id: ID!, $filenames: [String!]!) {
              theme(id: $id) {
                files(filenames: $filenames) {
                  nodes {
                    filename
                    body { ... on OnlineStoreThemeFileBodyText { content } }
                  }
                }
              }
            }',
            ['id' => $themeId, 'filenames' => array_values($filenames)],
        );

        $out = [];
        foreach ($data['theme']['files']['nodes'] ?? [] as $node) {
            $out[$node['filename']] = $node['body']['content'] ?? '';
        }

        return $out;
    }
}
