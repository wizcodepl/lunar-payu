<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Actions;

use Lunar\Models\Order;
use WizcodePl\LunarPayu\Enums\PayuTransactionStatus;
use WizcodePl\LunarPayu\Models\PayuTransaction;

/**
 * Updates (or creates) the `PayuTransaction` audit row that corresponds
 * to the incoming webhook. Looks up by `payu_order_id` first, then by
 * `order_id`. If we have neither match (e.g. the driver row was wiped) we
 * still create a row so the notification is not lost.
 */
class RecordPayuWebhookEvent
{
    /**
     * @param array{payuOrderId: string, status: string, amount: string, order_id: string} $payload
     * @param array<string, mixed> $rawBody
     */
    public function __invoke(
        ?Order $order,
        array $payload,
        PayuTransactionStatus $status,
        array $rawBody,
    ): PayuTransaction {
        $record = null;

        if ($payload['payuOrderId'] !== '') {
            $record = PayuTransaction::query()
                ->where('payu_order_id', $payload['payuOrderId'])
                ->latest('id')
                ->first();
        }

        if (! $record && $order) {
            $record = PayuTransaction::query()
                ->where('order_id', $order->id)
                ->latest('id')
                ->first();
        }

        $attributes = [
            'status' => $status,
            'last_event' => ['notification' => $rawBody],
            'last_notification_at' => now(),
        ];

        if ($record) {
            $record->update($attributes);

            return $record->fresh() ?? $record;
        }

        return PayuTransaction::create(array_merge($attributes, [
            'order_id' => $order?->id,
            'payu_order_id' => $payload['payuOrderId'] ?: null,
            'amount' => (int) ($payload['amount'] ?: 0),
            'currency' => (string) ($order?->currency_code ?: 'PLN'),
        ]));
    }
}
