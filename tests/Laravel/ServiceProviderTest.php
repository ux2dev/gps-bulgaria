<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Ux2Dev\GpsBulgaria\GpsBulgaria;
use Ux2Dev\GpsBulgaria\Laravel\Facades\GpsBulgaria as GpsBulgariaFacade;
use Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaManager;
use Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaServiceProvider;
use Ux2Dev\GpsBulgaria\Resource\ZonesResource;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('registers the manager as a singleton with an alias', function () {
    expect(app(GpsBulgariaManager::class))->toBe(app('gps-bulgaria'))
        ->and(app(GpsBulgariaManager::class))->toBe(app(GpsBulgariaManager::class));
});

it('merges the package config', function () {
    expect(config('gps-bulgaria.tenants.main.api_key'))->toBe('key_main');
});

it('builds a client from config through the facade', function () {
    expect(GpsBulgariaFacade::client())->toBeInstanceOf(GpsBulgaria::class)
        ->and(GpsBulgariaFacade::zones())->toBeInstanceOf(ZonesResource::class)
        ->and(GpsBulgariaFacade::tenant('other')->currentTenant())->toBe('other')
        ->and(GpsBulgariaFacade::forKey('runtime'))->toBeInstanceOf(GpsBulgaria::class);
});

it('binds the PSR-17 factories when guzzle is installed', function () {
    expect(app()->bound(RequestFactoryInterface::class))->toBeTrue();
});

it('publishes the config file under the gps-bulgaria-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(
        GpsBulgariaServiceProvider::class,
        'gps-bulgaria-config',
    );

    expect(array_values($paths))->toBe([config_path('gps-bulgaria.php')]);
});

it('uses a PSR-18 client bound in the container', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);
    app()->instance(ClientInterface::class, $http);
    app()->forgetInstance(GpsBulgariaManager::class);

    GpsBulgariaFacade::clearResolvedInstances();
    GpsBulgariaFacade::objects()->list();

    expect($http->captured)->toHaveCount(1)
        ->and($http->captured[0]->getHeaderLine('X-API-Key'))->toBe('key_main');
});
