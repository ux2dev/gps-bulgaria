<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

/** HTTP 503: the service is temporarily unavailable; safe to retry for reads. */
class ServiceUnavailableException extends ApiException {}
