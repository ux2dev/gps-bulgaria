<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Http;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Exception\ApiException;
use Ux2Dev\GpsBulgaria\Exception\AuthenticationException;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Exception\PermissionDeniedException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Exception\TransportException;
use Ux2Dev\GpsBulgaria\Exception\ValidationException;

/**
 * Sends JSON requests to the GPS Bulgaria API and maps responses to arrays
 * or typed exceptions. Shared by every resource.
 */
final class Transport
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    private const EXCERPT_LENGTH = 500;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @param (Closure(int): void)|null $sleep Receives milliseconds; defaults to usleep. */
    public function __construct(
        private readonly GpsBulgariaConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Retries GET requests on 503 / transport failure per the configured
     * RetryPolicy. POST is never retried: createZone is not idempotent.
     *
     * @param  array<string, scalar|DateTimeInterface|null>  $query
     * @param  array<mixed>|null  $body
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $policy = $this->config->retry;
        $retryable = strtoupper($method) === 'GET';

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->send($method, $path, $query, $body);
            } catch (ServiceUnavailableException|TransportException $e) {
                if (! $retryable || $attempt >= $policy->maxAttempts) {
                    throw $e;
                }

                $retryAfter = $e instanceof ServiceUnavailableException ? $e->retryAfterSeconds : null;
                ($this->sleep)($policy->delayFor($attempt, $retryAfter));
            }
        }
    }

    /**
     * @param  array<string, scalar|DateTimeInterface|null>  $query
     * @param  array<mixed>|null  $body
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $query, ?array $body): array
    {
        $url = $this->config->baseUrl.$path;
        $queryString = self::encodeQuery($query);
        if ($queryString !== '') {
            $url .= '?'.$queryString;
        }

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('X-API-Key', $this->config->apiKey())
            ->withHeader('Accept', 'application/json');

        if ($body !== null) {
            try {
                $json = json_encode($body, self::JSON_FLAGS);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('Request body cannot be encoded as JSON: '.$e->getMessage(), 0, $e);
            }

            $request = $request
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody($this->streamFactory->createStream($json));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException('GPS Bulgaria request failed: '.$e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw self::apiError($status, $raw, $response->getHeaderLine('Retry-After'));
        }

        if ($raw === '') {
            throw new InvalidResponseException("Empty response body (HTTP {$status})");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidResponseException("Response is not valid JSON (HTTP {$status}): ".self::excerpt($raw), 0, $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidResponseException("Response is not a JSON object or array (HTTP {$status}): ".self::excerpt($raw));
        }

        return $decoded;
    }

    /** @param array<string, scalar|DateTimeInterface|null> $query */
    private static function encodeQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            $pairs[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z'),
                default => (string) $value,
            };
        }

        return http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    private static function apiError(int $status, string $raw, string $retryAfterHeader): ApiException
    {
        $decoded = json_decode($raw, true);
        $body = is_array($decoded) ? $decoded : [];

        $message = isset($body['message']) && is_string($body['message']) && $body['message'] !== ''
            ? $body['message']
            : ($raw === '' ? "HTTP {$status}" : "HTTP {$status}: ".self::excerpt($raw));

        $errorCode = isset($body['code']) && is_string($body['code']) ? $body['code'] : null;
        $traceId = isset($body['traceId']) && is_string($body['traceId']) ? $body['traceId'] : null;
        $retryAfter = ctype_digit($retryAfterHeader) ? (int) $retryAfterHeader : null;

        $class = match ($status) {
            400 => ValidationException::class,
            401 => AuthenticationException::class,
            403 => PermissionDeniedException::class,
            404 => NotFoundException::class,
            503 => ServiceUnavailableException::class,
            default => ApiException::class,
        };

        return new $class($message, $status, $errorCode, $traceId, $body, $retryAfter);
    }

    private static function excerpt(string $raw): string
    {
        return strlen($raw) > self::EXCERPT_LENGTH ? substr($raw, 0, self::EXCERPT_LENGTH).'...' : $raw;
    }
}
