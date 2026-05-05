<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Tests\Feature;

use WizcodePl\LunarPayu\LunarPayuServiceProvider;
use WizcodePl\LunarPayu\Tests\TestCase;

/**
 * Smoke test — keeps the test suite non-empty until the real driver / webhook
 * tests land. Replace once `LunarPayuPaymentDriver` exists.
 */
class PackageBootsTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $this->assertTrue(
            $this->app->getProviders(LunarPayuServiceProvider::class) !== [],
            'LunarPayuServiceProvider should be registered by Orchestra Testbench',
        );
    }
}
