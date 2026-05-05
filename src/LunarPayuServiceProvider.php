<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu;

use Illuminate\Support\ServiceProvider;
use Lunar\Facades\Payments;

/**
 * Boots the lunar-payu package — config, migrations, routes, and the
 * driver registration with Lunar's PaymentManager.
 */
class LunarPayuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lunar-payu.php', 'lunar-payu');

        $this->app->singleton(PayuClient::class, fn () => new PayuClient(
            sandbox: (bool) config('lunar-payu.sandbox', true),
        ));

        $this->app->singleton(PayuSignatureVerifier::class, fn () => new PayuSignatureVerifier);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lunar-payu.php' => config_path('lunar-payu.php'),
            ], 'lunar-payu-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'lunar-payu-migrations');
        }

        Payments::extend((string) config('lunar-payu.driver', 'payu'), function ($app) {
            return new PayuPaymentDriver($app->make(PayuClient::class));
        });
    }
}
