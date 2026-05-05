<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Actions;

use Lunar\Models\Order;

/**
 * Maps an incoming PayU notification back to a Lunar Order.
 *
 * Primary lookup: `extOrderId` (set to the Lunar Order id when we created
 * the PayU order). Fallback: stored `meta.payu.order_id` in case the
 * `extOrderId` was lost upstream.
 */
class ResolveOrderFromNotification
{
    /**
     * @param array{payuOrderId: string, status: string, amount: string, order_id: string} $payload
     */
    public function __invoke(array $payload): ?Order
    {
        if ($payload['order_id'] !== '' && ctype_digit($payload['order_id'])) {
            $order = Order::find((int) $payload['order_id']);
            if ($order) {
                return $order;
            }
        }

        if ($payload['payuOrderId'] === '') {
            return null;
        }

        return Order::query()
            ->whereJsonContains('meta->payu->order_id', $payload['payuOrderId'])
            ->first();
    }
}
