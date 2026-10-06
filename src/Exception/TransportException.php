<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

/** The HTTP request could not be completed (DNS, connection, TLS, timeout). The PSR-18 exception is available as getPrevious(). */
class TransportException extends GpsBulgariaException {}
