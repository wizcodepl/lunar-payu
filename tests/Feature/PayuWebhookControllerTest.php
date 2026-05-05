<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Lunar\Database\Factories\OrderFactory;
use Lunar\Models\Currency;
use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarPayu\Jobs\ProcessPayuNotification;
use WizcodePl\LunarPayu\PayuSignatureVerifier;
use WizcodePl\LunarPayu\Tests\TestCase;

/**
 * Controller-level tests: middleware enforcement, order lookup, ack body,
 * and the controller's hand-off to the queued job.
 *
 * The HMAC verification path is covered separately in
 * PayuSignatureVerifierTest; here we swap the verifier with a stub so we
 * can drive the controller with arbitrary payloads.
 *
 * The job's actual work — status mapping, idempotency, amount sanity
 * check, domain event dispatch — has its own dedicated test
 * (ProcessPayuNotificationTest).
 */
#[Group('e2e')]
class PayuWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::factory()->create([
            'code' => 'PLN',
            'default' => true,
            'enabled' => true,
            'exchange_rate' => 1,
            'decimal_places' => 2,
        ]);
    }

    public function test_rejects_notification_without_signature_header(): void
    {
        $response = $this->postJson('/payu/notify', [
            'order' => [
                'orderId' => 'PAYU-AAA',
                'status' => 'COMPLETED',
                'totalAmount' => '1500',
                'extOrderId' => '999',
            ],
        ]);

        $response->assertStatus(403);
        $this->assertSame('SIGNATURE_INVALID', $response->getContent());
    }

    public function test_acks_payu_and_dispatches_processing_job(): void
    {
        Bus::fake();
        $this->bindVerifierStub(verified: true);

        $order = OrderFactory::new()->create([
            'total' => 1500,
            'sub_total' => 1500,
            'meta' => ['payu' => ['order_id' => 'PAYU-OK']],
        ]);

        $response = $this->postJson(
            '/payu/notify',
            [
                'order' => [
                    'orderId' => 'PAYU-OK',
                    'status' => 'COMPLETED',
                    'totalAmount' => '1500',
                    'extOrderId' => (string) $order->id,
                ],
            ],
            ['OpenPayU-Signature' => 'signature=any;algorithm=SHA-256'],
        );

        $response->assertStatus(200);
        $this->assertSame('', $response->getContent());

        Bus::assertDispatched(ProcessPayuNotification::class, fn ($job) => $job->order->is($order)
            && $job->payload['payuOrderId'] === 'PAYU-OK'
            && $job->payload['status'] === 'COMPLETED');
    }

    public function test_rejects_when_signature_verifier_returns_false(): void
    {
        Bus::fake();
        $this->bindVerifierStub(verified: false);

        $order = OrderFactory::new()->create([
            'total' => 1500,
            'sub_total' => 1500,
            'meta' => ['payu' => ['order_id' => 'PAYU-BAD']],
        ]);

        $response = $this->postJson(
            '/payu/notify',
            [
                'order' => [
                    'orderId' => 'PAYU-BAD',
                    'status' => 'COMPLETED',
                    'totalAmount' => '1500',
                    'extOrderId' => (string) $order->id,
                ],
            ],
            ['OpenPayU-Signature' => 'signature=tampered;algorithm=SHA-256'],
        );

        $response->assertStatus(403);
        Bus::assertNotDispatched(ProcessPayuNotification::class);
    }

    public function test_returns_404_for_unknown_order(): void
    {
        Bus::fake();
        $this->bindVerifierStub(verified: true);

        $response = $this->postJson(
            '/payu/notify',
            [
                'order' => [
                    'orderId' => 'PAYU-NOPE',
                    'status' => 'COMPLETED',
                    'totalAmount' => '100',
                    'extOrderId' => '99999999',
                ],
            ],
            ['OpenPayU-Signature' => 'signature=any;algorithm=SHA-256'],
        );

        $response->assertStatus(404);
        Bus::assertNotDispatched(ProcessPayuNotification::class);
    }

    /**
     * Swap the HMAC verifier in the container with a deterministic stub.
     */
    private function bindVerifierStub(bool $verified): void
    {
        $this->app->instance(PayuSignatureVerifier::class, new class($verified) extends PayuSignatureVerifier
        {
            public function __construct(private readonly bool $verified)
            {
                parent::__construct(secondKey: 'irrelevant');
            }

            public function verify(string $signatureHeader, string $rawBody): bool
            {
                return $this->verified;
            }
        });
    }
}
