<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Exception\TransportException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

function unavailable(array $headers = []): Response
{
    return FakeHttpClient::json(503, ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'service temporarily unavailable'], $headers);
}

it('does not retry by default', function () {
    $http = new FakeHttpClient([unavailable(), FakeHttpClient::json(200, [])]);

    expect(fn () => transport($http)->request('GET', '/objects'))->toThrow(ServiceUnavailableException::class);
    expect($http->captured)->toHaveCount(1);
});

it('retries a GET on 503 and transport errors until it succeeds', function () {
    $sleeps = [];
    $http = new FakeHttpClient([unavailable(), FakeHttpClient::networkError(), FakeHttpClient::json(200, [['ok' => 1]])]);

    $result = transport($http, new RetryPolicy(3, 100, 1000), function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    })->request('GET', '/objects');

    expect($result)->toBe([['ok' => 1]])
        ->and($http->captured)->toHaveCount(3)
        ->and($sleeps)->toHaveCount(2)
        ->and($sleeps[0])->toBeLessThanOrEqual(100)
        ->and($sleeps[1])->toBeLessThanOrEqual(200);
});

it('rethrows the last exception once attempts are exhausted', function () {
    $http = new FakeHttpClient([unavailable(), FakeHttpClient::networkError('timed out')]);

    expect(fn () => transport($http, RetryPolicy::attempts(2))->request('GET', '/objects'))
        ->toThrow(TransportException::class, 'GPS Bulgaria request failed: timed out');
    expect($http->captured)->toHaveCount(2);
});

it('never retries a POST', function () {
    $http = new FakeHttpClient([unavailable(), FakeHttpClient::json(201, [])]);

    expect(fn () => transport($http, RetryPolicy::attempts(3))->request('POST', '/zones', [], ['zoneType' => 'circle']))
        ->toThrow(ServiceUnavailableException::class);
    expect($http->captured)->toHaveCount(1);
});

it('never retries other API errors', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(404, ['code' => 'NOT_FOUND', 'message' => 'object not found'])]);

    expect(fn () => transport($http, RetryPolicy::attempts(3))->request('GET', '/objects/1'))
        ->toThrow(NotFoundException::class);
    expect($http->captured)->toHaveCount(1);
});

it('waits for Retry-After when the server sends one', function () {
    $sleeps = [];
    $http = new FakeHttpClient([unavailable(['Retry-After' => '1']), FakeHttpClient::json(200, [])]);

    transport($http, new RetryPolicy(2, 100, 5000), function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    })->request('GET', '/objects');

    expect($sleeps)->toBe([1000]);
});
