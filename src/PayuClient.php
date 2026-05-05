<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the PayU REST API (`/api/v2_1/orders` + OAuth).
 *
 * Auth is `client_credentials` against `/pl/standard/user/oauth/authorize`.
 * The bearer token is cached (slightly under the TTL PayU returns) so we
 * don't hit the auth endpoint on every order. Re-fetched on 401.
 *
 * Note: PayU's `POST /orders` returns a 302 redirect by default. We disable
 * redirect-following so the call stays a single round-trip and we can read
 * `redirectUri` straight off the JSON body.
 */
class PayuClient
{
    private const PROD_BASE = 'https://secure.payu.com';

    private const SANDBOX_BASE = 'https://secure.snd.payu.com';

    private const TOKEN_CACHE_KEY = 'lunar-payu:oauth-token';

    public function __construct(
        private readonly bool $sandbox = true,
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
    ) {}

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE : self::PROD_BASE;
    }

    private function clientId(): string
    {
        return (string) ($this->clientId ?? config('lunar-payu.client_id'));
    }

    private function clientSecret(): string
    {
        return (string) ($this->clientSecret ?? config('lunar-payu.client_secret'));
    }

    /**
     * Exchange `client_credentials` for a bearer token. Cached under the
     * connection-distinct key so multiple PayU shops sharing one app don't
     * clobber each other's tokens (advanced — defaults to a single key).
     */
    public function accessToken(bool $forceRefresh = false): string
    {
        if (! $forceRefresh) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = Http::asForm()->post($this->baseUrl().'/pl/standard/user/oauth/authorize', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'PayU OAuth failed: HTTP %d — %s',
                $response->status(),
                (string) $response->body(),
            ));
        }

        $data = (array) $response->json();
        $token = (string) ($data['access_token'] ?? '');
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        if ($token === '') {
            throw new RuntimeException('PayU OAuth returned no access_token');
        }

        // Refresh slightly before expiry to avoid edge-of-TTL 401s.
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 60));

        return $token;
    }

    /**
     * Create a PayU order. Returns the decoded JSON response. On 401 the
     * token is refreshed once and the request is retried — covers the case
     * where a cached token has been revoked server-side.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload): array
    {
        $send = fn (string $token) => Http::withToken($token)
            ->withoutRedirecting()
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/api/v2_1/orders', $payload);

        $response = $send($this->accessToken());

        if ($response->status() === 401) {
            $response = $send($this->accessToken(forceRefresh: true));
        }

        if (! ($response->successful() || $response->status() === 302)) {
            throw new RuntimeException(sprintf(
                'PayU createOrder failed: HTTP %d — %s',
                $response->status(),
                (string) $response->body(),
            ));
        }

        $body = (array) $response->json();

        // PayU's success response carries either a top-level `redirectUri` +
        // `orderId`, or a 302 to `redirectUri` with the same JSON body. We
        // surface whatever shape we got back — caller (driver) reads keys.
        return $body;
    }
}
