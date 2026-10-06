<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Exception\ApiException;
use Ux2Dev\GpsBulgaria\Exception\AuthenticationException;
use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;
use Ux2Dev\GpsBulgaria\Exception\GpsBulgariaException;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Exception\PermissionDeniedException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Exception\TransportException;
use Ux2Dev\GpsBulgaria\Exception\ValidationException;

it('roots every exception in GpsBulgariaException which extends RuntimeException', function (string $class) {
    expect(is_subclass_of($class, GpsBulgariaException::class))->toBeTrue()
        ->and(is_subclass_of(GpsBulgariaException::class, RuntimeException::class))->toBeTrue();
})->with([
    ConfigurationException::class, TransportException::class, InvalidResponseException::class, ApiException::class,
]);

it('puts every status-specific exception under ApiException', function (string $class) {
    expect(is_subclass_of($class, ApiException::class))->toBeTrue();
})->with([
    ValidationException::class, AuthenticationException::class, PermissionDeniedException::class,
    NotFoundException::class, ServiceUnavailableException::class,
]);

it('keeps exception classes open for extension', function (string $class) {
    expect((new ReflectionClass($class))->isFinal())->toBeFalse();
})->with([
    GpsBulgariaException::class, ApiException::class, NotFoundException::class, TransportException::class,
]);

it('carries the API error details on ApiException', function () {
    $previous = new RuntimeException('inner');
    $e = new NotFoundException('object not found', 404, 'NOT_FOUND', 'trace-1', ['code' => 'NOT_FOUND'], null, $previous);

    expect($e->getMessage())->toBe('object not found')
        ->and($e->httpStatus)->toBe(404)
        ->and($e->errorCode)->toBe('NOT_FOUND')
        ->and($e->traceId)->toBe('trace-1')
        ->and($e->body)->toBe(['code' => 'NOT_FOUND'])
        ->and($e->retryAfterSeconds)->toBeNull()
        ->and($e->getPrevious())->toBe($previous);
});
