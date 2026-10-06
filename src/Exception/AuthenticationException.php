<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

/** HTTP 401: X-API-Key is missing, not recognised, revoked or expired. */
class AuthenticationException extends ApiException {}
