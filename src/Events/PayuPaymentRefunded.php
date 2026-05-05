<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarPayu\Models\PayuTransaction;

/**
 * Dispatched when a PayU webhook reports a refund. The Lunar Order has
 * been moved to `refunded`; listeners may want to notify the customer,
 * kick off a return / restock workflow, alert finance, etc.
 */
class PayuPaymentRefunded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly PayuTransaction $transaction,
    ) {}
}
