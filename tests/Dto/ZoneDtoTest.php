<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Zone;
use Ux2Dev\GpsBulgaria\Enum\GeometryType;
use Ux2Dev\GpsBulgaria\Enum\ZoneType;

it('hydrates a zone with geometry', function () {
    $z = Zone::fromArray(api_fixture('zone'));

    expect($z->zoneId)->toBe('5930')
        ->and($z->zoneType)->toBe(ZoneType::Circle)
        ->and($z->name)->toBe('Depot')
        ->and($z->color)->toBe('#ff0000')
        ->and($z->address)->toBe('1 Vitosha Blvd, Sofia')
        ->and($z->tag)->toBe('depots')
        ->and($z->onMap)->toBeTrue()
        ->and($z->geometry?->type)->toBe(GeometryType::Point)
        ->and($z->radius)->toBe(250.0)
        ->and($z->buffer)->toBeNull();
});

it('hydrates a zone listed without geometry and with null labels', function () {
    $z = Zone::fromArray([
        'zoneID' => '14700', 'zoneType' => 'polyline', 'name' => null, 'color' => null,
        'address' => null, 'tag' => null, 'onMap' => false, 'buffer' => 30,
    ]);

    expect($z->geometry)->toBeNull()
        ->and($z->name)->toBeNull()
        ->and($z->buffer)->toBe(30.0)
        ->and($z->radius)->toBeNull();
});
