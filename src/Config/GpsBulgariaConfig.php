<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Config;

use LogicException;
use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;

final readonly class GpsBulgariaConfig
{
    public const DEFAULT_BASE_URL = 'https://iot.gps.bg/api/v2';

    public string $baseUrl;

    public function __construct(
        #[\SensitiveParameter] private string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        public int $timeout = 30,
        public RetryPolicy $retry = new RetryPolicy,
    ) {
        if (trim($apiKey) === '') {
            throw new ConfigurationException('apiKey must not be empty');
        }

        if (! preg_match('~^https://~i', $baseUrl)) {
            throw new ConfigurationException('baseUrl must start with https://');
        }

        if ($timeout < 1) {
            throw new ConfigurationException('timeout must be at least 1 second');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[REDACTED]',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'retry' => $this->retry,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('GpsBulgariaConfig must not be serialized as it contains an API key');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('GpsBulgariaConfig must not be unserialized');
    }
}
