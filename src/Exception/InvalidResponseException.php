<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

/** The API answered 2xx but the body was empty, not JSON, or did not match the documented schema. */
class InvalidResponseException extends GpsBulgariaException {}
