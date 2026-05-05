# Changelog

All notable changes to `wizcodepl/lunar-payu` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **PayU REST payment driver** for Lunar PHP — registers as `payu` in Lunar's `PaymentManager`.
- `PayuClient` over Laravel `Http` facade — OAuth2 `client_credentials` flow with cached bearer tokens, auto-refresh on 401, `createOrder` against `POST /api/v2_1/orders`.
- **HMAC-SHA256 webhook signature verification** (`PayuSignatureVerifier`) — parses the `OpenPayU-Signature` header, computes `sha256(body . second_key)`, constant-time compare via `hash_equals`. Legacy MD5 algorithm supported as a fallback for shops still on it.
- **`VerifyPayuSignature` middleware** — rejects unsigned / invalid bodies with `403 SIGNATURE_INVALID` before downstream code runs.
- **`payu_transactions` audit table** — append-only log of every authorize attempt and webhook event.
- **`PayuTransactionStatus` enum** with model cast on `PayuTransaction.status`.
- **Domain events** — `PayuPaymentReceived`, `PayuPaymentFailed`, `PayuPaymentRefunded`, `PayuPaymentCancelled`.
- **Actions decomposition** — `ResolveOrderFromNotification`, `UpdateOrderFromPayuStatus`, `RecordPayuWebhookEvent`. Controller composes them; each is independently testable.
- **`ProcessPayuNotification` job** — webhook controller acks PayU immediately and dispatches the job, so heavy listener work happens on a queue worker.
- **Idempotency** — repeat notifications for already-settled transactions don't double-fire domain events.
- **Amount sanity check** — `COMPLETED` notifications with a `totalAmount` that doesn't match the order total are downgraded to `Failed` and logged.
- Configurable webhook path, sandbox / production toggle, return URLs, log channel.
- CI workflows for tests / pint / phpstan, matrix PHP 8.2 / 8.3 / 8.4 against Lunar `^1.3`.

### Notes
- **Refunds are out of MVP scope.** `refund()` returns `PaymentRefund(false, …)`. PayU does have a refund endpoint — surface it in a follow-up release.
- **Recurring billing / saved cards** out of MVP scope.
