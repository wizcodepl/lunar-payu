<p align="center">
  <img src="art/logo.svg" alt="Lunar PayU" width="200">
</p>

# lunar-payu

PayU REST payment driver for [Lunar PHP](https://lunarphp.io).

## Features

- **PayU REST API** — `POST /api/v2_1/orders` with OAuth2 `client_credentials` and cached bearer tokens (auto-refresh on 401).
- **HMAC-SHA256 webhook signature verification** — `OpenPayU-Signature` header parsed and validated against the merchant Second key. Legacy MD5 algorithm supported as a fallback for shops still on it.
- **`payu_transactions` audit table** — append-only log of every authorize attempt and webhook event (PayU order id, status, amount, redirect URL, last raw event, last notification timestamp). Indexed by `payu_order_id` and `status`.
- **`PayuTransactionStatus` enum** — `Pending` / `CreateFailed` / `RedirectPending` / `Paid` / `Failed` / `Refunded` / `Cancelled` cast on `PayuTransaction.status`.
- **Domain events** — `PayuPaymentReceived`, `PayuPaymentFailed`, `PayuPaymentRefunded`, `PayuPaymentCancelled`. Listeners can implement `ShouldQueue` to do slow work (mails, fulfilment) without starving the webhook.
- **Actions decomposition** — webhook handling split into `ResolveOrderFromNotification`, `UpdateOrderFromPayuStatus`, `RecordPayuWebhookEvent`. Controller composes them; each is independently testable.
- **Queueable webhook job** — controller acks PayU with `200` immediately and dispatches `ProcessPayuNotification`, so heavy listener work happens on a queue worker.
- **Idempotent webhook processing** — repeat notifications for already-settled transactions don't double-fire domain events.
- **Amount sanity check** — `COMPLETED` notifications with a `totalAmount` that doesn't match the order total are downgraded to `Failed` and logged.

## Requirements

- PHP 8.2+
- Lunar core ^1.3

## Install

```bash
composer require wizcodepl/lunar-payu
php artisan vendor:publish --tag=lunar-payu-config
php artisan vendor:publish --tag=lunar-payu-migrations
php artisan migrate
```

The service provider auto-registers via Laravel package discovery.

## Configure

Set the following in your `.env`:

```env
PAYU_POS_ID="..."
PAYU_CLIENT_ID="..."
PAYU_CLIENT_SECRET="..."
PAYU_SECOND_KEY="..."
PAYU_SANDBOX=true
PAYU_RETURN_URL_SUCCESS="https://shop.example/order/thanks"
PAYU_RETURN_URL_ERROR="https://shop.example/checkout"
```

Register the driver in `config/lunar.php`:

```php
'payments' => [
    'types' => [
        'payu' => ['driver' => 'payu'],
    ],
],
```

In the PayU merchant panel set the notification URL to:

```
https://shop.example/payu/notify
```

(The path is configurable via `PAYU_WEBHOOK_PATH`.)

## Use

```php
use Lunar\Facades\Payments;

$result = Payments::driver('payu')->order($order)->authorize();

if ($result?->success) {
    return redirect($result->message); // PayU's redirectUri lives in PaymentAuthorize.message
}
```

The customer pays on PayU's hosted page and is sent to `PAYU_RETURN_URL_SUCCESS`. The actual order status is updated by the webhook a few seconds later (asynchronously through `ProcessPayuNotification`), so don't trust the URL — read `Order.status` instead.

## Listening to events

```php
use WizcodePl\LunarPayu\Events\PayuPaymentReceived;

Event::listen(PayuPaymentReceived::class, function (PayuPaymentReceived $e) {
    // $e->order, $e->transaction
    Mail::to($e->order->billingAddress->contact_email)->queue(new PaymentConfirmation($e->order));
});
```

If your listener is slow, implement `ShouldQueue` — the event is already serializable.

## Testing

```bash
composer install
composer test       # runs everything; e2e suites skip if PayU sandbox creds aren't set
composer format     # Pint
composer analyse    # PHPStan level 5 (Larastan)
```

E2E tests against the real PayU sandbox require:

```bash
export PAYU_POS_ID="..."
export PAYU_CLIENT_ID="..."
export PAYU_CLIENT_SECRET="..."
export PAYU_SECOND_KEY="..."
composer test
```

## Refunds

Refunds are issued from the **PayU merchant panel** — this package does not expose a programmatic refund API. `refund()` on the driver returns `PaymentRefund(false, …)` by design.

What the package **does** handle on the refund side:

- `REFUNDED` webhooks from PayU are received, verified, and update the Lunar Order status to `refunded`.
- A `PayuPaymentRefunded` domain event fires so listeners can release stock, notify the customer, alert finance, etc.
- The audit row in `payu_transactions` records the refund event.

So: trigger refunds in the panel, listen to the event in your app.

## What's intentionally not in this package

- **Programmatic refund API** — see above.
- **Recurring billing / saved cards** — PayU supports them but they need their own DTO/event surface and a token storage strategy. Not in scope.
- **Partial captures** — order is captured in full when PayU reports `COMPLETED`.
- **Filament admin UI** — `Order.meta.payu` is the source of truth; surface it in your panel however you prefer.

## License

MIT — see [LICENSE](LICENSE).
