<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;

it('applies defaults', function () {
    $c = new GpsBulgariaConfig(apiKey: 'k');

    expect($c->baseUrl)->toBe('https://iot.gps.bg/api/v2')
        ->and($c->timeout)->toBe(30)
        ->and($c->retry->maxAttempts)->toBe(1)
        ->and($c->apiKey())->toBe('k');
});

it('trims trailing slashes from the base URL', function () {
    expect((new GpsBulgariaConfig('k', 'https://example.test/api/v2//'))->baseUrl)->toBe('https://example.test/api/v2');
});

it('rejects invalid values', function (array $args, string $message) {
    expect(fn () => new GpsBulgariaConfig(...$args))->toThrow(ConfigurationException::class, $message);
})->with([
    'empty key' => [['apiKey' => ''], 'apiKey must not be empty'],
    'blank key' => [['apiKey' => '   '], 'apiKey must not be empty'],
    'empty url' => [['apiKey' => 'k', 'baseUrl' => ''], 'baseUrl must start with https://'],
    'http url' => [['apiKey' => 'k', 'baseUrl' => 'http://iot.gps.bg/api/v2'], 'baseUrl must start with https://'],
    'zero timeout' => [['apiKey' => 'k', 'timeout' => 0], 'timeout must be at least 1 second'],
]);

it('redacts the API key from debug output', function () {
    $c = new GpsBulgariaConfig(apiKey: 'super-secret-key');

    expect(print_r($c, true))->not->toContain('super-secret-key')->toContain('[REDACTED]');

    ob_start();
    var_dump($c);
    expect((string) ob_get_clean())->not->toContain('super-secret-key');
});

it('refuses to be serialized or unserialized', function () {
    expect(fn () => serialize(new GpsBulgariaConfig('k')))->toThrow(LogicException::class);

    $payload = 'O:43:"Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig":0:{}';
    expect(fn () => unserialize($payload))->toThrow(LogicException::class);
});

it('accepts a custom retry policy', function () {
    expect((new GpsBulgariaConfig('k', retry: RetryPolicy::attempts(3)))->retry->maxAttempts)->toBe(3);
});
