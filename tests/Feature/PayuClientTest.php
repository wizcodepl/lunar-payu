<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarPayu\PayuClient;
use WizcodePl\LunarPayu\Tests\TestCase;

/**
 * End-to-end against the real PayU sandbox. No mocks — real OAuth, real
 * `POST /api/v2_1/orders`.
 *
 * Skipped automatically when PAYU_CLIENT_ID / PAYU_CLIENT_SECRET / PAYU_POS_ID
 * aren't in env, so CI without secrets stays green. Run locally with:
 *
 *     export PAYU_POS_ID=...
 *     export PAYU_CLIENT_ID=...
 *     export PAYU_CLIENT_SECRET=...
 *     composer test
 */
#[Group('e2e')]
class PayuClientTest extends TestCase
{
    private PayuClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoSandboxCreds();

        $this->client = new PayuClient(
            sandbox: true,
            clientId: (string) getenv('PAYU_CLIENT_ID'),
            clientSecret: (string) getenv('PAYU_CLIENT_SECRET'),
        );
    }

    public function test_authenticates_against_sandbox_oauth(): void
    {
        $token = $this->client->accessToken();

        $this->assertNotEmpty($token, 'expected non-empty bearer token from PayU OAuth');
    }

    public function test_creates_order_against_sandbox(): void
    {
        $response = $this->client->createOrder([
            'merchantPosId' => (string) getenv('PAYU_POS_ID'),
            'description' => 'lunar-payu e2e create order',
            'currencyCode' => 'PLN',
            'totalAmount' => '100', // 1.00 PLN in minor units
            'extOrderId' => 'lunar-payu-e2e-'.uniqid(),
            'customerIp' => '127.0.0.1',
            'continueUrl' => 'https://example.test/ok',
            'notifyUrl' => 'https://example.test/payu/notify',
            'buyer' => [
                'email' => 'test@wizcode.pl',
                'firstName' => 'Test',
                'lastName' => 'Payer',
                'language' => 'pl',
            ],
            'products' => [[
                'name' => 'lunar-payu test product',
                'unitPrice' => '100',
                'quantity' => '1',
            ]],
        ]);

        $this->assertNotEmpty($response['orderId'] ?? null, 'expected orderId in response');
        $this->assertNotEmpty($response['redirectUri'] ?? null, 'expected redirectUri in response');
        $this->assertSame('SUCCESS', (string) ($response['status']['statusCode'] ?? ''));
    }

    public function test_throws_runtime_exception_on_bad_payload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PayU createOrder failed');

        // Missing required fields (merchantPosId, totalAmount, etc.) — PayU rejects.
        $this->client->createOrder(['description' => 'no required fields']);
    }
}
