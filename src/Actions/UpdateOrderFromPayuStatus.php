<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Actions;

use Illuminate\Support\Facades\Log;
use Lunar\Models\Order;
use WizcodePl\LunarPayu\Enums\LunarOrderStatus;
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
        $currentLunarStatus = LunarOrderStatus::tryFrom((string) $order->status);

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

        // Status downgrade guard — once an order is `paid`, only `refunded`
        // is a legitimate next state (refund issued from the PayU merchant
        // panel). Replays of older `CANCELED` / `REJECTED` notifications
        // (legitimately late, or maliciously replayed) cannot flip the
        // order back. `refunded` orders are terminal too.
        if ($currentLunarStatus !== null && $this->isDowngrade($currentLunarStatus, $resolved)) {
            Log::channel((string) config('lunar-payu.log_channel', 'stack'))->warning(
                'lunar-payu | rejected notification that would downgrade an already-settled order',
                [
                    'order_id' => $order->id,
                    'current_order_status' => $currentLunarStatus->value,
                    'reported_status' => $payload['status'],
                    'payuOrderId' => $payload['payuOrderId'],
                ],
            );

            $order->update([
                'meta' => array_merge((array) $order->meta, [
                    'payu' => array_merge((array) ($order->meta['payu'] ?? []), [
                        'rejected_status' => $payload['status'],
                        'rejected_at' => now()->toIso8601String(),
                    ]),
                ]),
            ]);

            // Reflect current order state back to caller — the job's
            // idempotency check then sees "no transition" and skips events.
            return $this->mirrorOrderStatus($currentLunarStatus);
        }

        $order->update([
            'status' => $this->mapToOrderStatus($resolved)->value,
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

    private function mapToOrderStatus(PayuTransactionStatus $status): LunarOrderStatus
    {
        return match ($status) {
            PayuTransactionStatus::Paid => LunarOrderStatus::Paid,
            PayuTransactionStatus::Refunded => LunarOrderStatus::Refunded,
            PayuTransactionStatus::Cancelled, PayuTransactionStatus::Failed => LunarOrderStatus::Cancelled,
            default => LunarOrderStatus::AwaitingPayment,
        };
    }

    /**
     * Order is "settled" (paid or refunded) and the incoming status would
     * move it to a non-allowed state. Allowed transitions:
     *   paid     → refunded (legit refund issued from PayU panel)
     *   paid     → paid     (duplicate notification — let through, idempotent)
     *   refunded → refunded (duplicate notification — let through)
     */
    private function isDowngrade(LunarOrderStatus $current, PayuTransactionStatus $incoming): bool
    {
        if ($current === LunarOrderStatus::Paid) {
            return $incoming !== PayuTransactionStatus::Paid
                && $incoming !== PayuTransactionStatus::Refunded;
        }

        if ($current === LunarOrderStatus::Refunded) {
            return $incoming !== PayuTransactionStatus::Refunded;
        }

        return false;
    }

    private function mirrorOrderStatus(LunarOrderStatus $current): PayuTransactionStatus
    {
        return match ($current) {
            LunarOrderStatus::Paid => PayuTransactionStatus::Paid,
            LunarOrderStatus::Refunded => PayuTransactionStatus::Refunded,
            default => PayuTransactionStatus::RedirectPending,
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
