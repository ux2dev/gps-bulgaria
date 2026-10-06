<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Resource\AlertsResource;
use Ux2Dev\GpsBulgaria\Resource\ObjectsResource;
use Ux2Dev\GpsBulgaria\Resource\ObjectTypesResource;
use Ux2Dev\GpsBulgaria\Resource\ZonesResource;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('exposes each resource lazily and caches it', function () {
    $gps = gps(new FakeHttpClient);

    expect($gps->objects())->toBeInstanceOf(ObjectsResource::class)->toBe($gps->objects())
        ->and($gps->objectTypes())->toBeInstanceOf(ObjectTypesResource::class)->toBe($gps->objectTypes())
        ->and($gps->zones())->toBeInstanceOf(ZonesResource::class)->toBe($gps->zones())
        ->and($gps->alerts())->toBeInstanceOf(AlertsResource::class)->toBe($gps->alerts());
});
