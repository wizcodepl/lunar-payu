<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lunar\Database\Factories\OrderFactory;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarPayu\Actions\RecordPayuWebhookEvent;
use WizcodePl\LunarPayu\Actions\UpdateOrderFromPayuStatus;
use WizcodePl\LunarPayu\Enums\PayuTransactionStatus;
use WizcodePl\LunarPayu\Events\PayuPaymentCancelled;
use WizcodePl\LunarPayu\Events\PayuPaymentReceived;
use WizcodePl\LunarPayu\Events\PayuPaymentRefunded;
use WizcodePl\LunarPayu\Jobs\ProcessPayuNotification;
use WizcodePl\LunarPayu\Models\PayuTransaction;
use WizcodePl\LunarPayu\Tests\TestCase;

/**
 * Covers the post-ack pipeline: status mapping, audit row update, domain
 * event dispatch, idempotency and amount sanity check. Runs the job
 * synchronously (no real queue worker) — that's enough to assert behavior.
 */
#[Group('e2e')]
class ProcessPayuNotificationTest extends TestCase
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

    public function test_marks_order_paid_records_audit_row_and_dispatches_received_event(): void
    {
        Event::fake([PayuPaymentReceived::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, payuId: 'PAYU-PAID-1');

        $this->runJob($order, [
            'payuOrderId' => 'PAYU-PAID-1',
            'status' => 'COMPLETED',
            'amount' => '1500',
            'order_id' => (string) $order->id,
        ]);

        $this->assertSame('paid', $order->fresh()->status);
        $record = PayuTransaction::where('payu_order_id', 'PAYU-PAID-1')->first();
        $this->assertSame(PayuTransactionStatus::Paid, $record?->status);

        Event::assertDispatched(PayuPaymentReceived::class, fn ($e) => $e->order->is($order));
    }

    public function test_idempotent_completed_does_not_double_dispatch(): void
    {
        Event::fake([PayuPaymentReceived::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, payuId: 'PAYU-DUPE');

        $payload = [
            'payuOrderId' => 'PAYU-DUPE',
            'status' => 'COMPLETED',
            'amount' => '1500',
            'order_id' => (string) $order->id,
        ];

        $this->runJob($order, $payload);
        $this->runJob($order, $payload);

        Event::assertDispatchedTimes(PayuPaymentReceived::class, 1);
    }

    public function test_amount_mismatch_on_completed_downgrades_to_failed_and_skips_received_event(): void
    {
        Event::fake([PayuPaymentReceived::class, PayuPaymentCancelled::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, payuId: 'PAYU-WRONG');

        $this->runJob($order, [
            'payuOrderId' => 'PAYU-WRONG',
            'status' => 'COMPLETED',
            'amount' => '100', // expected 1500
            'order_id' => (string) $order->id,
        ]);

        $this->assertNotSame('paid', $order->fresh()->status);
        Event::assertNotDispatched(PayuPaymentReceived::class);
        Event::assertDispatched(PayuPaymentCancelled::class);

        $record = PayuTransaction::where('payu_order_id', 'PAYU-WRONG')->first();
        $this->assertSame(PayuTransactionStatus::Failed, $record?->status);
    }

    public function test_cancelled_dispatches_cancelled_event(): void
    {
        Event::fake([PayuPaymentCancelled::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, payuId: 'PAYU-CAN');

        $this->runJob($order, [
            'payuOrderId' => 'PAYU-CAN',
            'status' => 'CANCELED',
            'amount' => '1500',
            'order_id' => (string) $order->id,
        ]);

        $this->assertSame('cancelled', $order->fresh()->status);
        Event::assertDispatched(PayuPaymentCancelled::class);
    }

    public function test_refunded_dispatches_refunded_event(): void
    {
        Event::fake([PayuPaymentRefunded::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, payuId: 'PAYU-REF');

        $this->runJob($order, [
            'payuOrderId' => 'PAYU-REF',
            'status' => 'REFUNDED',
            'amount' => '1500',
            'order_id' => (string) $order->id,
        ]);

        $this->assertSame('refunded', $order->fresh()->status);
        Event::assertDispatched(PayuPaymentRefunded::class);
    }

    /**
     * @param array{payuOrderId: string, status: string, amount: string, order_id: string} $payload
     */
    private function runJob(Order $order, array $payload): void
    {
        (new ProcessPayuNotification($order, $payload, $payload))
            ->handle(
                app(UpdateOrderFromPayuStatus::class),
                app(RecordPayuWebhookEvent::class),
            );
    }

    private function orderWithExistingTransaction(int $amount, string $payuId): Order
    {
        $order = OrderFactory::new()->create([
            'total' => $amount,
            'sub_total' => $amount,
            'meta' => ['payu' => ['order_id' => $payuId]],
        ]);

        PayuTransaction::create([
            'order_id' => $order->id,
            'payu_order_id' => $payuId,
            'status' => PayuTransactionStatus::RedirectPending,
            'amount' => $amount,
            'currency' => 'PLN',
        ]);

        return $order;
    }
}
