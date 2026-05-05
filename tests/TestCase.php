<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Tests;

use Cartalyst\Converter\Laravel\ConverterServiceProvider;
use Kalnoy\Nestedset\NestedSetServiceProvider;
use Lunar\LunarServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use WizcodePl\LunarPayu\LunarPayuServiceProvider;

/**
 * Base test case bootstrapping the minimum stack required for a Lunar
 * plugin — Lunar core + its transitive Laravel providers (Cartalyst
 * Converter, Spatie ActivityLog/MediaLibrary/Blink, Kalnoy NestedSet).
 * Without these Lunar's boot fails with a `Target class [converter] does
 * not exist` style error from the Converter facade in
 * `LunarServiceProvider::boot()`.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConverterServiceProvider::class,
            ActivitylogServiceProvider::class,
            MediaLibraryServiceProvider::class,
            BlinkServiceProvider::class,
            NestedSetServiceProvider::class,
            LunarServiceProvider::class,
            LunarPayuServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('lunar-payu.client_id', env('PAYU_CLIENT_ID', ''));
        $app['config']->set('lunar-payu.client_secret', env('PAYU_CLIENT_SECRET', ''));
        $app['config']->set('lunar-payu.pos_id', env('PAYU_POS_ID', ''));
        $app['config']->set('lunar-payu.second_key', env('PAYU_SECOND_KEY', 'test-second-key'));
        $app['config']->set('lunar-payu.base_url', 'https://secure.snd.payu.com');
        $app['config']->set('lunar-payu.return_url_success', 'https://example.test/ok');
        $app['config']->set('lunar-payu.return_url_error', 'https://example.test/err');
    }

    protected function skipIfNoSandboxCreds(): void
    {
        if (! getenv('PAYU_CLIENT_ID') || ! getenv('PAYU_CLIENT_SECRET') || ! getenv('PAYU_POS_ID')) {
            $this->markTestSkipped(
                'Set PAYU_CLIENT_ID, PAYU_CLIENT_SECRET and PAYU_POS_ID in your shell to run sandbox e2e tests.'
            );
        }
    }
}
