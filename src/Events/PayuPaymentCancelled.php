<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarPayu\Models\PayuTransaction;

/**
 * Dispatched when a PayU webhook reports a cancelled / failed transaction
 * (customer aborted, bank declined, timeout). The Lunar Order has been
 * moved to `cancelled`; listeners may want to release reserved stock,
 * send a "we noticed your payment didn't go through" email, etc.
 */
class PayuPaymentCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly PayuTransaction $transaction,
    ) {}
}
