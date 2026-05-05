<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Tests\Feature;

use WizcodePl\LunarPayu\PayuSignatureVerifier;
use WizcodePl\LunarPayu\Tests\TestCase;

/**
 * Cryptographic / parsing tests for the HMAC verifier — runs without
 * Lunar's DB layer, no e2e marker needed.
 */
class PayuSignatureVerifierTest extends TestCase
{
    private const SECRET = 'test-second-key';

    public function test_accepts_a_correctly_signed_sha256_body(): void
    {
        $body = '{"order":{"orderId":"ABC123","status":"COMPLETED"}}';
        $sig = hash('sha256', $body.self::SECRET);
        $header = "signature={$sig};algorithm=SHA-256;sender=checkout";

        $verifier = new PayuSignatureVerifier(self::SECRET);

        $this->assertTrue($verifier->verify($header, $body));
    }

    public function test_accepts_legacy_md5_when_panel_is_still_on_md5(): void
    {
        $body = '{"order":{"orderId":"X"}}';
        $sig = hash('md5', $body.self::SECRET);
        $header = "signature={$sig};algorithm=MD5;sender=checkout";

        $this->assertTrue((new PayuSignatureVerifier(self::SECRET))->verify($header, $body));
    }

    public function test_rejects_tampered_body(): void
    {
        $body = '{"order":{"orderId":"ABC"}}';
        $sig = hash('sha256', $body.self::SECRET);
        $tampered = '{"order":{"orderId":"DEF"}}'; // body changed, sig didn't
        $header = "signature={$sig};algorithm=SHA-256";

        $this->assertFalse((new PayuSignatureVerifier(self::SECRET))->verify($header, $tampered));
    }

    public function test_rejects_wrong_secret(): void
    {
        $body = '{"order":{}}';
        $sig = hash('sha256', $body.'WRONG-KEY');
        $header = "signature={$sig};algorithm=SHA-256";

        $this->assertFalse((new PayuSignatureVerifier(self::SECRET))->verify($header, $body));
    }

    public function test_rejects_unknown_algorithm(): void
    {
        $body = '{"order":{}}';
        $sig = hash('sha256', $body.self::SECRET);
        $header = "signature={$sig};algorithm=XXX-512";

        $this->assertFalse((new PayuSignatureVerifier(self::SECRET))->verify($header, $body));
    }

    public function test_rejects_malformed_header(): void
    {
        $body = '{"order":{}}';

        $this->assertFalse((new PayuSignatureVerifier(self::SECRET))->verify('garbage-no-equals-sign', $body));
        $this->assertFalse((new PayuSignatureVerifier(self::SECRET))->verify('signature=abc', $body)); // no algorithm
        $this->assertFalse((new PayuSignatureVerifier(self::SECRET))->verify('algorithm=SHA-256', $body)); // no signature
    }

    public function test_rejects_empty_inputs(): void
    {
        $verifier = new PayuSignatureVerifier(self::SECRET);

        $this->assertFalse($verifier->verify('', 'body'));
        $this->assertFalse($verifier->verify('signature=abc;algorithm=SHA-256', ''));
    }

    public function test_rejects_when_no_secret_configured(): void
    {
        $body = '{"order":{}}';
        $sig = hash('sha256', $body.'whatever');
        $header = "signature={$sig};algorithm=SHA-256";

        $this->assertFalse((new PayuSignatureVerifier(''))->verify($header, $body));
    }
}
