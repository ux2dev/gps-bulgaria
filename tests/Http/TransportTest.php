<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Exception\TransportException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('sends a GET with the API key and JSON accept header', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [['a' => 1]])]);

    $result = transport($http)->request('GET', '/objects');

    $r = $http->captured[0];
    expect($result)->toBe([['a' => 1]])
        ->and($r->getMethod())->toBe('GET')
        ->and((string) $r->getUri())->toBe('https://iot.gps.bg/api/v2/objects')
        ->and($r->getHeaderLine('X-API-Key'))->toBe('test-key')
        ->and($r->getHeaderLine('Accept'))->toBe('application/json')
        ->and($r->hasHeader('Content-Type'))->toBeFalse()
        ->and((string) $r->getBody())->toBe('');
});

it('encodes booleans, drops nulls and keeps strings in the query', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

    transport($http)->request('GET', '/zones', ['includeGeometry' => true, 'other' => false, 'skip' => null, 's' => 'a b']);

    expect($http->captured[0]->getUri()->getQuery())->toBe('includeGeometry=true&other=false&s=a%20b');
});

it('sends DateTime query values as the same instant in UTC', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);
    $sofiaMidnight = new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('Europe/Sofia'));

    transport($http)->request('GET', '/x', ['from' => $sofiaMidnight]);

    expect(urldecode($http->captured[0]->getUri()->getQuery()))->toBe('from=2026-07-31T21:00:00Z');
});

it('sends a JSON body that preserves zero fractions and unicode', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(201, ['ok' => true])]);

    transport($http)->request('POST', '/zones', [], ['radius' => 250.0, 'name' => 'Склад', 'url' => 'a/b']);

    $r = $http->captured[0];
    expect($r->getMethod())->toBe('POST')
        ->and($r->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and((string) $r->getBody())->toBe('{"radius":250.0,"name":"Склад","url":"a/b"}');
});

it('rejects empty, non-JSON and scalar success bodies', function (string $body, string $message) {
    $http = new FakeHttpClient([FakeHttpClient::raw(200, $body)]);

    expect(fn () => transport($http)->request('GET', '/objects'))
        ->toThrow(InvalidResponseException::class, $message);
})->with([
    'empty' => ['', 'Empty response body (HTTP 200)'],
    'html' => ['<html>oops</html>', 'Response is not valid JSON (HTTP 200)'],
    'scalar' => ['"hello"', 'Response is not a JSON object or array (HTTP 200)'],
]);

it('wraps PSR-18 failures in TransportException with the original as previous', function () {
    $inner = FakeHttpClient::networkError('connection refused');
    $http = new FakeHttpClient([$inner]);

    try {
        transport($http)->request('GET', '/objects');
        $this->fail('expected TransportException');
    } catch (TransportException $e) {
        expect($e->getMessage())->toBe('GPS Bulgaria request failed: connection refused')
            ->and($e->getPrevious())->toBe($inner);
    }
});
