<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Enums;

/**
 * Lifecycle of a `PayuTransaction` row.
 *
 *   Pending           — row opened, `POST /api/v2_1/orders` not yet attempted.
 *   CreateFailed      — `POST /api/v2_1/orders` threw / returned an error.
 *   RedirectPending   — PayU returned an `orderId` + `redirectUri`; we are
 *                       waiting for the customer to come back and the webhook
 *                       to fire.
 *   Paid              — webhook reports the order COMPLETED.
 *   Failed            — webhook reports REJECTED (declined / blocked).
 *   Refunded          — webhook reports a refund.
 *   Cancelled         — webhook reports CANCELED.
 */
enum PayuTransactionStatus: string
{
    case Pending = 'pending';
    case CreateFailed = 'create_failed';
    case RedirectPending = 'redirect_pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
}
