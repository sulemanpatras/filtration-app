<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyAuth
{
    private const LEEWAY_SECONDS = 10;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {}

    public static function isValidShopDomain(?string $shop): bool
    {
        return is_string($shop) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop) === 1;
    }

    /**
     * Validate an App Bridge session token (HS256 JWT) and return its claims.
     *
     * @return array<string, mixed>
     */
    public function decodeSessionToken(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed session token.');
        }

        [$header64, $payload64, $signature64] = $parts;
        $header = json_decode($this->base64UrlDecode($header64), true);
        $claims = json_decode($this->base64UrlDecode($payload64), true);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ! is_array($claims)) {
            throw new RuntimeException('Unsupported session token.');
        }

        $expected = hash_hmac('sha256', "$header64.$payload64", $this->apiSecret, true);
        if (! hash_equals($expected, $this->base64UrlDecode($signature64))) {
            throw new RuntimeException('Invalid session token signature.');
        }

        $now = time();
        if (($claims['exp'] ?? 0) < $now - self::LEEWAY_SECONDS || ($claims['nbf'] ?? 0) > $now + self::LEEWAY_SECONDS) {
            throw new RuntimeException('Session token expired.');
        }

        if (($claims['aud'] ?? null) !== $this->apiKey) {
            throw new RuntimeException('Session token audience mismatch.');
        }

        $shop = parse_url((string) ($claims['dest'] ?? ''), PHP_URL_HOST);
        $issuerShop = parse_url((string) ($claims['iss'] ?? ''), PHP_URL_HOST);
        if (! self::isValidShopDomain($shop) || $shop !== $issuerShop) {
            throw new RuntimeException('Session token shop mismatch.');
        }

        $claims['shop'] = $shop;

        return $claims;
    }

    public function verifyWebhook(string $rawBody, ?string $hmacHeader): bool
    {
        if (! $hmacHeader) {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $rawBody, $this->apiSecret, true));

        return hash_equals($calculated, $hmacHeader);
    }

    /**
     * Verify the `signature` Shopify appends to App Proxy requests.
     *
     * @param  array<string, string|array<int, string>>  $query
     */
    public function verifyProxySignature(array $query): bool
    {
        $signature = $query['signature'] ?? null;
        if (! is_string($signature) || $signature === '') {
            return false;
        }
        unset($query['signature']);

        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = $key.'='.(is_array($value) ? implode(',', $value) : $value);
        }
        sort($pairs);

        $calculated = hash_hmac('sha256', implode('', $pairs), $this->apiSecret);

        return hash_equals($calculated, $signature);
    }

    /**
     * Exchange an App Bridge id_token for an offline Admin API access token.
     *
     * @return array{access_token: string, scope: string}
     */
    public function exchangeForOfflineToken(string $shop, string $idToken): array
    {
        $response = Http::asJson()->acceptJson()->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $this->apiKey,
            'client_secret' => $this->apiSecret,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $idToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Token exchange failed: '.$response->body());
        }

        return [
            'access_token' => $response->json('access_token'),
            'scope' => (string) $response->json('scope'),
        ];
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
