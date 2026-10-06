<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Dto\ZoneInput;
use Ux2Dev\GpsBulgaria\Exception\PermissionDeniedException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('lists zones without geometry by default', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [api_fixture('zone')])]);

    $zones = gps($http)->zones()->list();

    expect($zones[0]->zoneId)->toBe('5930')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/zones?includeGeometry=false');
});

it('gets one zone with geometry', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, api_fixture('zone'))]);

    $zone = gps($http)->zones()->get('5930', includeGeometry: true);

    expect($zone->geometry)->not->toBeNull()
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/zones/5930?includeGeometry=true');
});

it('searches zones by id with a JSON body', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [api_fixture('zone')])]);

    gps($http)->zones()->search(['5930', '14700']);

    $r = $http->captured[0];
    expect($r->getMethod())->toBe('POST')
        ->and((string) $r->getUri())->toBe('https://iot.gps.bg/api/v2/zones/search?includeGeometry=false')
        ->and((string) $r->getBody())->toBe('{"zoneIDs":["5930","14700"]}');
});

it('rejects an empty search before sending', function () {
    $http = new FakeHttpClient;

    expect(fn () => gps($http)->zones()->search([]))->toThrow(InvalidArgumentException::class, 'zoneIds must not be empty');
    expect($http->captured)->toBe([]);
});

it('rejects an empty zone id before sending', function () {
    $http = new FakeHttpClient;

    expect(fn () => gps($http)->zones()->get(''))->toThrow(InvalidArgumentException::class, 'zoneId must not be empty');
    expect($http->captured)->toBe([]);
});

it('creates a zone from a ZoneInput', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(201, api_fixture('zone'))]);

    $zone = gps($http)->zones()->create(ZoneInput::circle('Depot', 42.6977, 23.3219, 250)->withColor('#ff0000')->onMap());

    $r = $http->captured[0];
    expect($zone->zoneId)->toBe('5930')
        ->and($r->getMethod())->toBe('POST')
        ->and((string) $r->getUri())->toBe('https://iot.gps.bg/api/v2/zones')
        ->and(json_decode((string) $r->getBody(), true))->toBe([
            'zoneType' => 'circle',
            'geometry' => ['type' => 'Point', 'coordinates' => [23.3219, 42.6977]],
            'name' => 'Depot',
            'color' => '#ff0000',
            'radius' => 250.0,
            'onMap' => true,
        ])
        ->and((string) $r->getBody())->toContain('"radius":250.0');
});

it('surfaces a 403 on create', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(403, ['code' => 'FORBIDDEN', 'message' => 'not permitted to create zones'])]);

    expect(fn () => gps($http)->zones()->create(ZoneInput::circle('A', 42, 23, 10)))
        ->toThrow(PermissionDeniedException::class, 'not permitted to create zones');
});

it('does not retry create even with a retry policy', function () {
    $http = new FakeHttpClient([
        FakeHttpClient::json(503, ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'x']),
        FakeHttpClient::json(201, api_fixture('zone')),
    ]);

    expect(fn () => gps($http, RetryPolicy::attempts(3))->zones()->create(ZoneInput::circle('A', 42, 23, 10)))
        ->toThrow(ServiceUnavailableException::class);
    expect($http->captured)->toHaveCount(1);
});
