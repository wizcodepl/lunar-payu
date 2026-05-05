<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Base\BaseModel;
use Lunar\Models\Order;
use WizcodePl\LunarPayu\Enums\PayuTransactionStatus;

/**
 * Append-only audit log of PayU transactions opened from this shop.
 *
 * One row per `Payments::driver('payu')->...->authorize()` call. Updated by
 * the driver (after `POST /orders`) and by the webhook controller (on every
 * notification).
 *
 * Lookups: by `payu_order_id` (webhook → row), by `order_id` (admin →
 * history), by `status` (queues / dashboards).
 */
class PayuTransaction extends BaseModel
{
    protected $fillable = [
        'order_id',
        'payu_order_id',
        'status',
        'amount',
        'currency',
        'redirect_url',
        'last_event',
        'last_notification_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'last_event' => 'array',
            'last_notification_at' => 'datetime',
            'status' => PayuTransactionStatus::class,
        ];
    }

    public function getTable()
    {
        return config('lunar.database.table_prefix').'payu_transactions';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
