<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu;

use Lunar\Base\DataTransferObjects\PaymentAuthorize;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\Events\PaymentAttemptEvent;
use Lunar\Models\Contracts\Transaction as TransactionContract;
use Lunar\Models\Order;
use Lunar\PaymentTypes\AbstractPayment;
use Throwable;
use WizcodePl\LunarPayu\Enums\PayuTransactionStatus;
use WizcodePl\LunarPayu\Events\PayuPaymentFailed;
use WizcodePl\LunarPayu\Models\PayuTransaction;

/**
 * PayU payment driver for Lunar.
 *
 * Flow:
 *   1. authorize() — creates the Lunar Order (if missing), POSTs an order
 *      to PayU REST, stores the PayU `orderId` on Order.meta and returns
 *      the redirect URL via PaymentAuthorize.message. The frontend
 *      redirects the customer there to complete payment.
 *   2. PayU calls our webhook (POST /payu/notify) on every state change.
 *      The PayuWebhookController acks immediately and dispatches a job
 *      that updates Order.status accordingly.
 *   3. capture() is a no-op success — final state is decided by the
 *      webhook.
 *   4. refund() is a no-op (refunds are out of MVP scope; PayU does have
 *      a refund endpoint, surface it in a follow-up release).
 */
class PayuPaymentDriver extends AbstractPayment
{
    public function __construct(
        private readonly PayuClient $client,
    ) {}

    public function authorize(): ?PaymentAuthorize
    {
        if (! $this->order) {
            $this->order = $this->cart?->draftOrder()->first()
                ?: $this->cart?->createOrder();
        }

        if (! $this->order) {
            return new PaymentAuthorize(
                success: false,
                message: 'Cannot authorize PayU payment without an order or cart',
                paymentType: (string) config('lunar-payu.driver', 'payu'),
            );
        }

        // Open an audit row before the API call so failures are visible too.
        $record = PayuTransaction::create([
            'order_id' => $this->order->id,
            'status' => PayuTransactionStatus::Pending,
            'amount' => (int) $this->order->total->value,
            'currency' => (string) ($this->order->currency_code ?: 'PLN'),
        ]);

        try {
            $response = $this->client->createOrder(
                $this->buildOrderPayload(),
            );
        } catch (Throwable $e) {
            $record->update([
                'status' => PayuTransactionStatus::CreateFailed,
                'last_event' => ['error' => $e->getMessage()],
            ]);
            PayuPaymentFailed::dispatch($this->order, $record->fresh(), $e->getMessage());

            $result = new PaymentAuthorize(
                success: false,
                message: $e->getMessage(),
                orderId: $this->order->id,
                paymentType: (string) config('lunar-payu.driver', 'payu'),
            );
            PaymentAttemptEvent::dispatch($result);

            return $result;
        }

        $payuOrderId = (string) ($response['orderId'] ?? '');
        $redirectUrl = (string) ($response['redirectUri'] ?? '');

        $record->update([
            'payu_order_id' => $payuOrderId,
            'redirect_url' => $redirectUrl,
            'status' => PayuTransactionStatus::RedirectPending,
            'last_event' => ['create_response' => array_intersect_key($response, array_flip(['orderId', 'status', 'redirectUri']))],
        ]);

        // Stamp the order with the latest PayU order id so the webhook can
        // find us by `extOrderId` (= order.id) or by stored payu order id.
        $this->order->update([
            'meta' => array_merge((array) $this->order->meta, [
                'payu' => [
                    'order_id' => $payuOrderId,
                    'redirect_url' => $redirectUrl,
                    'status' => $response['status']['statusCode'] ?? null,
                ],
            ]),
            'placed_at' => now(),
        ]);

        // Lunar PaymentAuthorize doesn't have a redirectUrl field; we
        // surface it through `message` so the storefront can read and
        // forward the customer.
        $result = new PaymentAuthorize(
            success: $payuOrderId !== '' && $redirectUrl !== '',
            message: $redirectUrl,
            orderId: $this->order->id,
            paymentType: (string) config('lunar-payu.driver', 'payu'),
        );

        PaymentAttemptEvent::dispatch($result);

        return $result;
    }

    public function capture(TransactionContract $transaction, $amount = 0): PaymentCapture
    {
        // PayU is webhook-driven — the final status comes from the
        // notification. Returning success here lets Lunar's checkout flow
        // continue cleanly; the webhook is what marks the order as paid.
        return new PaymentCapture(true);
    }

    public function refund(TransactionContract $transaction, int $amount = 0, $notes = null): PaymentRefund
    {
        // Refunds are intentionally not part of MVP — PayU does have a
        // `POST /api/v2_1/orders/{orderId}/refunds` endpoint, surface it
        // in a follow-up release. For now process via the merchant panel.
        return new PaymentRefund(false, 'Refunds are not supported by lunar-payu yet — process via the PayU merchant panel.');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderPayload(): array
    {
        /** @var Order $order */
        $order = $this->order;
        $billing = $order->billingAddress;

        $email = (string) ($billing?->contact_email ?: $order->customer?->meta['email'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // PayU rejects invalid emails. Use a deterministic placeholder
            // so guest checkout flows that collect email later still work.
            $email = sprintf('order-%d@no-email.local', $order->id);
        }

        $firstName = (string) ($billing?->first_name ?? '');
        $lastName = (string) ($billing?->last_name ?? '');
        if ($firstName === '' && $lastName === '') {
            $firstName = 'Customer';
            $lastName = '#'.$order->id;
        }

        $totalAmount = (int) $order->total->value;

        return [
            'merchantPosId' => (string) config('lunar-payu.pos_id'),
            'description' => $this->describeOrder($order),
            'currencyCode' => (string) ($order->currency_code ?: 'PLN'),
            'totalAmount' => (string) $totalAmount,
            // PayU rejects duplicate `extOrderId` (`ERROR_ORDER_NOT_UNIQUE`),
            // so we suffix the Lunar order id with a per-attempt random tail.
            // The webhook controller parses the prefix back to the order id.
            'extOrderId' => $order->id.'-'.bin2hex(random_bytes(4)),
            'customerIp' => (string) (request()->ip() ?: '127.0.0.1'),
            'continueUrl' => (string) config('lunar-payu.return_url_success'),
            'notifyUrl' => url((string) config('lunar-payu.webhook_path', 'payu/notify')),
            'buyer' => [
                'email' => $email,
                'firstName' => $firstName !== '' ? $firstName : 'Customer',
                'lastName' => $lastName !== '' ? $lastName : '#'.$order->id,
                'language' => 'pl',
            ],
            'products' => [
                [
                    'name' => $this->describeOrder($order),
                    'unitPrice' => (string) $totalAmount,
                    'quantity' => '1',
                ],
            ],
        ];
    }

    private function describeOrder(Order $order): string
    {
        $count = $order->lines->count();

        return sprintf('Zamówienie #%d (%d %s)', $order->id, $count, $count === 1 ? 'pozycja' : 'pozycji');
    }
}
