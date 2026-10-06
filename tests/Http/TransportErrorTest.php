<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Exception\ApiException;
use Ux2Dev\GpsBulgaria\Exception\AuthenticationException;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Exception\PermissionDeniedException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Exception\ValidationException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

function catchApi(callable $fn): ApiException
{
    try {
        $fn();
    } catch (ApiException $e) {
        return $e;
    }
    throw new RuntimeException('expected ApiException');
}

it('maps each status to its exception class with code, message and traceId', function (int $status, string $class, string $code) {
    $body = ['code' => $code, 'message' => 'msg '.$status, 'traceId' => 'tr-'.$status];
    $http = new FakeHttpClient([FakeHttpClient::json($status, $body)]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects/1'));

    expect($e)->toBeInstanceOf($class)
        ->and($e->httpStatus)->toBe($status)
        ->and($e->getCode())->toBe($status)
        ->and($e->errorCode)->toBe($code)
        ->and($e->getMessage())->toBe('msg '.$status)
        ->and($e->traceId)->toBe('tr-'.$status)
        ->and($e->body)->toBe($body);
})->with([
    [400, ValidationException::class, 'BAD_REQUEST'],
    [401, AuthenticationException::class, 'UNAUTHORIZED'],
    [403, PermissionDeniedException::class, 'FORBIDDEN'],
    [404, NotFoundException::class, 'NOT_FOUND'],
    [503, ServiceUnavailableException::class, 'SERVICE_UNAVAILABLE'],
    [500, ApiException::class, 'INTERNAL_ERROR'],
    [418, ApiException::class, 'TEAPOT'],
]);

it('handles an error body without traceId', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(404, ['code' => 'NOT_FOUND', 'message' => 'object not found'])]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects/1'));

    expect($e->traceId)->toBeNull()->and($e->errorCode)->toBe('NOT_FOUND');
});

it('still maps the status when a proxy returns an HTML error page', function () {
    $html = '<html><body>'.str_repeat('Bad Gateway ', 100).'</body></html>';
    $http = new FakeHttpClient([FakeHttpClient::raw(503, $html)]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects'));

    expect($e)->toBeInstanceOf(ServiceUnavailableException::class)
        ->and($e->errorCode)->toBeNull()
        ->and($e->body)->toBe([])
        ->and($e->getMessage())->toStartWith('HTTP 503: <html><body>Bad Gateway')
        ->and(strlen($e->getMessage()))->toBeLessThanOrEqual(strlen('HTTP 503: ') + 503);
});

it('describes an empty error body by status', function () {
    $http = new FakeHttpClient([FakeHttpClient::raw(500, '')]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects'));

    expect($e->getMessage())->toBe('HTTP 500')->and($e->body)->toBe([]);
});

it('falls back to the status description when the error message is empty', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(500, ['message' => ''])]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects'));

    expect($e->getMessage())->toBe('HTTP 500: {"message":""}');
});

it('keeps a JSON error body that lacks the documented shape', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(500, ['error' => 'boom'])]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects'));

    expect($e->getMessage())->toBe('HTTP 500: {"error":"boom"}')
        ->and($e->errorCode)->toBeNull()
        ->and($e->body)->toBe(['error' => 'boom']);
});

it('parses a numeric Retry-After header', function (string $header, ?int $expected) {
    $http = new FakeHttpClient([FakeHttpClient::json(503, ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'x'], ['Retry-After' => $header])]);

    expect(catchApi(fn () => transport($http)->request('GET', '/objects'))->retryAfterSeconds)->toBe($expected);
})->with([
    ['7', 7],
    ['Wed, 21 Oct 2026 07:28:00 GMT', null],
    ['', null],
]);
