<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarPayu\Models\PayuTransaction;

/**
 * Dispatched when a PayU webhook confirms a payment as successful (status
 * `COMPLETED`). Listen for this to send confirmation emails, hand off to
 * fulfilment, etc.
 */
class PayuPaymentReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly PayuTransaction $transaction,
    ) {}
}
