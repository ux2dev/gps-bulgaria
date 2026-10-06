<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Geometry;
use Ux2Dev\GpsBulgaria\Enum\GeometryType;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

it('builds a point with latitude first and emits GeoJSON order', function () {
    expect(Geometry::point(42.6977, 23.3219)->toArray())
        ->toBe(['type' => 'Point', 'coordinates' => [23.3219, 42.6977]]);
});

it('builds a line string', function () {
    expect(Geometry::lineString([[42.69, 23.32], [42.70, 23.33]])->toArray())
        ->toBe(['type' => 'LineString', 'coordinates' => [[23.32, 42.69], [23.33, 42.70]]]);
});

it('closes polygon rings automatically', function () {
    $open = Geometry::polygon([[42.69, 23.32], [42.69, 23.33], [42.70, 23.33]]);
    $closed = Geometry::polygon([[42.69, 23.32], [42.69, 23.33], [42.70, 23.33], [42.69, 23.32]]);

    $ring = [[23.32, 42.69], [23.33, 42.69], [23.33, 42.70], [23.32, 42.69]];
    expect($open->toArray())->toBe(['type' => 'Polygon', 'coordinates' => [$ring]])
        ->and($closed->toArray())->toBe(['type' => 'Polygon', 'coordinates' => [$ring]]);
});

it('rejects invalid factory input', function (callable $fn, string $message) {
    expect($fn)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'lat out of range' => [fn () => Geometry::point(91, 23), 'latitude must be between -90 and 90'],
    'lng out of range' => [fn () => Geometry::point(42, 181), 'longitude must be between -180 and 180'],
    'short line' => [fn () => Geometry::lineString([[42, 23]]), 'a line string needs at least 2 positions'],
    'degenerate polygon' => [fn () => Geometry::polygon([[42, 23], [42, 24], [42, 23]]), 'a polygon needs at least 3 distinct positions'],
    'bad position' => [fn () => Geometry::lineString([[42, 23], [42]]), 'each position must be [latitude, longitude]'],
]);

it('round-trips GeoJSON from the API', function () {
    $g = Geometry::fromArray(['type' => 'LineString', 'coordinates' => [[23.32, 42.69], [23.33, 42.70]]]);

    expect($g->type)->toBe(GeometryType::LineString)
        ->and($g->toArray())->toBe(['type' => 'LineString', 'coordinates' => [[23.32, 42.69], [23.33, 42.70]]]);
});

it('rejects unknown geometry types from the API', function () {
    expect(fn () => Geometry::fromArray(['type' => 'MultiPolygon', 'coordinates' => []]))
        ->toThrow(InvalidResponseException::class, "Geometry: field 'type' has unknown GeometryType value 'MultiPolygon'");
});
