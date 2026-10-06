<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;

it('has named constructors', function () {
    expect(RetryPolicy::none()->maxAttempts)->toBe(1)
        ->and(RetryPolicy::attempts(4)->maxAttempts)->toBe(4)
        ->and(RetryPolicy::attempts(4)->baseDelayMs)->toBe(200)
        ->and(RetryPolicy::attempts(4)->maxDelayMs)->toBe(2000);
});

it('rejects invalid values', function (array $args) {
    expect(fn () => new RetryPolicy(...$args))->toThrow(ConfigurationException::class);
})->with([
    'zero attempts' => [['maxAttempts' => 0]],
    'negative base' => [['baseDelayMs' => -1]],
    'negative max' => [['baseDelayMs' => 0, 'maxDelayMs' => -1]],
    'base above max' => [['baseDelayMs' => 500, 'maxDelayMs' => 100]],
]);

it('keeps full-jitter delays within the exponential cap', function () {
    $p = new RetryPolicy(maxAttempts: 5, baseDelayMs: 100, maxDelayMs: 1000);

    for ($i = 0; $i < 200; $i++) {
        expect($p->delayFor(1))->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            ->and($p->delayFor(3))->toBeLessThanOrEqual(400)
            ->and($p->delayFor(10))->toBeLessThanOrEqual(1000)
            ->and($p->delayFor(500))->toBeLessThanOrEqual(1000);
    }
});

it('honours Retry-After capped at maxDelayMs', function () {
    $p = new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, maxDelayMs: 5000);

    expect($p->delayFor(1, 2))->toBe(2000)
        ->and($p->delayFor(1, 60))->toBe(5000)
        ->and($p->delayFor(1, 0))->toBe(0);
});
