<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarPayu\Actions\RecordPayuWebhookEvent;
use WizcodePl\LunarPayu\Actions\UpdateOrderFromPayuStatus;
use WizcodePl\LunarPayu\Enums\PayuTransactionStatus;
use WizcodePl\LunarPayu\Events\PayuPaymentCancelled;
use WizcodePl\LunarPayu\Events\PayuPaymentReceived;
use WizcodePl\LunarPayu\Events\PayuPaymentRefunded;
use WizcodePl\LunarPayu\Models\PayuTransaction;

/**
 * Async pipeline for a verified PayU webhook.
 *
 * The webhook controller acks PayU immediately (so retries don't pile up
 * if listeners are slow) and dispatches this job to do the actual work:
 * apply the status, record the audit row, fire domain events.
 *
 * Idempotency: PayU re-sends the same notification until it gets a 200.
 * We compare the previous transaction status with the resolved one — if
 * it's the same terminal state, we skip dispatching domain events so
 * listeners (mails, fulfilment) don't fire twice.
 */
class ProcessPayuNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array{payuOrderId: string, status: string, amount: string, order_id: string} $payload
     * @param array<string, mixed> $rawBody
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $payload,
        public readonly array $rawBody,
    ) {}

    public function handle(
        UpdateOrderFromPayuStatus $updateOrder,
        RecordPayuWebhookEvent $recordEvent,
    ): void {
        $previousStatus = $this->previousTransactionStatus();

        $resolvedStatus = $updateOrder($this->order, $this->payload);
        $transaction = $recordEvent($this->order, $this->payload, $resolvedStatus, $this->rawBody);

        // Idempotency: skip event dispatch if we've already settled in this state.
        if ($previousStatus === $resolvedStatus && $this->isTerminal($resolvedStatus)) {
            return;
        }

        match ($resolvedStatus) {
            PayuTransactionStatus::Paid => PayuPaymentReceived::dispatch($this->order, $transaction),
            PayuTransactionStatus::Refunded => PayuPaymentRefunded::dispatch($this->order, $transaction),
            PayuTransactionStatus::Cancelled, PayuTransactionStatus::Failed => PayuPaymentCancelled::dispatch($this->order, $transaction),
            default => null,
        };
    }

    private function previousTransactionStatus(): ?PayuTransactionStatus
    {
        $existing = PayuTransaction::query()
            ->when(
                $this->payload['payuOrderId'] !== '',
                fn ($q) => $q->where('payu_order_id', $this->payload['payuOrderId']),
                fn ($q) => $q->where('order_id', $this->order->id),
            )
            ->latest('id')
            ->first();

        return $existing?->status;
    }

    private function isTerminal(PayuTransactionStatus $status): bool
    {
        return in_array($status, [
            PayuTransactionStatus::Paid,
            PayuTransactionStatus::Refunded,
            PayuTransactionStatus::Cancelled,
            PayuTransactionStatus::Failed,
        ], true);
    }
}
