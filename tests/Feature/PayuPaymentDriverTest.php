<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Database\Factories\OrderFactory;
use Lunar\Facades\Payments;
use Lunar\Models\Currency;
use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarPayu\PayuPaymentDriver;
use WizcodePl\LunarPayu\Tests\TestCase;

/**
 * Tests the driver against a real PayU sandbox order creation.
 * We use OrderFactory directly — full Cart→Order flow is Lunar's domain;
 * our concern is "given an Order, does authorize() create a PayU order
 * and persist its metadata".
 */
#[Group('e2e')]
class PayuPaymentDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_is_registered_in_lunar_payment_manager(): void
    {
        $driver = Payments::driver('payu');

        $this->assertInstanceOf(PayuPaymentDriver::class, $driver);
    }

    public function test_authorize_creates_payu_order_and_persists_metadata(): void
    {
        $this->skipIfNoSandboxCreds();

        // Lunar's Order.total cast requires a Currency in scope — minimum seed.
        Currency::factory()->create([
            'code' => 'PLN',
            'default' => true,
            'enabled' => true,
            'exchange_rate' => 1,
            'decimal_places' => 2,
        ]);

        $order = OrderFactory::new()->create([
            'total' => 1500,
            'sub_total' => 1500,
            'currency_code' => 'PLN',
        ]);

        $result = Payments::driver('payu')->order($order)->authorize();

        $this->assertNotNull($result);
        $this->assertTrue(
            $result->success,
            sprintf('authorize() failed: %s', $result->message ?? '(no message)'),
        );
        $this->assertNotEmpty($result->message, 'expected redirect URL in PaymentAuthorize.message');
        $this->assertStringContainsString('payu', $result->message);

        $order = $order->fresh();
        $this->assertNotEmpty($order->meta['payu']['order_id'] ?? null);
        $this->assertNotEmpty($order->meta['payu']['redirect_url'] ?? null);
    }

    public function test_authorize_returns_failure_when_no_cart_or_order(): void
    {
        $result = Payments::driver('payu')->authorize();

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('without an order or cart', (string) $result->message);
    }
}
