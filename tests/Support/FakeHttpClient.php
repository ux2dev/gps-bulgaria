<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 client for tests: captures every request and replays a queue of
 * responses (or throws queued client exceptions) in order.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $captured = [];

    /** @param list<ResponseInterface|ClientExceptionInterface> $queue */
    public function __construct(private array $queue = []) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->captured[] = $request;
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('FakeHttpClient: no more responses queued');
        }

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * @param  array<mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    public static function json(int $status, array|string $body = [], array $headers = []): Response
    {
        $payload = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $payload);
    }

    /** @param array<string, string> $headers */
    public static function raw(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, $headers, $body);
    }

    public static function networkError(string $message = 'connection refused'): ClientExceptionInterface
    {
        return new class($message) extends RuntimeException implements ClientExceptionInterface {};
    }
}
