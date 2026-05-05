<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Actions;

use Illuminate\Support\Facades\Log;
use Lunar\Models\Order;
use WizcodePl\LunarPayu\Enums\PayuTransactionStatus;

/**
 * Translates the PayU-side status string carried by a webhook into the
 * matching `PayuTransactionStatus` and applies the corresponding Lunar
 * Order status.
 *
 * On a `COMPLETED` notification we additionally verify the reported
 * `totalAmount` matches the order total (defense in depth — the HMAC
 * already prevents tampering, but a future integration mistake or upstream
 * bug would be caught here). A mismatch downgrades the result to `Failed`
 * and the order is left in `awaiting-payment` for manual review.
 */
class UpdateOrderFromPayuStatus
{
    /**
     * @param array{payuOrderId: string, status: string, amount: string, order_id: string} $payload
     */
    public function __invoke(Order $order, array $payload): PayuTransactionStatus
    {
        $resolved = $this->resolveStatus($payload['status']);

        if ($resolved === PayuTransactionStatus::Paid && ! $this->amountMatches($order, $payload['amount'])) {
            Log::channel((string) config('lunar-payu.log_channel', 'stack'))->warning(
                'lunar-payu | COMPLETED notification with amount mismatch — refusing to mark as paid',
                [
                    'order_id' => $order->id,
                    'order_total' => (int) $order->total->value,
                    'reported_amount' => $payload['amount'],
                    'payuOrderId' => $payload['payuOrderId'],
                ],
            );

            $resolved = PayuTransactionStatus::Failed;
        }

        $order->update([
            'status' => $this->mapToOrderStatus($resolved),
            'meta' => array_merge((array) $order->meta, [
                'payu' => array_merge((array) ($order->meta['payu'] ?? []), [
                    'last_status' => $payload['status'],
                    'last_amount' => $payload['amount'],
                    'last_notification_at' => now()->toIso8601String(),
                ]),
            ]),
        ]);

        return $resolved;
    }

    private function resolveStatus(string $payuStatus): PayuTransactionStatus
    {
        return match (strtoupper($payuStatus)) {
            'COMPLETED' => PayuTransactionStatus::Paid,
            'CANCELED', 'CANCELLED' => PayuTransactionStatus::Cancelled,
            'REJECTED' => PayuTransactionStatus::Failed,
            'REFUNDED' => PayuTransactionStatus::Refunded,
            default => PayuTransactionStatus::RedirectPending,
        };
    }

    private function mapToOrderStatus(PayuTransactionStatus $status): string
    {
        return match ($status) {
            PayuTransactionStatus::Paid => 'paid',
            PayuTransactionStatus::Refunded => 'refunded',
            PayuTransactionStatus::Cancelled, PayuTransactionStatus::Failed => 'cancelled',
            default => 'awaiting-payment',
        };
    }

    /**
     * PayU reports `totalAmount` in minor units as a string ("1500"). Lunar
     * also stores totals in minor units. Direct compare with 1-unit
     * tolerance for any rounding edge cases.
     */
    private function amountMatches(Order $order, string $reported): bool
    {
        if ($reported === '' || ! ctype_digit($reported)) {
            return false;
        }

        $reportedMinor = (int) $reported;
        $expectedMinor = (int) $order->total->value;

        return abs($reportedMinor - $expectedMinor) <= 1;
    }
}
