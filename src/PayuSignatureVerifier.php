<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu;

/**
 * Verifies PayU webhook authenticity.
 *
 * PayU signs notifications with HMAC-SHA256 over the raw request body using
 * the merchant's "Second key" (also called "MD5 key" in older docs — same
 * secret, just a renamed field in the panel).
 *
 * The signature arrives in the `OpenPayU-Signature` header, formatted as a
 * semicolon-separated list:
 *
 *   OpenPayU-Signature: signature=<hex>;algorithm=SHA-256;sender=...
 *
 * We compare in constant time (`hash_equals`) and reject anything we can't
 * parse — better to fail closed than pass through ambiguous input.
 *
 * Older PayU shops still send the legacy MD5 algorithm. This verifier
 * supports both `SHA-256` (preferred) and `MD5` (fallback). Newly-issued
 * shops should never need MD5 — switch the keypair in the merchant panel
 * if you see incoming signatures use it.
 */
class PayuSignatureVerifier
{
    public function __construct(
        private readonly ?string $secondKey = null,
    ) {}

    private function secondKey(): string
    {
        return (string) ($this->secondKey ?? config('lunar-payu.second_key'));
    }

    public function verify(string $signatureHeader, string $rawBody): bool
    {
        if ($signatureHeader === '' || $rawBody === '') {
            return false;
        }

        $parts = $this->parseSignatureHeader($signatureHeader);
        if ($parts === null) {
            return false;
        }

        $algorithm = strtolower($parts['algorithm']);
        $hashAlgo = match ($algorithm) {
            'sha-256', 'sha256' => 'sha256',
            'md5' => 'md5',
            default => null,
        };

        if ($hashAlgo === null) {
            return false;
        }

        $secret = $this->secondKey();
        if ($secret === '') {
            return false;
        }

        // PayU's HMAC concatenates body + secret (NOT keyed HMAC):
        //   sha256(body . second_key)
        // This matches the official PHP SDK and merchant-panel documentation.
        $expected = hash($hashAlgo, $rawBody.$secret);

        return hash_equals($expected, strtolower($parts['signature']));
    }

    /**
     * Parses `signature=<hex>;algorithm=SHA-256;sender=...` into a map.
     * Returns null when the header is malformed or missing the required
     * `signature` / `algorithm` keys.
     *
     * @return array{signature: string, algorithm: string}|null
     */
    private function parseSignatureHeader(string $header): ?array
    {
        $parts = array_filter(array_map('trim', explode(';', $header)));
        $map = [];

        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $map[strtolower(trim($k))] = trim($v);
        }

        if (empty($map['signature']) || empty($map['algorithm'])) {
            return null;
        }

        return [
            'signature' => $map['signature'],
            'algorithm' => $map['algorithm'],
        ];
    }
}
