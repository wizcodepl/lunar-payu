<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 * Concurrency: per-order `Cache::lock` serializes notifications for the
 * same order across worker processes. Without it, two workers could read
 * `previousStatus = RedirectPending` simultaneously and both dispatch a
 * domain event — listeners (mail, fulfilment) would fire twice.
 *
 * Atomicity: the order update + audit row are wrapped in a `DB::transaction`
 * so a partial failure (e.g. order updated, audit row write threw) rolls
 * back. Job retry then re-runs cleanly under idempotency.
 *
 * Idempotency: PayU re-sends the same notification until it gets a 200.
 * We compare the previous transaction status with the resolved one — if
 * it's the same terminal state, we skip dispatching domain events.
 */
class ProcessPayuNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LOCK_TTL_SECONDS = 30;

    private const LOCK_BLOCK_SECONDS = 10;

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
        $lockKey = sprintf('lunar-payu:order:%d', $this->order->id);

        Cache::lock($lockKey, self::LOCK_TTL_SECONDS)
            ->block(self::LOCK_BLOCK_SECONDS, function () use ($updateOrder, $recordEvent) {
                // Refresh inside the lock — another worker may have updated
                // since this job was serialized into the queue.
                $order = $this->order->fresh() ?? $this->order;

                $previousStatus = $this->previousTransactionStatus();

                /** @var array{0: PayuTransactionStatus, 1: PayuTransaction} $result */
                $result = DB::transaction(function () use ($updateOrder, $recordEvent, $order) {
                    $resolvedStatus = $updateOrder($order, $this->payload);
                    $transaction = $recordEvent($order, $this->payload, $resolvedStatus, $this->rawBody);

                    return [$resolvedStatus, $transaction];
                });

                [$resolvedStatus, $transaction] = $result;

                // Idempotency: skip event dispatch if we've already settled
                // in this state. This also covers the downgrade-rejected
                // path — UpdateOrderFromPayuStatus mirrors the current
                // order status back, which equals previousStatus.
                if ($previousStatus === $resolvedStatus && $this->isTerminal($resolvedStatus)) {
                    return;
                }

                match ($resolvedStatus) {
                    PayuTransactionStatus::Paid => PayuPaymentReceived::dispatch($order, $transaction),
                    PayuTransactionStatus::Refunded => PayuPaymentRefunded::dispatch($order, $transaction),
                    PayuTransactionStatus::Cancelled, PayuTransactionStatus::Failed => PayuPaymentCancelled::dispatch($order, $transaction),
                    default => null,
                };
            });
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
