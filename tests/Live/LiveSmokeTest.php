<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Dto\GpsObject;
use Ux2Dev\GpsBulgaria\GpsBulgaria;

/*
 * Read-only calls against the real API. Never creates or mutates anything.
 * Run with: GPS_BULGARIA_LIVE_KEY=... vendor/bin/pest --group=live
 */

function live(): GpsBulgaria
{
    $key = getenv('GPS_BULGARIA_LIVE_KEY');
    $factory = new HttpFactory;

    return new GpsBulgaria(
        new GpsBulgariaConfig((string) $key, retry: RetryPolicy::attempts(3)),
        new Client(['timeout' => 30]),
        $factory,
        $factory,
    );
}

beforeEach(function () {
    if (! getenv('GPS_BULGARIA_LIVE_KEY')) {
        $this->markTestSkipped('GPS_BULGARIA_LIVE_KEY is not set');
    }
});

it('lists objects and fetches the first one', function () {
    $objects = live()->objects()->list();
    expect($objects)->each->toBeInstanceOf(GpsObject::class);

    if ($objects !== []) {
        expect(live()->objects()->get($objects[0]->objectId)->objectId)->toBe($objects[0]->objectId);
    }
})->group('live');

it('lists object types and statuses', function () {
    expect(live()->objectTypes()->list())->toBeArray()
        ->and(live()->objects()->statuses())->toBeArray();
})->group('live');

it('lists the last day of routes for the first object', function () {
    $objects = live()->objects()->list();
    if ($objects === []) {
        $this->markTestSkipped('account has no objects');
    }

    $to = new DateTimeImmutable;
    expect(live()->objects()->routes($objects[0]->objectId, $to->modify('-1 day'), $to))->toBeArray();
})->group('live');

it('lists zones with geometry', function () {
    expect(live()->zones()->list(includeGeometry: true))->toBeArray();
})->group('live');
