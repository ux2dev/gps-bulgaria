<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Alert;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('lists alerts for all objects without a range', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [api_fixture('alert')])]);

    $alerts = gps($http)->alerts()->list();

    expect($alerts[0])->toBeInstanceOf(Alert::class)
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects/alerts');
});

it('lists alerts for one object within a range', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

    $alerts = gps($http)->alerts()->forObject('14574', new DateTimeImmutable('2026-08-01T00:00:00Z'), new DateTimeImmutable('2026-08-07T23:59:59Z'));

    $uri = $http->captured[0]->getUri();
    expect($alerts)->toBe([])
        ->and($uri->getPath())->toBe('/api/v2/objects/14574/alerts')
        ->and(urldecode($uri->getQuery()))->toBe('from=2026-08-01T00:00:00Z&to=2026-08-07T23:59:59Z');
});

it('rejects a reversed range and an empty object id', function () {
    $http = new FakeHttpClient;
    $alerts = gps($http)->alerts();

    expect(fn () => $alerts->list(new DateTimeImmutable('2026-08-07'), new DateTimeImmutable('2026-08-01')))
        ->toThrow(InvalidArgumentException::class, 'from must not be after to');
    expect(fn () => $alerts->forObject(''))->toThrow(InvalidArgumentException::class, 'objectId must not be empty');
    expect($http->captured)->toBe([]);
});
