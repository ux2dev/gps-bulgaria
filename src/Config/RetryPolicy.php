<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Config;

use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;

/**
 * How many times a GET request is attempted on 503 / transport failure, and
 * how long to wait between attempts (exponential backoff with full jitter).
 * POST requests are never retried regardless of this policy.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 1,
        public int $baseDelayMs = 200,
        public int $maxDelayMs = 2000,
    ) {
        if ($maxAttempts < 1) {
            throw new ConfigurationException('maxAttempts must be at least 1');
        }

        if ($baseDelayMs < 0 || $maxDelayMs < 0) {
            throw new ConfigurationException('retry delays must not be negative');
        }

        if ($baseDelayMs > $maxDelayMs) {
            throw new ConfigurationException('baseDelayMs must not exceed maxDelayMs');
        }
    }

    public static function none(): self
    {
        return new self;
    }

    public static function attempts(int $maxAttempts): self
    {
        return new self(maxAttempts: $maxAttempts);
    }

    /**
     * Milliseconds to wait before retry number $retry (1 = first retry).
     * A server-sent Retry-After (seconds) replaces the computed delay.
     */
    public function delayFor(int $retry, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null) {
            return min($this->maxDelayMs, max(0, $retryAfterSeconds) * 1000);
        }

        $exponent = min(max($retry - 1, 0), 30);
        $cap = (int) min($this->maxDelayMs, $this->baseDelayMs * (2 ** $exponent));

        return random_int(0, $cap);
    }
}
