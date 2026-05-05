<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarPayu\Models\PayuTransaction;

/**
 * Dispatched when an authorize() call to PayU errors out — e.g. credentials
 * rejected, malformed payload, network failure. The transaction row is in
 * `create_failed` status; no Lunar Order status change happened.
 */
class PayuPaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ?Order $order,
        public readonly PayuTransaction $transaction,
        public readonly string $reason,
    ) {}
}
