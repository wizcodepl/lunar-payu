<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu;

use Illuminate\Support\ServiceProvider;

/**
 * Boots the lunar-payu package — config, payment driver registration,
 * webhook routes. Currently a scaffold; the actual PayU integration is
 * being added incrementally.
 */
class LunarPayuServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
