# Changelog

All notable changes to `wizcodepl/lunar-payu` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-05-05

First stable release. The package is in production-grade shape and validated end-to-end against the real PayU sandbox.

### Added
- **PayU REST payment driver** for Lunar PHP — registers as `payu` in Lunar's `PaymentManager`.
- `PayuClient` over Laravel `Http` facade — OAuth2 `client_credentials` flow with cached bearer tokens, auto-refresh on 401, `createOrder` against `POST /api/v2_1/orders`.
- **HMAC-SHA256 webhook signature verification** (`PayuSignatureVerifier`) — parses the `OpenPayU-Signature` header, computes `sha256(body . second_key)`, constant-time compare via `hash_equals`. Legacy MD5 algorithm supported as a fallback for shops still on it.
- **`VerifyPayuSignature` middleware** — rejects unsigned / invalid bodies with `403 SIGNATURE_INVALID` before downstream code runs.
- **`payu_transactions` audit table** — append-only log of every authorize attempt and webhook event (PayU order id, status, amount, redirect URL, last raw event, last notification timestamp). Indexed by `payu_order_id` and `status`.
- **`PayuTransactionStatus` enum** — `Pending` / `CreateFailed` / `RedirectPending` / `Paid` / `Failed` / `Refunded` / `Cancelled` cast on `PayuTransaction.status`.
- **Domain events** — `PayuPaymentReceived`, `PayuPaymentFailed`, `PayuPaymentRefunded`, `PayuPaymentCancelled`. Listeners can implement `ShouldQueue` for slow work (mails, fulfilment) without starving the webhook.
- **Actions decomposition** — `ResolveOrderFromNotification`, `UpdateOrderFromPayuStatus`, `RecordPayuWebhookEvent`. Controller composes them; each is independently testable.
- **`ProcessPayuNotification` job** — webhook controller acks PayU with `200` immediately and dispatches the job, so heavy listener work happens on a queue worker.
- **Per-order `Cache::lock`** in the job — prevents two concurrent workers from double-dispatching domain events for notifications that arrive within milliseconds of each other.
- **DB transaction** wrapping the order update + audit-row write inside the job — partial failures roll back cleanly and the queue retry runs again under idempotency.
- **Idempotency** — repeat notifications for already-settled transactions don't double-fire domain events.
- **Status downgrade guard** in `UpdateOrderFromPayuStatus` — once an order is `paid`, the only allowed next state is `refunded`. Replays of older `CANCELED` / `REJECTED` notifications cannot flip the order back. Order's `meta.payu.rejected_status` records the rejected payload for forensics.
- **Amount sanity check** — `COMPLETED` notifications with a `totalAmount` that doesn't match the order total are downgraded to `Failed` and logged.
- Per-attempt unique `extOrderId` (`<lunar_order_id>-<random>`) to satisfy PayU's `ERROR_ORDER_NOT_UNIQUE` rule on retries; webhook controller parses the prefix back.
- Configurable webhook path, sandbox / production toggle, return URLs, log channel.
- E2E test suite against the real PayU sandbox (no mocks for the API contract) — `PayuClientTest`, `PayuPaymentDriverTest`, `PayuSignatureVerifierTest`, `PayuWebhookControllerTest`, `ProcessPayuNotificationTest`. Skip cleanly when `PAYU_*` sandbox creds aren't in env.
- CI workflows for tests / pint / phpstan, matrix PHP 8.2 / 8.3 / 8.4 against Lunar `^1.3`.

### Notes
- **Refunds are panel-only by design.** `refund()` on the driver returns `PaymentRefund(false, …)` and that is the intended behavior. The package handles **inbound** refund webhooks (`REFUNDED` → `Refunded` status, `PayuPaymentRefunded` event fires, order moves to `refunded`); refunds themselves are issued in the PayU merchant panel.
- **Recurring billing / saved cards** are not in scope.
