# lunar-payu

PayU REST payment driver for [Lunar PHP](https://lunarphp.io).

> ⚠️ **Pre-1.0 — scaffold only.** The package is set up with CI, lint and static analysis, but the PayU driver itself is not yet implemented. Track progress in [CHANGELOG.md](CHANGELOG.md).

## Planned scope

- Authorize → redirect → webhook-driven capture flow against the PayU REST API
- Recurring billing (saved cards / tokenization)
- Refunds (full and partial) and partial captures
- Webhook signature verification per PayU's IPN spec
- Audit log table for every authorize / refund / webhook event
- Domain events: `PayuPaymentReceived`, `PayuPaymentFailed`, `PayuPaymentRefunded`, `PayuPaymentCancelled`

## Requirements

- PHP 8.2+
- Lunar core ^1.3

## Install

```bash
composer require wizcodepl/lunar-payu
```

The service provider auto-registers via Laravel package discovery.

## License

MIT — see [LICENSE](LICENSE).
