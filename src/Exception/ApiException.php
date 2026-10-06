<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

use Throwable;

/**
 * The API answered with a non-2xx status. Catch this to handle every API
 * error; catch a subclass (NotFoundException, …) to handle one status.
 */
class ApiException extends GpsBulgariaException
{
    /**
     * @param  string|null  $errorCode  Stable API code: BAD_REQUEST, UNAUTHORIZED, FORBIDDEN, NOT_FOUND,
     *                                  INTERNAL_ERROR, SERVICE_UNAVAILABLE, or a future value as-is.
     * @param  array<mixed>  $body  Decoded error body, or [] when it was not JSON.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?string $errorCode = null,
        public readonly ?string $traceId = null,
        public readonly array $body = [],
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
