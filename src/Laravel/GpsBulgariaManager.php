<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;
use Ux2Dev\GpsBulgaria\GpsBulgaria;

/**
 * Laravel integration. Resolves tenants from `config/gps-bulgaria.php`,
 * caches one client per tenant, and builds uncached clients for runtime
 * keys via forKey().
 *
 * @mixin GpsBulgaria
 */
final class GpsBulgariaManager
{
    /**
     * Shared by reference with tenant() clones, so each tenant is built once.
     *
     * @var \ArrayObject<string, GpsBulgaria>
     */
    private readonly \ArrayObject $instances;

    private string $currentTenant;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?RequestFactoryInterface $requestFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
    ) {
        /** @var \ArrayObject<string, GpsBulgaria> $instances */
        $instances = new \ArrayObject;
        $this->instances = $instances;
        $default = $config['default'] ?? 'main';
        $this->currentTenant = is_string($default) ? $default : 'main';
    }

    public function tenant(string $name): self
    {
        $clone = clone $this;
        $clone->currentTenant = $name;

        return $clone;
    }

    public function currentTenant(): string
    {
        return $this->currentTenant;
    }

    public function client(): GpsBulgaria
    {
        if (! isset($this->instances[$this->currentTenant])) {
            $this->instances[$this->currentTenant] = $this->make($this->configFor($this->tenantConfig($this->currentTenant)));
        }

        return $this->instances[$this->currentTenant];
    }

    /**
     * A client for a key known only at runtime (e.g. a customer's key from
     * the database). Inherits base_url/timeout/retry from the default tenant;
     * $overrides wins. Never cached, so keys never leak between customers
     * and rotations apply immediately in long-running workers.
     *
     * @param  array<string, mixed>  $overrides  Keys: base_url, timeout, retry.
     */
    public function forKey(#[\SensitiveParameter] string $apiKey, array $overrides = []): GpsBulgaria
    {
        $tenants = $this->config['tenants'] ?? [];
        $default = $this->config['default'] ?? 'main';
        $base = is_array($tenants) && is_string($default) && is_array($tenants[$default] ?? null) ? $tenants[$default] : [];

        /** @var array<string, mixed> $base */
        return $this->make($this->configFor(array_merge($base, $overrides, ['api_key' => $apiKey])));
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->client()->{$method}(...$arguments);
    }

    /** @return array<string, mixed> */
    private function tenantConfig(string $tenant): array
    {
        $tenants = $this->config['tenants'] ?? [];

        if (! is_array($tenants) || ! is_array($tenants[$tenant] ?? null)) {
            throw new ConfigurationException("GPS Bulgaria tenant \"{$tenant}\" is not configured");
        }

        /** @var array<string, mixed> */
        return $tenants[$tenant];
    }

    /** @param array<string, mixed> $c */
    private function configFor(array $c): GpsBulgariaConfig
    {
        $apiKey = $c['api_key'] ?? '';
        $baseUrl = $c['base_url'] ?? null;
        $timeout = $c['timeout'] ?? 30;
        $retry = $c['retry'] ?? 1;

        return new GpsBulgariaConfig(
            apiKey: is_string($apiKey) ? $apiKey : '',
            baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : GpsBulgariaConfig::DEFAULT_BASE_URL,
            timeout: is_numeric($timeout) ? (int) $timeout : 30,
            retry: RetryPolicy::attempts(is_numeric($retry) ? max(1, (int) $retry) : 1),
        );
    }

    private function make(GpsBulgariaConfig $config): GpsBulgaria
    {
        $httpClient = $this->httpClient ?? (class_exists(Client::class) ? new Client(['timeout' => $config->timeout]) : null);
        $factory = class_exists(HttpFactory::class) ? new HttpFactory : null;
        $requestFactory = $this->requestFactory ?? $factory;
        $streamFactory = $this->streamFactory ?? $factory;

        // Unreachable while guzzlehttp/guzzle is installed (it is a dev dependency).
        // @codeCoverageIgnoreStart
        if ($httpClient === null || $requestFactory === null || $streamFactory === null) {
            throw new ConfigurationException(
                'No PSR-18 client / PSR-17 factories available: install guzzlehttp/guzzle or bind them in the container',
            );
        }
        // @codeCoverageIgnoreEnd

        return new GpsBulgaria($config, $httpClient, $requestFactory, $streamFactory);
    }
}
