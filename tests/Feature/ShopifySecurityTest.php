<?php

namespace Tests\Feature;

use App\Jobs\StartCatalogSync;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Shopify\ShopifyAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopifySecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function sessionToken(array $overrides = [], string $secret = 'test-secret'): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode($overrides + [
            'iss' => 'https://demo.myshopify.com/admin',
            'dest' => 'https://demo.myshopify.com',
            'aud' => 'test-key',
            'sub' => '1',
            'exp' => time() + 60,
            'nbf' => time() - 5,
            'iat' => time() - 5,
        ]));

        return "$header.$payload.".$b64(hash_hmac('sha256', "$header.$payload", $secret, true));
    }

    public function test_session_token_is_validated(): void
    {
        $auth = app(ShopifyAuth::class);

        $this->assertSame('demo.myshopify.com', $auth->decodeSessionToken(self::sessionToken())['shop']);

        foreach ([
            self::sessionToken([], 'wrong-secret'),
            self::sessionToken(['exp' => time() - 120]),
            self::sessionToken(['aud' => 'other-app']),
            self::sessionToken(['dest' => 'https://evil.example.com']),
        ] as $token) {
            try {
                $auth->decodeSessionToken($token);
                $this->fail('Invalid token was accepted.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_api_exchanges_token_on_first_request(): void
    {
        Http::fake([
            'demo.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_123', 'scope' => 'read_products']),
        ]);
        Queue::fake();

        $this->getJson('/api/status')->assertUnauthorized();

        $this->getJson('/api/status', ['Authorization' => 'Bearer '.self::sessionToken()])
            ->assertOk()
            ->assertJsonPath('shop', 'demo.myshopify.com');

        $shop = Shop::where('domain', 'demo.myshopify.com')->firstOrFail();
        $this->assertSame('shpat_123', $shop->access_token);
        Queue::assertPushed(StartCatalogSync::class);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth/access_token')
            && $request['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token');
    }

    public function test_webhook_requires_valid_hmac(): void
    {
        $shop = Shop::create(['domain' => 'demo.myshopify.com', 'access_token' => 'x']);
        Product::create(['shop_id' => $shop->id, 'shopify_id' => 42, 'handle' => 'a', 'title' => 'A']);
        $body = json_encode(['id' => 42]);
        $headers = [
            'X-Shopify-Topic' => 'products/delete',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
            'Content-Type' => 'application/json',
        ];

        $this->call('POST', '/webhooks', [], [], [], $this->serverHeaders($headers + ['X-Shopify-Hmac-Sha256' => 'bad']), $body)
            ->assertUnauthorized();
        $this->assertSame(1, Product::count());

        $hmac = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));
        $this->call('POST', '/webhooks', [], [], [], $this->serverHeaders($headers + ['X-Shopify-Hmac-Sha256' => $hmac]), $body)
            ->assertNoContent();
        $this->assertSame(0, Product::count());
    }

    public function test_uninstall_webhook_clears_shop(): void
    {
        $shop = Shop::create(['domain' => 'demo.myshopify.com', 'access_token' => 'x']);
        Product::create(['shop_id' => $shop->id, 'shopify_id' => 42, 'handle' => 'a', 'title' => 'A']);
        $body = json_encode(['id' => 1]);

        $this->call('POST', '/webhooks', [], [], [], $this->serverHeaders([
            'X-Shopify-Topic' => 'app/uninstalled',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'test-secret', true)),
            'Content-Type' => 'application/json',
        ]), $body)->assertNoContent();

        $this->assertFalse($shop->fresh()->isInstalled());
        $this->assertSame(0, Product::count());
    }

    public function test_proxy_signature(): void
    {
        $auth = app(ShopifyAuth::class);
        $query = ['shop' => 'demo.myshopify.com', 'path_prefix' => '/apps/big-filters', 'timestamp' => '1700000000', 'f' => '{"vendor":["A,B"]}'];

        $this->assertTrue($auth->verifyProxySignature(ProxyTest::sign($query)));
        $this->assertFalse($auth->verifyProxySignature(['signature' => 'nope'] + $query));
        $this->assertFalse($auth->verifyProxySignature(['shop' => 'other.myshopify.com'] + ProxyTest::sign($query)));
    }

    /** @param array<string, string> $headers */
    private function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : "HTTP_$key"] = $value;
        }

        return $server;
    }
}
