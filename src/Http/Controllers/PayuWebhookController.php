<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use WizcodePl\LunarPayu\Actions\RecordPayuWebhookEvent;
use WizcodePl\LunarPayu\Actions\ResolveOrderFromNotification;
use WizcodePl\LunarPayu\Enums\PayuTransactionStatus;
use WizcodePl\LunarPayu\Jobs\ProcessPayuNotification;

/**
 * Receives PayU REST notifications.
 *
 * Authenticity is enforced upstream by the `VerifyPayuSignature`
 * middleware (HMAC-SHA256 over the raw body using the merchant Second
 * key). By the time we run, the body is trusted.
 *
 * The controller is intentionally thin: resolve the Lunar Order, ack
 * PayU with a 200, and hand the heavy work (status update, audit row,
 * domain events) off to a queued job. Listeners doing slow work
 * (emails, fulfilment, PDFs) cannot starve the webhook past PayU's
 * retry window this way.
 */
class PayuWebhookController extends Controller
{
    public function __construct(
        private readonly ResolveOrderFromNotification $resolveOrder,
        private readonly RecordPayuWebhookEvent $recordEvent,
    ) {}

    public function __invoke(Request $request): Response
    {
        $body = $request->isJson() ? ($request->json()->all() ?? []) : $request->all();
        $payload = $this->extractPayload($body);

        Log::channel((string) config('lunar-payu.log_channel', 'stack'))->info('lunar-payu | notification received', [
            'payuOrderId' => $payload['payuOrderId'],
            'status' => $payload['status'],
            'amount' => $payload['amount'],
            'order_id' => $payload['order_id'],
        ]);

        $order = ($this->resolveOrder)($payload);
        if (! $order) {
            // Still record the event for forensics, but tell PayU we couldn't process.
            ($this->recordEvent)(null, $payload, PayuTransactionStatus::Failed, $body);

            return response('ORDER_NOT_FOUND', 404);
        }

        ProcessPayuNotification::dispatch($order, $payload, $body);

        return response('', 200);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{payuOrderId: string, status: string, amount: string, order_id: string}
     */
    private function extractPayload(array $body): array
    {
        // PayU wraps the payload under an `order` key.
        $order = (array) ($body['order'] ?? []);

        return [
            'payuOrderId' => (string) ($order['orderId'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'amount' => (string) ($order['totalAmount'] ?? ''),
            'order_id' => (string) ($order['extOrderId'] ?? ''),
        ];
    }
}
