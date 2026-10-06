<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\ObjectType;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('lists object types', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [api_fixture('object-type')])]);

    $types = gps($http)->objectTypes()->list();

    expect($types[0])->toBeInstanceOf(ObjectType::class)
        ->and($types[0]->name)->toBe('Vehicle')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/object-types');
});
