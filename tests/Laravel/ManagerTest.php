<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;
use Ux2Dev\GpsBulgaria\GpsBulgaria;
use Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaManager;
use Ux2Dev\GpsBulgaria\Resource\ObjectsResource;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

function manager(FakeHttpClient $http, array $config): GpsBulgariaManager
{
    $factory = new HttpFactory;

    return new GpsBulgariaManager($config, $http, $factory, $factory);
}

function tenants(): array
{
    return [
        'default' => 'main',
        'tenants' => [
            'main' => ['api_key' => 'key_main', 'timeout' => 30, 'retry' => 1],
            'other' => ['api_key' => 'key_other', 'base_url' => 'https://staging.example.test/api/v2'],
            'blank' => ['api_key' => ''],
        ],
    ];
}

it('resolves the default tenant and caches its client', function () {
    $m = manager(new FakeHttpClient, tenants());

    expect($m->currentTenant())->toBe('main')
        ->and($m->client())->toBeInstanceOf(GpsBulgaria::class)
        ->and($m->client())->toBe($m->client());
});

it('switches tenants immutably', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, []), FakeHttpClient::json(200, [])]);
    $m = manager($http, tenants());

    $other = $m->tenant('other');
    $other->objects()->list();
    $m->objects()->list();

    expect($m->currentTenant())->toBe('main')
        ->and($other->currentTenant())->toBe('other')
        ->and($http->captured[0]->getHeaderLine('X-API-Key'))->toBe('key_other')
        ->and((string) $http->captured[0]->getUri())->toBe('https://staging.example.test/api/v2/objects')
        ->and($http->captured[1]->getHeaderLine('X-API-Key'))->toBe('key_main');
});

it('forwards resource accessors to the current client', function () {
    expect(manager(new FakeHttpClient, tenants())->objects())->toBeInstanceOf(ObjectsResource::class);
});

it('rejects unknown tenants and tenants without a key', function () {
    $m = manager(new FakeHttpClient, tenants());

    expect(fn () => $m->tenant('nope')->client())->toThrow(ConfigurationException::class, 'GPS Bulgaria tenant "nope" is not configured')
        ->and(fn () => $m->tenant('blank')->client())->toThrow(ConfigurationException::class, 'apiKey must not be empty');
});

it('builds an uncached client for a runtime key, inheriting default settings', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, []), FakeHttpClient::json(200, [])]);
    $m = manager($http, tenants());

    $a = $m->forKey('customer-key');
    $b = $m->forKey('customer-key');
    $a->objects()->list();
    $m->forKey('customer-key-2', ['base_url' => 'https://staging.example.test/api/v2'])->objects()->list();

    expect($a)->not->toBe($b)
        ->and($http->captured[0]->getHeaderLine('X-API-Key'))->toBe('customer-key')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects')
        ->and($http->captured[1]->getHeaderLine('X-API-Key'))->toBe('customer-key-2')
        ->and((string) $http->captured[1]->getUri())->toBe('https://staging.example.test/api/v2/objects');
});

it('lets forKey work without any configured tenants', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

    manager($http, [])->forKey('k')->objects()->list();

    expect($http->captured[0]->getHeaderLine('X-API-Key'))->toBe('k');
});

it('rejects an empty runtime key', function () {
    expect(fn () => manager(new FakeHttpClient, tenants())->forKey(''))
        ->toThrow(ConfigurationException::class, 'apiKey must not be empty');
});

// Retry behaviour itself is covered in TransportRetryTest; this checks only the
// config wiring, so it needs no real backoff sleeps.
it('maps the tenant retry setting onto the config', function () {
    $m = manager(new FakeHttpClient, tenants());

    $config = (new ReflectionMethod($m, 'configFor'))->invoke($m, ['api_key' => 'k', 'retry' => 4, 'timeout' => 12]);

    expect($config->retry->maxAttempts)->toBe(4)->and($config->timeout)->toBe(12);
});

it('uses default timeout, retry and base URL when the settings are not usable', function () {
    $m = manager(new FakeHttpClient, ['default' => 42, 'tenants' => 'nope']);
    $config = (new ReflectionMethod($m, 'configFor'))->invoke($m, ['api_key' => 'k', 'base_url' => '', 'timeout' => 'abc', 'retry' => 'abc']);

    expect($m->currentTenant())->toBe('main')
        ->and($config->baseUrl)->toBe('https://iot.gps.bg/api/v2')
        ->and($config->timeout)->toBe(30)
        ->and($config->retry->maxAttempts)->toBe(1)
        ->and(fn () => $m->client())->toThrow(ConfigurationException::class, 'tenant "main" is not configured');
});

it('lets forKey ignore a malformed tenants entry', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);
    manager($http, ['tenants' => 'nope'])->forKey('k')->objects()->list();

    expect($http->captured[0]->getHeaderLine('X-API-Key'))->toBe('k');
});
