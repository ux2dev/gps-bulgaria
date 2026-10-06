<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Route;

it('hydrates a route with points and addresses', function () {
    $r = Route::fromArray(api_fixture('route'));

    expect($r->objectId)->toBe('14574')
        ->and($r->startedAt->format(DATE_ATOM))->toBe('2026-08-01T07:10:00+00:00')
        ->and($r->endedAt?->format(DATE_ATOM))->toBe('2026-08-01T08:02:13+00:00')
        ->and($r->startAddress)->toBe('1 Vitosha Blvd, Sofia')
        ->and($r->endAddress)->toBe('25 Tsarigradsko Shose, Sofia')
        ->and($r->startPoint?->latitude)->toBe(42.6977)
        ->and($r->endPoint?->longitude)->toBe(23.3815)
        ->and($r->dataPoints)->toHaveCount(2)
        ->and($r->dataPoints[1]->eventTs)->toBeNull()
        ->and($r->dataPoints[1]->latitude)->toBe(42.0)
        ->and($r->isOpen())->toBeFalse();
});

it('exposes typed getters for every documented aggregation', function () {
    $r = Route::fromArray(api_fixture('route'));

    expect($r->mileage())->toBe(42.7)
        ->and($r->duration())->toBe(3133)
        ->and($r->durationMoving())->toBe(2900)
        ->and($r->durationIdle())->toBe(233)
        ->and($r->maxSpeed())->toBe(96.0)
        ->and($r->avgSpeed())->toBe(49.1)
        ->and($r->odometerAtStart())->toBe(46329.1)
        ->and($r->odometerAtEnd())->toBe(46371.8)
        ->and($r->fuelLevelAtStart())->toBe(61.0)
        ->and($r->fuelLevelAtEnd())->toBe(57.0);
});

it('handles a minimal open route without optional data', function () {
    $r = Route::fromArray([
        'objectID' => '14574', 'startedAt' => '2026-08-01T07:10:00Z', 'endedAt' => null, 'aggregations' => ['duration' => '12.6'],
    ]);

    expect($r->isOpen())->toBeTrue()
        ->and($r->startAddress)->toBeNull()
        ->and($r->startPoint)->toBeNull()
        ->and($r->dataPoints)->toBe([])
        ->and($r->duration())->toBe(13)
        ->and($r->mileage())->toBeNull();
});
