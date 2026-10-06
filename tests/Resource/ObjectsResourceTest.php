<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\GpsObject;
use Ux2Dev\GpsBulgaria\Dto\ObjectStatus;
use Ux2Dev\GpsBulgaria\Dto\Route;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('lists objects', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [api_fixture('object')])]);

    $objects = gps($http)->objects()->list();

    expect($objects)->toHaveCount(1)
        ->and($objects[0])->toBeInstanceOf(GpsObject::class)
        ->and($http->captured[0]->getMethod())->toBe('GET')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects');
});

it('gets one object with an encoded id', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, api_fixture('object'))]);

    $object = gps($http)->objects()->get('14574');

    expect($object->objectId)->toBe('14574')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects/14574');
});

it('surfaces a 404 as NotFoundException', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(404, ['code' => 'NOT_FOUND', 'message' => 'object not found'])]);

    expect(fn () => gps($http)->objects()->get('999'))->toThrow(NotFoundException::class, 'object not found');
});

it('lists statuses and gets one status', function () {
    $http = new FakeHttpClient([
        FakeHttpClient::json(200, [api_fixture('object-status')]),
        FakeHttpClient::json(200, api_fixture('object-status')),
    ]);
    $objects = gps($http)->objects();

    expect($objects->statuses()[0])->toBeInstanceOf(ObjectStatus::class)
        ->and($objects->status('14574')->objectId)->toBe('14574')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects/statuses')
        ->and((string) $http->captured[1]->getUri())->toBe('https://iot.gps.bg/api/v2/objects/14574/status');
});

it('lists routes with a UTC time range and flags', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [api_fixture('route')])]);
    $from = new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('Europe/Sofia'));
    $to = new DateTimeImmutable('2026-08-07T23:59:59Z');

    $routes = gps($http)->objects()->routes('14574', $from, $to, includeAddresses: true);

    $uri = $http->captured[0]->getUri();
    expect($routes[0])->toBeInstanceOf(Route::class)
        ->and($uri->getPath())->toBe('/api/v2/objects/14574/routes')
        ->and(urldecode($uri->getQuery()))
        ->toBe('from=2026-07-31T21:00:00Z&to=2026-08-07T23:59:59Z&includeAddresses=true&includePoints=false');
});

it('rejects a reversed time range before sending', function () {
    $http = new FakeHttpClient;

    expect(fn () => gps($http)->objects()->routes('14574', new DateTimeImmutable('2026-08-07'), new DateTimeImmutable('2026-08-01')))
        ->toThrow(InvalidArgumentException::class, 'from must not be after to');
    expect($http->captured)->toBe([]);
});

it('rejects empty ids before sending', function (callable $call) {
    $http = new FakeHttpClient;

    expect(fn () => $call(gps($http)->objects()))->toThrow(InvalidArgumentException::class, 'objectId must not be empty');
    expect($http->captured)->toBe([]);
})->with([
    'get' => [fn ($o) => $o->get('')],
    'status' => [fn ($o) => $o->status(' ')],
    'routes' => [fn ($o) => $o->routes('', new DateTimeImmutable, new DateTimeImmutable)],
]);

it('rejects an object response where a list was expected', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, api_fixture('object'))]);

    expect(fn () => gps($http)->objects()->list())
        ->toThrow(InvalidResponseException::class, 'GpsObject: expected a JSON array of objects');
});
