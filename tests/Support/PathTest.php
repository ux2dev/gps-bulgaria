<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Support\Path;

it('encodes a path segment', function () {
    expect(Path::segment('14574', 'objectId'))->toBe('/14574')
        ->and(Path::segment('a/b c', 'objectId'))->toBe('/a%2Fb%20c');
});

it('rejects empty and blank ids before a request is built', function (string $id) {
    expect(fn () => Path::segment($id, 'objectId'))->toThrow(InvalidArgumentException::class, 'objectId must not be empty');
})->with(['', '   ']);
