<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\ObjectStatus;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

it('hydrates a status from the spec example', function () {
    $s = ObjectStatus::fromArray(api_fixture('object-status'));

    expect($s->objectId)->toBe('14574')
        ->and($s->objectName)->toBe('Renault Clio')
        ->and($s->lastUpdate?->format(DATE_ATOM))->toBe('2026-09-09T14:44:38+00:00')
        ->and($s->location?->latitude)->toBe(42.691143)
        ->and($s->location?->longitude)->toBe(23.352829)
        ->and($s->location?->angle)->toBe(89.0)
        ->and($s->sensorData['road_topology'])->toBe('urban')
        ->and($s->hasReported())->toBeTrue();
});

it('exposes sensor helpers', function () {
    $s = ObjectStatus::fromArray(api_fixture('object-status'));

    expect($s->sensor('key'))->toBe('key_off')
        ->and($s->sensor('missing'))->toBeNull()
        ->and($s->speed())->toBe(0.0)
        ->and($s->odometer())->toBe(46371.8);
});

it('handles an object that has never reported', function () {
    $s = ObjectStatus::fromArray([
        'objectID' => '1', 'objectName' => 'New unit', 'lastUpdate' => null, 'location' => null, 'sensorData' => [],
    ]);

    expect($s->hasReported())->toBeFalse()
        ->and($s->location)->toBeNull()
        ->and($s->speed())->toBeNull()
        ->and($s->odometer())->toBeNull();
});

it('returns null from numeric helpers for non-numeric readings', function () {
    $data = api_fixture('object-status');
    $data['sensorData']['speed'] = 'n/a';

    expect(ObjectStatus::fromArray($data)->speed())->toBeNull();
});

it('rejects non-string sensor values', function () {
    $data = api_fixture('object-status');
    $data['sensorData']['speed'] = 0;

    expect(fn () => ObjectStatus::fromArray($data))
        ->toThrow(InvalidResponseException::class, "ObjectStatus: field 'sensorData' must be a map of strings");
});
