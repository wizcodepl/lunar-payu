<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use WizcodePl\LunarPayu\LunarPayuServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
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
    }
}
