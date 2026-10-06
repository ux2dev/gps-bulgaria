<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Enum\ZoneType;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Support\Data;

it('reads typed scalars', function () {
    $d = ['s' => 'x', 'i' => 5, 'f' => 1.5, 'fi' => 89, 'b' => true];

    expect(Data::string($d, 's', 'T'))->toBe('x')
        ->and(Data::int($d, 'i', 'T'))->toBe(5)
        ->and(Data::float($d, 'f', 'T'))->toBe(1.5)
        ->and(Data::float($d, 'fi', 'T'))->toBe(89.0)
        ->and(Data::bool($d, 'b', 'T'))->toBeTrue()
        ->and(Data::bool([], 'missing', 'T', false))->toBeFalse();
});

it('names the DTO and field when a required value is missing', function () {
    expect(fn () => Data::string([], 'name', 'GpsObject'))
        ->toThrow(InvalidResponseException::class, "GpsObject: missing required field 'name'");
});

it('names the expected type when a value has the wrong type', function (callable $fn, string $message) {
    expect($fn)->toThrow(InvalidResponseException::class, $message);
})->with([
    [fn () => Data::string(['k' => 1], 'k', 'T'), "T: field 'k' must be string, got int"],
    [fn () => Data::int(['k' => '1'], 'k', 'T'), "T: field 'k' must be int, got string"],
    [fn () => Data::float(['k' => '1.5'], 'k', 'T'), "T: field 'k' must be number, got string"],
    [fn () => Data::bool(['k' => 'true'], 'k', 'T'), "T: field 'k' must be bool, got string"],
    [fn () => Data::nullableString(['k' => []], 'k', 'T'), "T: field 'k' must be string, got array"],
]);

it('treats missing and null nullable values as null', function () {
    expect(Data::nullableString([], 'k', 'T'))->toBeNull()
        ->and(Data::nullableString(['k' => null], 'k', 'T'))->toBeNull()
        ->and(Data::nullableFloat(['k' => null], 'k', 'T'))->toBeNull()
        ->and(Data::nullableFloat(['k' => 2], 'k', 'T'))->toBe(2.0)
        ->and(Data::nullableDateTime([], 'k', 'T'))->toBeNull()
        ->and(Data::nullableObject(['k' => null], 'k', 'T'))->toBeNull();
});

it('parses RFC 3339 date-times into UTC', function (string $input, string $expected) {
    $dt = Data::dateTime(['t' => $input], 't', 'T');

    expect($dt->format('Y-m-d\TH:i:s.u P'))->toBe($expected)
        ->and($dt->getTimezone()->getName())->toBe('UTC');
})->with([
    ['2026-09-09T14:44:38Z', '2026-09-09T14:44:38.000000 +00:00'],
    ['2026-09-09T17:44:38+03:00', '2026-09-09T14:44:38.000000 +00:00'],
    ['2026-09-09T14:44:38.250Z', '2026-09-09T14:44:38.250000 +00:00'],
]);

it('rejects non-RFC 3339 date-times', function (string $input) {
    expect(fn () => Data::dateTime(['t' => $input], 't', 'Route'))
        ->toThrow(InvalidResponseException::class, "Route: field 't' is not an RFC 3339 date-time");
})->with(['now', '2026-09-09', '2026-09-09 14:44:38', 'tomorrow 10:00']);

it('reads string maps and lists', function () {
    $d = ['m' => ['speed' => '0', 'km' => '1.5'], 'empty' => [], 'l' => ['a', 'b']];

    expect(Data::stringMap($d, 'm', 'T'))->toBe(['speed' => '0', 'km' => '1.5'])
        ->and(Data::stringMap($d, 'empty', 'T'))->toBe([])
        ->and(Data::stringList($d, 'l', 'T'))->toBe(['a', 'b'])
        ->and(Data::stringList([], 'l', 'T', required: false))->toBe([]);

    expect(fn () => Data::stringMap(['m' => ['speed' => 0]], 'm', 'T'))
        ->toThrow(InvalidResponseException::class, "T: field 'm' must be a map of strings");
    expect(fn () => Data::stringList(['l' => ['a', 1]], 'l', 'T'))
        ->toThrow(InvalidResponseException::class, "T: field 'l' must be a list of strings");
});

it('maps nested object lists', function () {
    $out = Data::objects(['p' => [['v' => 1], ['v' => 2]]], 'p', 'T', fn (array $row) => $row['v']);

    expect($out)->toBe([1, 2])
        ->and(Data::objects([], 'p', 'T', fn (array $r) => $r, required: false))->toBe([]);

    expect(fn () => Data::objects(['p' => ['x']], 'p', 'T', fn (array $r) => $r))
        ->toThrow(InvalidResponseException::class, "T: field 'p' must be a list of objects");
});

it('reads enums strictly', function () {
    expect(Data::enum(ZoneType::class, ['z' => 'circle'], 'z', 'Zone'))->toBe(ZoneType::Circle);

    expect(fn () => Data::enum(ZoneType::class, ['z' => 'hexagon'], 'z', 'Zone'))
        ->toThrow(InvalidResponseException::class, "Zone: field 'z' has unknown ZoneType value 'hexagon'");
});

it('validates bare JSON arrays of objects', function () {
    expect(Data::rows([['a' => 1], ['a' => 2]], 'GpsObject'))->toBe([['a' => 1], ['a' => 2]])
        ->and(Data::rows([], 'GpsObject'))->toBe([]);

    expect(fn () => Data::rows(['a' => 1], 'GpsObject'))
        ->toThrow(InvalidResponseException::class, 'GpsObject: expected a JSON array of objects');
    expect(fn () => Data::rows([1, 2], 'GpsObject'))
        ->toThrow(InvalidResponseException::class, 'GpsObject: expected a JSON array of objects');
});
