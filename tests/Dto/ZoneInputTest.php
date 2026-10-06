<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\ZoneInput;

it('builds a circle matching the spec example', function () {
    $input = ZoneInput::circle('Depot', lat: 42.6977, lng: 23.3219, radius: 250)->withColor('#ff0000')->onMap();

    expect($input->toArray())->toBe([
        'zoneType' => 'circle',
        'geometry' => ['type' => 'Point', 'coordinates' => [23.3219, 42.6977]],
        'name' => 'Depot',
        'color' => '#ff0000',
        'radius' => 250.0,
        'onMap' => true,
    ]);
});

it('builds a closed polygon matching the spec example', function () {
    $input = ZoneInput::polygon('Yard', [[42.69, 23.32], [42.69, 23.33], [42.7, 23.33], [42.7, 23.32]]);

    expect($input->toArray())->toBe([
        'zoneType' => 'polygon',
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[23.32, 42.69], [23.33, 42.69], [23.33, 42.7], [23.32, 42.7], [23.32, 42.69]]]],
        'name' => 'Yard',
        'onMap' => false,
    ]);
});

it('builds a rectangle from two corners', function () {
    $input = ZoneInput::rectangle(null, southWest: [42.69, 23.32], northEast: [42.70, 23.33]);

    expect($input->toArray())->toBe([
        'zoneType' => 'rectangle',
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[23.32, 42.69], [23.33, 42.69], [23.33, 42.70], [23.32, 42.70], [23.32, 42.69]]]],
        'onMap' => false,
    ]);
});

it('builds a polyline with a buffer and optional labels', function () {
    $input = ZoneInput::polyline('Route A', [[42.69, 23.32], [42.70, 23.33]], buffer: 30)
        ->withAddress('Ring road')->withTag('routes')->onMap(false);

    expect($input->toArray())->toBe([
        'zoneType' => 'polyline',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[23.32, 42.69], [23.33, 42.70]]],
        'name' => 'Route A',
        'address' => 'Ring road',
        'tag' => 'routes',
        'buffer' => 30.0,
        'onMap' => false,
    ]);
});

it('is immutable', function () {
    $a = ZoneInput::circle('A', 42, 23, 10);
    $b = $a->withColor('#000000');

    expect($a->toArray())->not->toHaveKey('color')
        ->and($b->toArray()['color'])->toBe('#000000');
});

it('rejects invalid shapes', function (callable $fn, string $message) {
    expect($fn)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero radius' => [fn () => ZoneInput::circle('A', 42, 23, 0), 'radius must be greater than 0'],
    'negative buffer' => [fn () => ZoneInput::polyline('A', [[42, 23], [42, 24]], -1), 'buffer must not be negative'],
    'inverted rectangle' => [fn () => ZoneInput::rectangle('A', [42.7, 23.33], [42.69, 23.32]), 'southWest must be south-west of northEast'],
]);
