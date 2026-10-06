<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\GpsBulgaria;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Tests\Laravel\TestCase;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

uses(TestCase::class)->in('Laravel');

function test_config(?RetryPolicy $retry = null): GpsBulgariaConfig
{
    return new GpsBulgariaConfig(apiKey: 'test-key', retry: $retry ?? RetryPolicy::none());
}

function gps(FakeHttpClient $http, ?RetryPolicy $retry = null): GpsBulgaria
{
    $factory = new HttpFactory;

    return new GpsBulgaria(test_config($retry), $http, $factory, $factory);
}

/** @param (Closure(int): void)|null $sleep */
function transport(FakeHttpClient $http, ?RetryPolicy $retry = null, ?Closure $sleep = null): Transport
{
    $factory = new HttpFactory;

    return new Transport(test_config($retry), $http, $factory, $factory, $sleep ?? static function (int $ms): void {});
}

/** @return array<mixed> */
function api_fixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__."/fixtures/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}
