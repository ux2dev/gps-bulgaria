<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Tests\Laravel;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Ux2Dev\GpsBulgaria\Laravel\Facades\GpsBulgaria;
use Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [GpsBulgariaServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['GpsBulgaria' => GpsBulgaria::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('gps-bulgaria.default', 'main');
        $app['config']->set('gps-bulgaria.tenants.main', [
            'api_key' => 'key_main',
            'base_url' => 'https://iot.gps.bg/api/v2',
            'timeout' => 30,
            'retry' => 3,
        ]);
        $app['config']->set('gps-bulgaria.tenants.other', [
            'api_key' => 'key_other',
            'base_url' => 'https://staging.example.test/api/v2',
            'timeout' => 10,
            'retry' => 1,
        ]);
    }
}
