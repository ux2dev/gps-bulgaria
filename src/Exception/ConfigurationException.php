<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

/** Invalid SDK configuration (empty API key, non-https base URL, unknown tenant, …). */
class ConfigurationException extends GpsBulgariaException {}
