# ux2dev/gps-bulgaria SDK Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `ux2dev/gps-bulgaria`, a public, framework-agnostic PHP SDK for the GPS Bulgaria IoT API v2 with an optional Laravel layer, in the house style of `ux2dev/prim` and `ux2dev/borica`.

**Architecture:**
- **Root client:** `GpsBulgaria` takes a `final readonly` config plus PSR-18/17 interfaces and lazily builds four resources (`objects`, `objectTypes`, `zones`, `alerts`).
- **Transport:** a single `Transport` builds requests (with the `X-API-Key` header), maps HTTP errors to an exception per status code, and runs an opt-in retry policy that applies only to GET.
- **DTOs:** hand-written `final readonly` classes with `fromArray()`, using a shared internal `Support\Data` hydration helper.
- **Laravel:** a Manager supporting static tenants plus a runtime `forKey()`.

**Tech Stack:** PHP ^8.3, PSR-18/PSR-17, Pest 4, Guzzle 7 (dev), Orchestra Testbench (Laravel 12/13), symfony/yaml (dev, drift test), PHPStan level max, Laravel Pint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-06-gps-bulgaria-sdk-design.md`. Read it before starting any task.

## Global Constraints

**Package**
- Package `ux2dev/gps-bulgaria`, namespace `Ux2Dev\GpsBulgaria\` → `src/`, tests `Ux2Dev\GpsBulgaria\Tests\` → `tests/`.
- `require`: `php ^8.3`, `ext-json`, `psr/http-client ^1.0`, `psr/http-factory ^1.0`. Nothing else at runtime.
- Every PHP file starts with `<?php`, a blank line, then `declare(strict_types=1);`.

**Classes and types**
- Resources, DTOs, config, transport and the root client are `final`. All exception classes are **non-final**.
- Default base URL: `https://iot.gps.bg/api/v2`. Auth header: `X-API-Key`.
- IDs are always `string`. All date-times hydrate to UTC `DateTimeImmutable`.
- Enums are strict: an unknown value throws `InvalidResponseException`.
- "Required" means *required and non-nullable* in the OpenAPI schema. A missing one throws `InvalidResponseException("<Dto>: missing required field '<key>'")`.
  - A field that is required but nullable (e.g. `Object.comment`, `ObjectStatus.lastUpdate`, `Zone.name`) hydrates a missing key as `null`.
- Geometry factories take **latitude first**. The wire format is GeoJSON `[longitude, latitude]`.

**Behaviour**
- Retry applies only to `GET`, only on `ServiceUnavailableException`/`TransportException`, and is off by default (`maxAttempts = 1`).
- Laravel: `illuminate/support ^12|^13`. Config key `gps-bulgaria`, publish tag `gps-bulgaria-config`, container alias `gps-bulgaria`, env prefix `GPS_BULGARIA_`.

**Git**
- Commits are one line, in English, formatted `type(scope): summary`. **Never** add a `Co-Authored-By` trailer.
- The commit identity is `ux2dev <181749481+ux2dev@users.noreply.github.com>`. Task 1 sets this in the repo-local git config.

## Review Focus

These are the input classes most likely to bite a real user. Each one has a pinning test in the task that owns the code.

1. **Empty IDs:** an empty or whitespace ID passed to `objects()->get('')`, `status('')`, `routes('')`, `alerts()->forObject('')` or `zones()->get('')` must throw `InvalidArgumentException` *before* any request. Otherwise `'/objects/'` would silently hit the list endpoint and fail with a confusing hydration error. Covered in Task 8 (`Support\Path`), Task 9 and Task 10.
2. **Non-UTC dates:** a `DateTimeImmutable` in a non-UTC zone (e.g. `Europe/Sofia`) passed as `from`/`to` must be sent as the same instant in UTC (`…T21:00:00Z` for Sofia midnight in summer), not the local wall-clock time with `Z` appended. Covered in Task 3.
3. **Integers where floats are expected:** JSON integers for float fields (`angle: 89`, `radius: 250`, `latitude: 42`) must hydrate to `float`. Floats with a zero fraction (`250.0`) must be sent as `250.0`. Covered in Tasks 4, 5 and 6.
4. **Non-JSON error bodies:** an HTML 502 page from a proxy, or an empty 500 body, must produce the status-mapped `ApiException` with a readable message, never a raw `JsonException`. Covered in Task 3.
5. **Reversed time range:** `routes()` with `from` after `to` must throw `InvalidArgumentException` locally rather than spend a request on a guaranteed 400. Covered in Task 9.

---

## File Structure

```
composer.json  phpunit.xml  phpstan.neon  pint.json  .gitignore  .gitattributes
LICENSE  README.md  CHANGELOG.md  SECURITY.md
.github/workflows/ci.yml
spec/openapi.yaml                                  vendored upstream spec (Task 12)
src/
  GpsBulgaria.php                                  root client, lazy resources (Task 9)
  Config/GpsBulgariaConfig.php                     validated, redacted config (Task 2)
  Config/RetryPolicy.php                           attempts + jittered backoff (Task 2)
  Contracts/Hydratable.php                         fromArray() contract (Task 1)
  Exception/*.php                                  10 exception classes (Task 1)
  Http/Transport.php                               request building, error mapping, retry (Tasks 3 and 4)
  Support/Data.php                                 @internal typed hydration helpers (Task 5)
  Support/Path.php                                 @internal path-segment guard (Task 8)
  Enum/{ZoneType,GeometryType,PrimitiveType,ConditionOperator,ConditionPrimitive}.php
  Dto/{GpsObject,Parameter,ObjectType,ParameterDefinition}.php      (Task 5)
  Dto/{ObjectStatus,Location,Route,RoutePoint}.php                  (Task 6)
  Dto/{Geometry,Zone,ZoneInput}.php                                 (Task 7)
  Dto/{Alert,AlertRules,AlertRuleGroup,ConditionNode}.php           (Task 8)
  Resource/{ObjectsResource,ObjectTypesResource}.php                (Task 9)
  Resource/{ZonesResource,AlertsResource}.php                       (Task 10)
  Laravel/{GpsBulgariaManager,GpsBulgariaServiceProvider}.php, Facades/GpsBulgaria.php, config/gps-bulgaria.php (Task 11)
tests/
  Pest.php                                         helpers: fake responses, gps(), transport(), fixture()
  Support/FakeHttpClient.php
  fixtures/*.json                                  spec example payloads
  Exception/ExceptionHierarchyTest.php
  Config/{GpsBulgariaConfigTest,RetryPolicyTest}.php
  Http/{TransportTest,TransportErrorTest,TransportRetryTest}.php
  Support/{DataTest,PathTest}.php
  Dto/*Test.php
  Resource/*Test.php
  GpsBulgariaTest.php
  Laravel/{TestCase,ManagerTest,ServiceProviderTest}.php
  SpecDriftTest.php
  Live/LiveSmokeTest.php
```

**Deviation from the spec, called out explicitly:** the spec's §8 says the provider should `bindIf` a Guzzle PSR-18 client with the *default* tenant's timeout. That would apply one timeout to every tenant and every `forKey()` client. Instead, the provider passes PSR clients the *app* has already bound, and the manager builds a Guzzle client per tenant with *that* tenant's `timeout` (the prim pattern). The PSR-17 factories are still `bindIf`'d to Guzzle's `HttpFactory`. The observable behaviour the spec asks for (Guzzle used automatically, timeout honoured) is kept and becomes per-tenant.

---

### Task 1: Scaffold, exceptions, Hydratable contract, test harness

**Files:**
- Create: `composer.json`, `phpunit.xml`, `phpstan.neon`, `pint.json`, `.gitignore`, `.gitattributes`, `LICENSE`
- Create: `src/Contracts/Hydratable.php`
- Create: `src/Exception/GpsBulgariaException.php`, `ConfigurationException.php`, `TransportException.php`, `InvalidResponseException.php`, `ApiException.php`, `ValidationException.php`, `AuthenticationException.php`, `PermissionDeniedException.php`, `NotFoundException.php`, `ServiceUnavailableException.php`
- Create: `tests/Pest.php`, `tests/Support/FakeHttpClient.php`
- Test: `tests/Exception/ExceptionHierarchyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `interface Hydratable { /** @param array<mixed> $data */ public static function fromArray(array $data): static; }`
  - `class ApiException extends GpsBulgariaException` with constructor `(string $message, int $httpStatus, ?string $errorCode = null, ?string $traceId = null, array $body = [], ?int $retryAfterSeconds = null, ?\Throwable $previous = null)` and public readonly props `httpStatus`, `errorCode`, `traceId`, `body`, `retryAfterSeconds`. The 5 status subclasses inherit it unchanged.
  - `class GpsBulgariaException extends \RuntimeException`. `ConfigurationException`, `TransportException` and `InvalidResponseException` extend it with the default `RuntimeException` constructor.
  - `FakeHttpClient` with: a constructor taking `list<ResponseInterface|ClientExceptionInterface>`; `public array $captured`; static `json(int $status, array|string $body = [], array $headers = [])`, `raw(int $status, string $body, array $headers = [])` and `networkError(string $message = 'connection refused')`.

- [ ] **Step 1: Set the repo-local git identity**

```bash
git config user.name ux2dev
git config user.email 181749481+ux2dev@users.noreply.github.com
```

- [ ] **Step 2: Create `composer.json`**

```json
{
    "name": "ux2dev/gps-bulgaria",
    "description": "Framework-agnostic PHP SDK for the GPS Bulgaria IoT API (iot.gps.bg)",
    "type": "library",
    "license": "MIT",
    "keywords": ["gps", "gps-bulgaria", "fleet", "telematics", "iot", "sdk", "laravel"],
    "require": {
        "php": "^8.3",
        "ext-json": "*",
        "psr/http-client": "^1.0",
        "psr/http-factory": "^1.0"
    },
    "require-dev": {
        "pestphp/pest": "^4.0",
        "guzzlehttp/guzzle": "^7.9",
        "orchestra/testbench": "^10.0|^11.0",
        "symfony/yaml": "^7.0|^8.0",
        "phpstan/phpstan": "^2.1",
        "laravel/pint": "^1.18"
    },
    "suggest": {
        "guzzlehttp/guzzle": "Supplies a PSR-18 client and PSR-17 factories out of the box",
        "illuminate/support": "^12.0|^13.0 for the Laravel integration"
    },
    "autoload": {
        "psr-4": {
            "Ux2Dev\\GpsBulgaria\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Ux2Dev\\GpsBulgaria\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Ux2Dev\\GpsBulgaria\\Laravel\\GpsBulgariaServiceProvider"
            ],
            "aliases": {
                "GpsBulgaria": "Ux2Dev\\GpsBulgaria\\Laravel\\Facades\\GpsBulgaria"
            }
        }
    },
    "scripts": {
        "test": "pest",
        "test:coverage": "XDEBUG_MODE=coverage pest --coverage --min=100",
        "test:live": "pest --group=live",
        "lint": "pint --test",
        "stan": "phpstan analyse"
    },
    "minimum-stability": "stable",
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    }
}
```

- [ ] **Step 3: Create `phpunit.xml`, `phpstan.neon`, `pint.json`, `.gitignore`, `.gitattributes` and `LICENSE`**

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="GpsBulgaria">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <groups>
        <exclude>
            <group>live</group>
        </exclude>
    </groups>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`phpstan.neon`:
```neon
parameters:
    level: max
    paths:
        - src
```

`pint.json`:
```json
{
    "preset": "laravel"
}
```

`.gitignore`:
```
/vendor/
composer.lock
.phpunit.result.cache
.phpunit.cache/
/coverage/
.claude/
.env
```

`.gitattributes` (keeps tests and docs out of Packagist dist archives):
```
/.github        export-ignore
/docs           export-ignore
/tests          export-ignore
/spec           export-ignore
/.gitattributes export-ignore
/.gitignore     export-ignore
/phpunit.xml    export-ignore
/phpstan.neon   export-ignore
/pint.json      export-ignore
```

`LICENSE`: the standard MIT text with the line `Copyright (c) 2026 ux2dev`.

- [ ] **Step 4: Install dependencies**

Run: `composer install`
Expected: dependencies resolve and `vendor/bin/pest` exists.

- [ ] **Step 5: Create the test harness**

`tests/Support/FakeHttpClient.php`:
```php
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
```

`tests/Pest.php` (the `gps()`/`transport()` helpers reference classes from Tasks 2, 3 and 9. Pest only calls them inside tests, so defining them now is safe):
```php
<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\GpsBulgaria;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

uses(Ux2Dev\GpsBulgaria\Tests\Laravel\TestCase::class)->in('Laravel');

function test_config(?RetryPolicy $retry = null): GpsBulgariaConfig
{
    return new GpsBulgariaConfig(apiKey: 'test-key', retry: $retry ?? RetryPolicy::none());
}

function gps(FakeHttpClient $http, ?RetryPolicy $retry = null): GpsBulgaria
{
    $factory = new HttpFactory;

    return new GpsBulgaria(test_config($retry), $http, $factory, $factory);
}

/** @param (Closure(int): void)|null $sleep */
function transport(FakeHttpClient $http, ?RetryPolicy $retry = null, ?Closure $sleep = null): Transport
{
    $factory = new HttpFactory;

    return new Transport(test_config($retry), $http, $factory, $factory, $sleep ?? static function (int $ms): void {});
}

/** @return array<mixed> */
function fixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__."/fixtures/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}
```

Create an empty `tests/Laravel/TestCase.php` placeholder now so `uses()` resolves. Task 11 replaces its body:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Tests\Laravel;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {}
```

- [ ] **Step 6: Write the failing exception-hierarchy test**

`tests/Exception/ExceptionHierarchyTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Exception\ApiException;
use Ux2Dev\GpsBulgaria\Exception\AuthenticationException;
use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;
use Ux2Dev\GpsBulgaria\Exception\GpsBulgariaException;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Exception\PermissionDeniedException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Exception\TransportException;
use Ux2Dev\GpsBulgaria\Exception\ValidationException;

it('roots every exception in GpsBulgariaException which extends RuntimeException', function (string $class) {
    expect(is_subclass_of($class, GpsBulgariaException::class))->toBeTrue()
        ->and(is_subclass_of(GpsBulgariaException::class, RuntimeException::class))->toBeTrue();
})->with([
    ConfigurationException::class, TransportException::class, InvalidResponseException::class, ApiException::class,
]);

it('puts every status-specific exception under ApiException', function (string $class) {
    expect(is_subclass_of($class, ApiException::class))->toBeTrue();
})->with([
    ValidationException::class, AuthenticationException::class, PermissionDeniedException::class,
    NotFoundException::class, ServiceUnavailableException::class,
]);

it('keeps exception classes open for extension', function (string $class) {
    expect((new ReflectionClass($class))->isFinal())->toBeFalse();
})->with([
    GpsBulgariaException::class, ApiException::class, NotFoundException::class, TransportException::class,
]);

it('carries the API error details on ApiException', function () {
    $previous = new RuntimeException('inner');
    $e = new NotFoundException('object not found', 404, 'NOT_FOUND', 'trace-1', ['code' => 'NOT_FOUND'], null, $previous);

    expect($e->getMessage())->toBe('object not found')
        ->and($e->httpStatus)->toBe(404)
        ->and($e->errorCode)->toBe('NOT_FOUND')
        ->and($e->traceId)->toBe('trace-1')
        ->and($e->body)->toBe(['code' => 'NOT_FOUND'])
        ->and($e->retryAfterSeconds)->toBeNull()
        ->and($e->getPrevious())->toBe($previous);
});
```

- [ ] **Step 7: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Exception`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\Exception\…" not found`.

- [ ] **Step 8: Implement the contract and exceptions**

`src/Contracts/Hydratable.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Contracts;

interface Hydratable
{
    /** @param array<mixed> $data Decoded JSON object from the API. */
    public static function fromArray(array $data): static;
}
```

`src/Exception/GpsBulgariaException.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

use RuntimeException;

/** Root of every exception thrown by this SDK. */
class GpsBulgariaException extends RuntimeException {}
```

`ConfigurationException.php`, `TransportException.php` and `InvalidResponseException.php` all follow this form (only the class name and docblock change):
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

/** Invalid SDK configuration (empty API key, non-https base URL, unknown tenant, …). */
class ConfigurationException extends GpsBulgariaException {}
```
Docblocks: `TransportException` = "The HTTP request could not be completed (DNS, connection, TLS, timeout). The PSR-18 exception is available as getPrevious()." `InvalidResponseException` = "The API answered 2xx but the body was empty, not JSON, or did not match the documented schema."

`src/Exception/ApiException.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

use Throwable;

/**
 * The API answered with a non-2xx status. Catch this to handle every API
 * error; catch a subclass (NotFoundException, …) to handle one status.
 */
class ApiException extends GpsBulgariaException
{
    /**
     * @param  string|null  $errorCode  Stable API code: BAD_REQUEST, UNAUTHORIZED, FORBIDDEN, NOT_FOUND,
     *                                  INTERNAL_ERROR, SERVICE_UNAVAILABLE, or a future value as-is.
     * @param  array<mixed>  $body  Decoded error body, or [] when it was not JSON.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?string $errorCode = null,
        public readonly ?string $traceId = null,
        public readonly array $body = [],
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
```

The 5 subclasses (`ValidationException` 400, `AuthenticationException` 401, `PermissionDeniedException` 403, `NotFoundException` 404, `ServiceUnavailableException` 503) all follow this form:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Exception;

/** HTTP 404: the resource does not exist or is not available to this API key. */
class NotFoundException extends ApiException {}
```
Docblocks:
- 400: "HTTP 400: the request was rejected as malformed or invalid."
- 401: "HTTP 401: X-API-Key is missing, not recognised, revoked or expired."
- 403: "HTTP 403: this API key is not permitted to perform the operation."
- 503: "HTTP 503: the service is temporarily unavailable; safe to retry for reads."

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Exception`
Expected: PASS, 0 failures.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "chore(scaffold): add package skeleton, exception hierarchy and test harness"
```

---

### Task 2: Config and RetryPolicy

**Files:**
- Create: `src/Config/GpsBulgariaConfig.php`, `src/Config/RetryPolicy.php`
- Test: `tests/Config/GpsBulgariaConfigTest.php`, `tests/Config/RetryPolicyTest.php`

**Interfaces:**
- Consumes: `ConfigurationException` (Task 1).
- Produces:
  - `final readonly class GpsBulgariaConfig`: `__construct(string $apiKey, string $baseUrl = 'https://iot.gps.bg/api/v2', int $timeout = 30, RetryPolicy $retry = new RetryPolicy())`, public `string $baseUrl`, public `int $timeout`, public `RetryPolicy $retry`, and `apiKey(): string`.
  - `final readonly class RetryPolicy`: `__construct(int $maxAttempts = 1, int $baseDelayMs = 200, int $maxDelayMs = 2000)`, `static none(): self`, `static attempts(int $n): self`, and `delayFor(int $retry, ?int $retryAfterSeconds = null): int` (milliseconds; `$retry` is 1 for the first retry).

- [ ] **Step 1: Write the failing tests**

`tests/Config/GpsBulgariaConfigTest.php`:
```php
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

    $payload = 'O:42:"Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig":0:{}';
    expect(fn () => unserialize($payload))->toThrow(LogicException::class);
});

it('accepts a custom retry policy', function () {
    expect((new GpsBulgariaConfig('k', retry: RetryPolicy::attempts(3)))->retry->maxAttempts)->toBe(3);
});
```

`tests/Config/RetryPolicyTest.php`:
```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Config`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig" not found`.

- [ ] **Step 3: Implement `RetryPolicy`**

`src/Config/RetryPolicy.php`:
```php
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
```

- [ ] **Step 4: Implement `GpsBulgariaConfig`**

`src/Config/GpsBulgariaConfig.php`:
```php
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
        private string $apiKey,
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
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Config`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Config tests/Config
git commit -m "feat(config): add validated config with redacted api key and retry policy"
```

---

### Task 3: Transport (requests and error mapping)

**Files:**
- Create: `src/Http/Transport.php`
- Test: `tests/Http/TransportTest.php`, `tests/Http/TransportErrorTest.php`

**Interfaces:**
- Consumes: `GpsBulgariaConfig`, `RetryPolicy` (Task 2), and all exceptions (Task 1).
- Produces:
  - `final class Transport`: `__construct(GpsBulgariaConfig $config, ClientInterface $httpClient, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory, ?Closure $sleep = null)`.
  - `request(string $method, string $path, array<string, scalar|DateTimeInterface|null> $query = [], ?array $body = null): array` returns the decoded JSON array.
  - `$path` must start with `/`, and resources are responsible for `rawurlencode`-ing segments.
  - Task 4 adds retry inside `request()` without changing its signature.

- [ ] **Step 1: Write the failing request-building tests**

`tests/Http/TransportTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Exception\TransportException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('sends a GET with the API key and JSON accept header', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [['a' => 1]])]);

    $result = transport($http)->request('GET', '/objects');

    $r = $http->captured[0];
    expect($result)->toBe([['a' => 1]])
        ->and($r->getMethod())->toBe('GET')
        ->and((string) $r->getUri())->toBe('https://iot.gps.bg/api/v2/objects')
        ->and($r->getHeaderLine('X-API-Key'))->toBe('test-key')
        ->and($r->getHeaderLine('Accept'))->toBe('application/json')
        ->and($r->hasHeader('Content-Type'))->toBeFalse()
        ->and((string) $r->getBody())->toBe('');
});

it('encodes booleans, drops nulls and keeps strings in the query', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

    transport($http)->request('GET', '/zones', ['includeGeometry' => true, 'other' => false, 'skip' => null, 's' => 'a b']);

    expect($http->captured[0]->getUri()->getQuery())->toBe('includeGeometry=true&other=false&s=a%20b');
});

it('sends DateTime query values as the same instant in UTC', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);
    $sofiaMidnight = new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('Europe/Sofia'));

    transport($http)->request('GET', '/x', ['from' => $sofiaMidnight]);

    expect(urldecode($http->captured[0]->getUri()->getQuery()))->toBe('from=2026-07-31T21:00:00Z');
});

it('sends a JSON body that preserves zero fractions and unicode', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(201, ['ok' => true])]);

    transport($http)->request('POST', '/zones', [], ['radius' => 250.0, 'name' => 'Склад', 'url' => 'a/b']);

    $r = $http->captured[0];
    expect($r->getMethod())->toBe('POST')
        ->and($r->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and((string) $r->getBody())->toBe('{"radius":250.0,"name":"Склад","url":"a/b"}');
});

it('rejects empty, non-JSON and scalar success bodies', function (string $body, string $message) {
    $http = new FakeHttpClient([FakeHttpClient::raw(200, $body)]);

    expect(fn () => transport($http)->request('GET', '/objects'))
        ->toThrow(InvalidResponseException::class, $message);
})->with([
    'empty' => ['', 'Empty response body (HTTP 200)'],
    'html' => ['<html>oops</html>', 'Response is not valid JSON (HTTP 200)'],
    'scalar' => ['"hello"', 'Response is not a JSON object or array (HTTP 200)'],
]);

it('wraps PSR-18 failures in TransportException with the original as previous', function () {
    $inner = FakeHttpClient::networkError('connection refused');
    $http = new FakeHttpClient([$inner]);

    try {
        transport($http)->request('GET', '/objects');
        $this->fail('expected TransportException');
    } catch (TransportException $e) {
        expect($e->getMessage())->toBe('GPS Bulgaria request failed: connection refused')
            ->and($e->getPrevious())->toBe($inner);
    }
});
```

- [ ] **Step 2: Write the failing error-mapping tests**

`tests/Http/TransportErrorTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Exception\ApiException;
use Ux2Dev\GpsBulgaria\Exception\AuthenticationException;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Exception\PermissionDeniedException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Exception\ValidationException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

function catchApi(callable $fn): ApiException
{
    try {
        $fn();
    } catch (ApiException $e) {
        return $e;
    }
    throw new RuntimeException('expected ApiException');
}

it('maps each status to its exception class with code, message and traceId', function (int $status, string $class, string $code) {
    $body = ['code' => $code, 'message' => 'msg '.$status, 'traceId' => 'tr-'.$status];
    $http = new FakeHttpClient([FakeHttpClient::json($status, $body)]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects/1'));

    expect($e)->toBeInstanceOf($class)
        ->and($e->httpStatus)->toBe($status)
        ->and($e->getCode())->toBe($status)
        ->and($e->errorCode)->toBe($code)
        ->and($e->getMessage())->toBe('msg '.$status)
        ->and($e->traceId)->toBe('tr-'.$status)
        ->and($e->body)->toBe($body);
})->with([
    [400, ValidationException::class, 'BAD_REQUEST'],
    [401, AuthenticationException::class, 'UNAUTHORIZED'],
    [403, PermissionDeniedException::class, 'FORBIDDEN'],
    [404, NotFoundException::class, 'NOT_FOUND'],
    [503, ServiceUnavailableException::class, 'SERVICE_UNAVAILABLE'],
    [500, ApiException::class, 'INTERNAL_ERROR'],
    [418, ApiException::class, 'TEAPOT'],
]);

it('handles an error body without traceId', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(404, ['code' => 'NOT_FOUND', 'message' => 'object not found'])]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects/1'));

    expect($e->traceId)->toBeNull()->and($e->errorCode)->toBe('NOT_FOUND');
});

it('still maps the status when a proxy returns an HTML error page', function () {
    $html = '<html><body>'.str_repeat('Bad Gateway ', 100).'</body></html>';
    $http = new FakeHttpClient([FakeHttpClient::raw(503, $html)]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects'));

    expect($e)->toBeInstanceOf(ServiceUnavailableException::class)
        ->and($e->errorCode)->toBeNull()
        ->and($e->body)->toBe([])
        ->and($e->getMessage())->toStartWith('HTTP 503: <html><body>Bad Gateway')
        ->and(strlen($e->getMessage()))->toBeLessThanOrEqual(strlen('HTTP 503: ') + 503);
});

it('describes an empty error body by status', function () {
    $http = new FakeHttpClient([FakeHttpClient::raw(500, '')]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects'));

    expect($e->getMessage())->toBe('HTTP 500')->and($e->body)->toBe([]);
});

it('keeps a JSON error body that lacks the documented shape', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(500, ['error' => 'boom'])]);

    $e = catchApi(fn () => transport($http)->request('GET', '/objects'));

    expect($e->getMessage())->toBe('HTTP 500: {"error":"boom"}')
        ->and($e->errorCode)->toBeNull()
        ->and($e->body)->toBe(['error' => 'boom']);
});

it('parses a numeric Retry-After header', function (string $header, ?int $expected) {
    $http = new FakeHttpClient([FakeHttpClient::json(503, ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'x'], ['Retry-After' => $header])]);

    expect(catchApi(fn () => transport($http)->request('GET', '/objects'))->retryAfterSeconds)->toBe($expected);
})->with([
    ['7', 7],
    ['Wed, 21 Oct 2026 07:28:00 GMT', null],
    ['', null],
]);
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Http`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\Http\Transport" not found`.

- [ ] **Step 4: Implement `Transport` (without retry)**

`src/Http/Transport.php`:
```php
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
     * @param  array<string, scalar|DateTimeInterface|null>  $query
     * @param  array<mixed>|null  $body
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->send($method, $path, $query, $body);
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

        $message = isset($body['message']) && is_string($body['message'])
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
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Http`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Http tests/Http
git commit -m "feat(http): add transport with api key auth and status-mapped exceptions"
```

---

### Task 4: Transport (retry)

**Files:**
- Modify: `src/Http/Transport.php` (the `request()` method)
- Test: `tests/Http/TransportRetryTest.php`

**Interfaces:**
- Consumes: `RetryPolicy::delayFor()` (Task 2), `Transport` (Task 3).
- Produces: no new API. `request()` now retries according to `$config->retry`.

- [ ] **Step 1: Write the failing tests**

`tests/Http/TransportRetryTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Exception\TransportException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

function unavailable(array $headers = []): GuzzleHttp\Psr7\Response
{
    return FakeHttpClient::json(503, ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'service temporarily unavailable'], $headers);
}

it('does not retry by default', function () {
    $http = new FakeHttpClient([unavailable(), FakeHttpClient::json(200, [])]);

    expect(fn () => transport($http)->request('GET', '/objects'))->toThrow(ServiceUnavailableException::class);
    expect($http->captured)->toHaveCount(1);
});

it('retries a GET on 503 and transport errors until it succeeds', function () {
    $sleeps = [];
    $http = new FakeHttpClient([unavailable(), FakeHttpClient::networkError(), FakeHttpClient::json(200, [['ok' => 1]])]);

    $result = transport($http, new RetryPolicy(3, 100, 1000), function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    })->request('GET', '/objects');

    expect($result)->toBe([['ok' => 1]])
        ->and($http->captured)->toHaveCount(3)
        ->and($sleeps)->toHaveCount(2)
        ->and($sleeps[0])->toBeLessThanOrEqual(100)
        ->and($sleeps[1])->toBeLessThanOrEqual(200);
});

it('rethrows the last exception once attempts are exhausted', function () {
    $http = new FakeHttpClient([unavailable(), FakeHttpClient::networkError('timed out')]);

    expect(fn () => transport($http, RetryPolicy::attempts(2))->request('GET', '/objects'))
        ->toThrow(TransportException::class, 'GPS Bulgaria request failed: timed out');
    expect($http->captured)->toHaveCount(2);
});

it('never retries a POST', function () {
    $http = new FakeHttpClient([unavailable(), FakeHttpClient::json(201, [])]);

    expect(fn () => transport($http, RetryPolicy::attempts(3))->request('POST', '/zones', [], ['zoneType' => 'circle']))
        ->toThrow(ServiceUnavailableException::class);
    expect($http->captured)->toHaveCount(1);
});

it('never retries other API errors', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(404, ['code' => 'NOT_FOUND', 'message' => 'object not found'])]);

    expect(fn () => transport($http, RetryPolicy::attempts(3))->request('GET', '/objects/1'))
        ->toThrow(NotFoundException::class);
    expect($http->captured)->toHaveCount(1);
});

it('waits for Retry-After when the server sends one', function () {
    $sleeps = [];
    $http = new FakeHttpClient([unavailable(['Retry-After' => '1']), FakeHttpClient::json(200, [])]);

    transport($http, new RetryPolicy(2, 100, 5000), function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    })->request('GET', '/objects');

    expect($sleeps)->toBe([1000]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Http/TransportRetryTest.php`
Expected: FAIL. `retries a GET on 503…` throws `ServiceUnavailableException` because there is no retry yet.

- [ ] **Step 3: Implement retry in `request()`**

Replace the body of `request()` in `src/Http/Transport.php`:
```php
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
```

- [ ] **Step 4: Run all transport tests to verify they pass**

Run: `vendor/bin/pest tests/Http`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Transport.php tests/Http/TransportRetryTest.php
git commit -m "feat(http): add opt-in jittered retry for GET on 503 and transport errors"
```

---

### Task 5: Hydration helper, enums, object DTOs

**Files:**
- Create: `src/Support/Data.php`
- Create: `src/Enum/PrimitiveType.php`, `src/Enum/ZoneType.php`, `src/Enum/GeometryType.php`, `src/Enum/ConditionOperator.php`, `src/Enum/ConditionPrimitive.php`
- Create: `src/Dto/Parameter.php`, `src/Dto/GpsObject.php`, `src/Dto/ParameterDefinition.php`, `src/Dto/ObjectType.php`
- Create: `tests/fixtures/object.json`, `tests/fixtures/object-type.json`
- Test: `tests/Support/DataTest.php`, `tests/Dto/ObjectDtoTest.php`

**Interfaces:**
- Consumes: `InvalidResponseException`, `Hydratable` (Task 1).
- Produces (`Ux2Dev\GpsBulgaria\Support\Data`, `@internal`, every method static; `$dto` is the short class name used in error messages):
  - `string(array $d, string $key, string $dto): string`
  - `nullableString(array $d, string $key, string $dto): ?string`
  - `int(array $d, string $key, string $dto): int`
  - `float(array $d, string $key, string $dto): float`, which accepts a JSON int
  - `nullableFloat(array $d, string $key, string $dto): ?float`
  - `bool(array $d, string $key, string $dto, ?bool $default = null): bool`; when `$default` is non-null, a missing key returns it
  - `dateTime(array $d, string $key, string $dto): DateTimeImmutable`
  - `nullableDateTime(array $d, string $key, string $dto): ?DateTimeImmutable`
  - `stringMap(array $d, string $key, string $dto): array<string, string>` (required)
  - `stringList(array $d, string $key, string $dto, bool $required = true): list<string>`
  - `object(array $d, string $key, string $dto): array<mixed>` (required)
  - `nullableObject(array $d, string $key, string $dto): ?array<mixed>`
  - `objects(array $d, string $key, string $dto, callable(array<mixed>): T $map, bool $required = true): list<T>`
  - `enum(class-string<E of BackedEnum> $enum, array $d, string $key, string $dto): E`
  - `rows(array $decoded, string $dto): list<array<mixed>>`, which validates a bare JSON array of objects
- Produces (enums): `PrimitiveType` {`String`='string', `Number`='number', `Date`='date', `File`='file'}; `ZoneType` {`Circle`='circle', `Polygon`='polygon', `Polyline`='polyline', `Rectangle`='rectangle'}; `GeometryType` {`Point`='Point', `LineString`='LineString', `Polygon`='Polygon'}; `ConditionOperator` {`LogicalNot`='NOT', `LogicalAnd`='AND', `LogicalOr`='OR', `Eq`='EQ', `NotEq`='NOT_EQ', `Lt`='LT', `Lte`='LTE', `Gt`='GT', `Gte`='GTE', `StartsWith`='STARTS_WITH', `NotStartsWith`='NOT_STARTS_WITH', `Contains`='CONTAINS', `NotContains`='NOT_CONTAINS', `InZone`='IN_ZONE', `OutsideZone`='OUTSIDE_ZONE'}; `ConditionPrimitive` {`String`='string', `Boolean`='boolean', `Integer`='integer', `Long`='long', `Float`='float', `Double`='double', `NotApplicable`='n/a'}.
- Produces (DTOs):
  - `Parameter(int $id, string $name, string $value)` with `asDate(): ?DateTimeImmutable` and `asFloat(): ?float`.
  - `GpsObject(string $objectId, ?string $objectType, string $name, ?string $comment, list<string> $tags, list<Parameter> $parameters)` with `parameter(int $id): ?Parameter` and `parameterValue(int $id): ?string`.
  - `ParameterDefinition(int $id, string $name, PrimitiveType $type, bool $required, bool $readOnly, ?list<string> $values)`.
  - `ObjectType(string $name, list<ParameterDefinition> $parameters)` with `definition(int $id): ?ParameterDefinition`.
  - All DTOs are `final readonly` and implement `Hydratable`.

- [ ] **Step 1: Create the fixtures**

These are the spec's own `example:` payloads.

`tests/fixtures/object.json`:
```json
{
    "objectID": "14574",
    "objectType": "Vehicle",
    "name": "Renault Clio",
    "comment": "Priority delivery unit",
    "tags": ["fleet", "europe"],
    "parameters": [
        {"id": 1, "name": "brand", "value": "Renault"},
        {"id": 2, "name": "model", "value": "Clio"},
        {"id": 3, "name": "license plate", "value": "CA0000CA"}
    ]
}
```

`tests/fixtures/object-type.json`:
```json
{
    "name": "Vehicle",
    "parameters": [
        {"id": 1, "name": "brand", "type": "string", "required": true, "readOnly": false, "values": ["Renault", "Peugeot", "Ford"]},
        {"id": 2, "name": "model", "type": "string", "required": true, "readOnly": false},
        {"id": 3, "name": "license plate", "type": "string", "required": false, "readOnly": false}
    ]
}
```

- [ ] **Step 2: Write the failing `Data` tests**

`tests/Support/DataTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Enum\ZoneType;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Support\Data;

it('reads typed scalars', function () {
    $d = ['s' => 'x', 'i' => 5, 'f' => 1.5, 'fi' => 89, 'b' => true];

    expect(Data::string($d, 's', 'T'))->toBe('x')
        ->and(Data::int($d, 'i', 'T'))->toBe(5)
        ->and(Data::float($d, 'f', 'T'))->toBe(1.5)
        ->and(Data::float($d, 'fi', 'T'))->toBe(89.0)
        ->and(Data::bool($d, 'b', 'T'))->toBeTrue()
        ->and(Data::bool([], 'missing', 'T', false))->toBeFalse();
});

it('names the DTO and field when a required value is missing', function () {
    expect(fn () => Data::string([], 'name', 'GpsObject'))
        ->toThrow(InvalidResponseException::class, "GpsObject: missing required field 'name'");
});

it('names the expected type when a value has the wrong type', function (callable $fn, string $message) {
    expect($fn)->toThrow(InvalidResponseException::class, $message);
})->with([
    [fn () => Data::string(['k' => 1], 'k', 'T'), "T: field 'k' must be string, got int"],
    [fn () => Data::int(['k' => '1'], 'k', 'T'), "T: field 'k' must be int, got string"],
    [fn () => Data::float(['k' => '1.5'], 'k', 'T'), "T: field 'k' must be number, got string"],
    [fn () => Data::bool(['k' => 'true'], 'k', 'T'), "T: field 'k' must be bool, got string"],
    [fn () => Data::nullableString(['k' => []], 'k', 'T'), "T: field 'k' must be string, got array"],
]);

it('treats missing and null nullable values as null', function () {
    expect(Data::nullableString([], 'k', 'T'))->toBeNull()
        ->and(Data::nullableString(['k' => null], 'k', 'T'))->toBeNull()
        ->and(Data::nullableFloat(['k' => null], 'k', 'T'))->toBeNull()
        ->and(Data::nullableFloat(['k' => 2], 'k', 'T'))->toBe(2.0)
        ->and(Data::nullableDateTime([], 'k', 'T'))->toBeNull()
        ->and(Data::nullableObject(['k' => null], 'k', 'T'))->toBeNull();
});

it('parses RFC 3339 date-times into UTC', function (string $input, string $expected) {
    $dt = Data::dateTime(['t' => $input], 't', 'T');

    expect($dt->format('Y-m-d\TH:i:s.u P'))->toBe($expected)
        ->and($dt->getTimezone()->getName())->toBe('UTC');
})->with([
    ['2026-09-09T14:44:38Z', '2026-09-09T14:44:38.000000 +00:00'],
    ['2026-09-09T17:44:38+03:00', '2026-09-09T14:44:38.000000 +00:00'],
    ['2026-09-09T14:44:38.250Z', '2026-09-09T14:44:38.250000 +00:00'],
]);

it('rejects non-RFC 3339 date-times', function (string $input) {
    expect(fn () => Data::dateTime(['t' => $input], 't', 'Route'))
        ->toThrow(InvalidResponseException::class, "Route: field 't' is not an RFC 3339 date-time");
})->with(['now', '2026-09-09', '2026-09-09 14:44:38', 'tomorrow 10:00']);

it('reads string maps and lists', function () {
    $d = ['m' => ['speed' => '0', 'km' => '1.5'], 'empty' => [], 'l' => ['a', 'b']];

    expect(Data::stringMap($d, 'm', 'T'))->toBe(['speed' => '0', 'km' => '1.5'])
        ->and(Data::stringMap($d, 'empty', 'T'))->toBe([])
        ->and(Data::stringList($d, 'l', 'T'))->toBe(['a', 'b'])
        ->and(Data::stringList([], 'l', 'T', required: false))->toBe([]);

    expect(fn () => Data::stringMap(['m' => ['speed' => 0]], 'm', 'T'))
        ->toThrow(InvalidResponseException::class, "T: field 'm' must be a map of strings");
    expect(fn () => Data::stringList(['l' => ['a', 1]], 'l', 'T'))
        ->toThrow(InvalidResponseException::class, "T: field 'l' must be a list of strings");
});

it('maps nested object lists', function () {
    $out = Data::objects(['p' => [['v' => 1], ['v' => 2]]], 'p', 'T', fn (array $row) => $row['v']);

    expect($out)->toBe([1, 2])
        ->and(Data::objects([], 'p', 'T', fn (array $r) => $r, required: false))->toBe([]);

    expect(fn () => Data::objects(['p' => ['x']], 'p', 'T', fn (array $r) => $r))
        ->toThrow(InvalidResponseException::class, "T: field 'p' must be a list of objects");
});

it('reads enums strictly', function () {
    expect(Data::enum(ZoneType::class, ['z' => 'circle'], 'z', 'Zone'))->toBe(ZoneType::Circle);

    expect(fn () => Data::enum(ZoneType::class, ['z' => 'hexagon'], 'z', 'Zone'))
        ->toThrow(InvalidResponseException::class, "Zone: field 'z' has unknown ZoneType value 'hexagon'");
});

it('validates bare JSON arrays of objects', function () {
    expect(Data::rows([['a' => 1], ['a' => 2]], 'GpsObject'))->toBe([['a' => 1], ['a' => 2]])
        ->and(Data::rows([], 'GpsObject'))->toBe([]);

    expect(fn () => Data::rows(['a' => 1], 'GpsObject'))
        ->toThrow(InvalidResponseException::class, 'GpsObject: expected a JSON array of objects');
    expect(fn () => Data::rows([1, 2], 'GpsObject'))
        ->toThrow(InvalidResponseException::class, 'GpsObject: expected a JSON array of objects');
});
```

- [ ] **Step 3: Write the failing object-DTO tests**

`tests/Dto/ObjectDtoTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\GpsObject;
use Ux2Dev\GpsBulgaria\Dto\ObjectType;
use Ux2Dev\GpsBulgaria\Dto\Parameter;
use Ux2Dev\GpsBulgaria\Enum\PrimitiveType;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

it('hydrates an object from the spec example', function () {
    $o = GpsObject::fromArray(fixture('object'));

    expect($o->objectId)->toBe('14574')
        ->and($o->objectType)->toBe('Vehicle')
        ->and($o->name)->toBe('Renault Clio')
        ->and($o->comment)->toBe('Priority delivery unit')
        ->and($o->tags)->toBe(['fleet', 'europe'])
        ->and($o->parameters)->toHaveCount(3)
        ->and($o->parameters[2])->toBeInstanceOf(Parameter::class);
});

it('looks parameters up by id, not by display name', function () {
    $o = GpsObject::fromArray(fixture('object'));

    expect($o->parameter(3)?->value)->toBe('CA0000CA')
        ->and($o->parameterValue(1))->toBe('Renault')
        ->and($o->parameter(99))->toBeNull()
        ->and($o->parameterValue(99))->toBeNull();
});

it('normalises an empty objectType and a null comment to null', function () {
    $data = fixture('object');
    $data['objectType'] = '';
    $data['comment'] = null;

    $o = GpsObject::fromArray($data);

    expect($o->objectType)->toBeNull()->and($o->comment)->toBeNull();
});

it('fails loudly on a missing required field', function () {
    $data = fixture('object');
    unset($data['name']);

    expect(fn () => GpsObject::fromArray($data))
        ->toThrow(InvalidResponseException::class, "GpsObject: missing required field 'name'");
});

it('converts parameter values on request', function () {
    expect((new Parameter(4, 'registered', '2024-03-15'))->asDate()?->format('Y-m-d H:i:s e'))->toBe('2024-03-15 00:00:00 UTC')
        ->and((new Parameter(4, 'registered', '15.03.2024'))->asDate())->toBeNull()
        ->and((new Parameter(4, 'registered', '2024-02-30'))->asDate())->toBeNull()
        ->and((new Parameter(5, 'tank', '55.5'))->asFloat())->toBe(55.5)
        ->and((new Parameter(5, 'tank', 'n/a'))->asFloat())->toBeNull();
});

it('hydrates an object type with its parameter definitions', function () {
    $t = ObjectType::fromArray(fixture('object-type'));

    expect($t->name)->toBe('Vehicle')
        ->and($t->parameters)->toHaveCount(3)
        ->and($t->definition(1)?->type)->toBe(PrimitiveType::String)
        ->and($t->definition(1)?->required)->toBeTrue()
        ->and($t->definition(1)?->readOnly)->toBeFalse()
        ->and($t->definition(1)?->values)->toBe(['Renault', 'Peugeot', 'Ford'])
        ->and($t->definition(2)?->values)->toBeNull()
        ->and($t->definition(99))->toBeNull();
});

it('rejects an unknown parameter type', function () {
    $data = fixture('object-type');
    $data['parameters'][0]['type'] = 'geopoint';

    expect(fn () => ObjectType::fromArray($data))
        ->toThrow(InvalidResponseException::class, "ParameterDefinition: field 'type' has unknown PrimitiveType value 'geopoint'");
});
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Support tests/Dto`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\Support\Data" not found`.

- [ ] **Step 5: Implement `Support\Data`**

`src/Support/Data.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Support;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

/**
 * Typed readers over decoded JSON, used by every DTO's fromArray(). Each
 * failure names the DTO and the field so spec drift is easy to diagnose.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class Data
{
    private const RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/i';

    /** @param array<mixed> $d */
    public static function string(array $d, string $key, string $dto): string
    {
        $v = self::required($d, $key, $dto);

        return is_string($v) ? $v : throw self::type($dto, $key, 'string', $v);
    }

    /** @param array<mixed> $d */
    public static function nullableString(array $d, string $key, string $dto): ?string
    {
        $v = $d[$key] ?? null;

        return ($v === null || is_string($v)) ? $v : throw self::type($dto, $key, 'string', $v);
    }

    /** @param array<mixed> $d */
    public static function int(array $d, string $key, string $dto): int
    {
        $v = self::required($d, $key, $dto);

        return is_int($v) ? $v : throw self::type($dto, $key, 'int', $v);
    }

    /** @param array<mixed> $d */
    public static function float(array $d, string $key, string $dto): float
    {
        $v = self::required($d, $key, $dto);

        return (is_int($v) || is_float($v)) ? (float) $v : throw self::type($dto, $key, 'number', $v);
    }

    /** @param array<mixed> $d */
    public static function nullableFloat(array $d, string $key, string $dto): ?float
    {
        $v = $d[$key] ?? null;

        return match (true) {
            $v === null => null,
            is_int($v), is_float($v) => (float) $v,
            default => throw self::type($dto, $key, 'number', $v),
        };
    }

    /** @param array<mixed> $d */
    public static function bool(array $d, string $key, string $dto, ?bool $default = null): bool
    {
        if ($default !== null && ! array_key_exists($key, $d)) {
            return $default;
        }

        $v = self::required($d, $key, $dto);

        return is_bool($v) ? $v : throw self::type($dto, $key, 'bool', $v);
    }

    /** @param array<mixed> $d */
    public static function dateTime(array $d, string $key, string $dto): DateTimeImmutable
    {
        return self::parseDateTime(self::string($d, $key, $dto), $key, $dto);
    }

    /** @param array<mixed> $d */
    public static function nullableDateTime(array $d, string $key, string $dto): ?DateTimeImmutable
    {
        $v = self::nullableString($d, $key, $dto);

        return $v === null ? null : self::parseDateTime($v, $key, $dto);
    }

    /**
     * @param  array<mixed>  $d
     * @return array<string, string>
     */
    public static function stringMap(array $d, string $key, string $dto): array
    {
        $v = self::required($d, $key, $dto);
        if (! is_array($v)) {
            throw new InvalidResponseException("{$dto}: field '{$key}' must be a map of strings");
        }

        $out = [];
        foreach ($v as $k => $item) {
            if (! is_string($item)) {
                throw new InvalidResponseException("{$dto}: field '{$key}' must be a map of strings");
            }
            $out[(string) $k] = $item;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $d
     * @return list<string>
     */
    public static function stringList(array $d, string $key, string $dto, bool $required = true): array
    {
        if (! $required && ($d[$key] ?? null) === null) {
            return [];
        }

        $v = self::required($d, $key, $dto);
        if (! is_array($v) || ! array_is_list($v)) {
            throw new InvalidResponseException("{$dto}: field '{$key}' must be a list of strings");
        }

        foreach ($v as $item) {
            if (! is_string($item)) {
                throw new InvalidResponseException("{$dto}: field '{$key}' must be a list of strings");
            }
        }

        /** @var list<string> $v */
        return $v;
    }

    /**
     * @param  array<mixed>  $d
     * @return array<mixed>
     */
    public static function object(array $d, string $key, string $dto): array
    {
        $v = self::required($d, $key, $dto);

        return is_array($v) ? $v : throw self::type($dto, $key, 'object', $v);
    }

    /**
     * @param  array<mixed>  $d
     * @return array<mixed>|null
     */
    public static function nullableObject(array $d, string $key, string $dto): ?array
    {
        $v = $d[$key] ?? null;

        return ($v === null || is_array($v)) ? $v : throw self::type($dto, $key, 'object', $v);
    }

    /**
     * @template T
     *
     * @param  array<mixed>  $d
     * @param  callable(array<mixed>): T  $map
     * @return list<T>
     */
    public static function objects(array $d, string $key, string $dto, callable $map, bool $required = true): array
    {
        if (! $required && ($d[$key] ?? null) === null) {
            return [];
        }

        $v = self::required($d, $key, $dto);
        if (! is_array($v) || ! array_is_list($v)) {
            throw new InvalidResponseException("{$dto}: field '{$key}' must be a list of objects");
        }

        $out = [];
        foreach ($v as $row) {
            if (! is_array($row)) {
                throw new InvalidResponseException("{$dto}: field '{$key}' must be a list of objects");
            }
            $out[] = $map($row);
        }

        return $out;
    }

    /**
     * @template E of BackedEnum
     *
     * @param  class-string<E>  $enum
     * @param  array<mixed>  $d
     * @return E
     */
    public static function enum(string $enum, array $d, string $key, string $dto): BackedEnum
    {
        $raw = self::string($d, $key, $dto);
        $short = substr($enum, (int) strrpos($enum, '\\') + 1);

        return $enum::tryFrom($raw)
            ?? throw new InvalidResponseException("{$dto}: field '{$key}' has unknown {$short} value '{$raw}'");
    }

    /**
     * Validates a top-level JSON array response whose items are objects.
     *
     * @param  array<mixed>  $decoded
     * @return list<array<mixed>>
     */
    public static function rows(array $decoded, string $dto): array
    {
        if (! array_is_list($decoded)) {
            throw new InvalidResponseException("{$dto}: expected a JSON array of objects");
        }

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                throw new InvalidResponseException("{$dto}: expected a JSON array of objects");
            }
        }

        /** @var list<array<mixed>> $decoded */
        return $decoded;
    }

    private static function parseDateTime(string $value, string $key, string $dto): DateTimeImmutable
    {
        if (! preg_match(self::RFC3339, $value)) {
            throw new InvalidResponseException("{$dto}: field '{$key}' is not an RFC 3339 date-time: '{$value}'");
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception $e) {
            throw new InvalidResponseException("{$dto}: field '{$key}' is not an RFC 3339 date-time: '{$value}'", 0, $e);
        }
    }

    /** @param array<mixed> $d */
    private static function required(array $d, string $key, string $dto): mixed
    {
        if (! array_key_exists($key, $d) || $d[$key] === null) {
            throw new InvalidResponseException("{$dto}: missing required field '{$key}'");
        }

        return $d[$key];
    }

    private static function type(string $dto, string $key, string $expected, mixed $actual): InvalidResponseException
    {
        return new InvalidResponseException("{$dto}: field '{$key}' must be {$expected}, got ".get_debug_type($actual));
    }
}
```

- [ ] **Step 6: Implement the enums**

`src/Enum/ZoneType.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

/** Zone shape; selects the geometry: circle=Point+radius, polyline=LineString, polygon/rectangle=Polygon. */
enum ZoneType: string
{
    case Circle = 'circle';
    case Polygon = 'polygon';
    case Polyline = 'polyline';
    case Rectangle = 'rectangle';
}
```

`src/Enum/GeometryType.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

enum GeometryType: string
{
    case Point = 'Point';
    case LineString = 'LineString';
    case Polygon = 'Polygon';
}
```

`src/Enum/PrimitiveType.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

/** Declared type of an object parameter. Values on the wire are always strings. */
enum PrimitiveType: string
{
    case String = 'string';
    case Number = 'number';
    case Date = 'date';
    case File = 'file';
}
```

`src/Enum/ConditionOperator.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

enum ConditionOperator: string
{
    case LogicalNot = 'NOT';
    case LogicalAnd = 'AND';
    case LogicalOr = 'OR';
    case Eq = 'EQ';
    case NotEq = 'NOT_EQ';
    case Lt = 'LT';
    case Lte = 'LTE';
    case Gt = 'GT';
    case Gte = 'GTE';
    case StartsWith = 'STARTS_WITH';
    case NotStartsWith = 'NOT_STARTS_WITH';
    case Contains = 'CONTAINS';
    case NotContains = 'NOT_CONTAINS';
    case InZone = 'IN_ZONE';
    case OutsideZone = 'OUTSIDE_ZONE';
}
```

`src/Enum/ConditionPrimitive.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

enum ConditionPrimitive: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Long = 'long';
    case Float = 'float';
    case Double = 'double';
    case NotApplicable = 'n/a';
}
```

- [ ] **Step 7: Implement the object DTOs**

`src/Dto/Parameter.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use DateTimeImmutable;
use DateTimeZone;
use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** A parameter value on an object. The value is always a string on the wire. */
final readonly class Parameter implements Hydratable
{
    public function __construct(
        /** Matches ParameterDefinition::$id. Use this, not $name, as the key. */
        public int $id,
        /** Display label at read time; may change. */
        public string $name,
        public string $value,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            id: Data::int($data, 'id', 'Parameter'),
            name: Data::string($data, 'name', 'Parameter'),
            value: Data::string($data, 'value', 'Parameter'),
        );
    }

    /** Parses a `YYYY-MM-DD` value as UTC midnight; null if it is not one. */
    public function asDate(): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->value, new DateTimeZone('UTC'));

        return ($date !== false && $date->format('Y-m-d') === $this->value) ? $date : null;
    }

    public function asFloat(): ?float
    {
        return is_numeric($this->value) ? (float) $this->value : null;
    }
}
```

`src/Dto/GpsObject.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** A tracked object (vehicle, asset, …). Named GpsObject because `object` is reserved in PHP. */
final readonly class GpsObject implements Hydratable
{
    /**
     * @param  list<string>  $tags
     * @param  list<Parameter>  $parameters
     */
    public function __construct(
        public string $objectId,
        /** Name of the object's type; null when the object has no type. */
        public ?string $objectType,
        public string $name,
        public ?string $comment,
        public array $tags,
        public array $parameters,
    ) {}

    public static function fromArray(array $data): static
    {
        $type = Data::nullableString($data, 'objectType', 'GpsObject');

        return new self(
            objectId: Data::string($data, 'objectID', 'GpsObject'),
            objectType: $type === '' ? null : $type,
            name: Data::string($data, 'name', 'GpsObject'),
            comment: Data::nullableString($data, 'comment', 'GpsObject'),
            tags: Data::stringList($data, 'tags', 'GpsObject'),
            parameters: Data::objects($data, 'parameters', 'GpsObject', Parameter::fromArray(...)),
        );
    }

    /** Finds a parameter by its stable definition id. */
    public function parameter(int $id): ?Parameter
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->id === $id) {
                return $parameter;
            }
        }

        return null;
    }

    public function parameterValue(int $id): ?string
    {
        return $this->parameter($id)?->value;
    }
}
```

`src/Dto/ParameterDefinition.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Enum\PrimitiveType;
use Ux2Dev\GpsBulgaria\Support\Data;

final readonly class ParameterDefinition implements Hydratable
{
    /** @param list<string>|null $values Allowed values for fixed-list parameters; null otherwise. */
    public function __construct(
        public int $id,
        public string $name,
        public PrimitiveType $type,
        public bool $required,
        public bool $readOnly,
        public ?array $values,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            id: Data::int($data, 'id', 'ParameterDefinition'),
            name: Data::string($data, 'name', 'ParameterDefinition'),
            type: Data::enum(PrimitiveType::class, $data, 'type', 'ParameterDefinition'),
            required: Data::bool($data, 'required', 'ParameterDefinition'),
            readOnly: Data::bool($data, 'readOnly', 'ParameterDefinition'),
            values: ($data['values'] ?? null) === null ? null : Data::stringList($data, 'values', 'ParameterDefinition'),
        );
    }
}
```

`src/Dto/ObjectType.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

final readonly class ObjectType implements Hydratable
{
    /** @param list<ParameterDefinition> $parameters */
    public function __construct(
        public string $name,
        public array $parameters,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            name: Data::string($data, 'name', 'ObjectType'),
            parameters: Data::objects($data, 'parameters', 'ObjectType', ParameterDefinition::fromArray(...)),
        );
    }

    public function definition(int $id): ?ParameterDefinition
    {
        foreach ($this->parameters as $definition) {
            if ($definition->id === $id) {
                return $definition;
            }
        }

        return null;
    }
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Support tests/Dto`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Support src/Enum src/Dto tests/Support tests/Dto tests/fixtures
git commit -m "feat(dto): add hydration helper, enums and object dtos"
```

---

### Task 6: Status and route DTOs

**Files:**
- Create: `src/Dto/Location.php`, `src/Dto/ObjectStatus.php`, `src/Dto/RoutePoint.php`, `src/Dto/Route.php`
- Create: `tests/fixtures/object-status.json`, `tests/fixtures/route.json`
- Test: `tests/Dto/StatusDtoTest.php`, `tests/Dto/RouteDtoTest.php`

**Interfaces:**
- Consumes: `Data` (Task 5).
- Produces:
  - `Location(float $latitude, float $longitude, float $angle)`.
  - `ObjectStatus(string $objectId, string $objectName, ?DateTimeImmutable $lastUpdate, ?Location $location, array<string,string> $sensorData)` with `sensor(string): ?string`, `speed(): ?float`, `odometer(): ?float` and `hasReported(): bool`.
  - `RoutePoint(?DateTimeImmutable $eventTs, float $latitude, float $longitude)`.
  - `Route(string $objectId, DateTimeImmutable $startedAt, ?DateTimeImmutable $endedAt, ?string $startAddress, ?string $endAddress, ?RoutePoint $startPoint, ?RoutePoint $endPoint, array<string,string> $aggregations, list<RoutePoint> $dataPoints)` with `isOpen()`, `mileage()`, `duration()`, `durationMoving()`, `durationIdle()`, `maxSpeed()`, `avgSpeed()`, `odometerAtStart()`, `odometerAtEnd()`, `fuelLevelAtStart()` and `fuelLevelAtEnd()`.
    - The four duration getters return `?int` (seconds). The rest return `?float`.

- [ ] **Step 1: Create the fixtures**

`tests/fixtures/object-status.json` (the spec example):
```json
{
    "objectID": "14574",
    "objectName": "Renault Clio",
    "lastUpdate": "2026-09-09T14:44:38Z",
    "location": {"latitude": 42.691143, "longitude": 23.352829, "angle": 89},
    "sensorData": {
        "altitude": "553", "key": "key_off", "km": "46371.8", "road_topology": "urban",
        "speed": "0", "speed_limit": "0", "total_odometer": "46371.800"
    }
}
```

`tests/fixtures/route.json` (assembled from the spec's property examples, with all 10 documented aggregation keys):
```json
{
    "objectID": "14574",
    "startedAt": "2026-08-01T07:10:00Z",
    "endedAt": "2026-08-01T08:02:13Z",
    "startAddress": "1 Vitosha Blvd, Sofia",
    "endAddress": "25 Tsarigradsko Shose, Sofia",
    "startPoint": {"eventTs": "2026-08-01T07:10:00Z", "latitude": 42.6977, "longitude": 23.3219},
    "endPoint": {"eventTs": "2026-08-01T08:02:13Z", "latitude": 42.6629, "longitude": 23.3815},
    "aggregations": {
        "mileage": "42.7", "duration": "3133", "duration_moving": "2900", "duration_idle": "233",
        "speed_max": "96", "speed_avg": "49.1", "odometer_at_start": "46329.1", "odometer_at_end": "46371.8",
        "fuel_level_at_start": "61", "fuel_level_at_end": "57"
    },
    "dataPoints": [
        {"eventTs": "2026-08-01T07:12:44Z", "latitude": 42.6977, "longitude": 23.3219},
        {"latitude": 42, "longitude": 23}
    ]
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Dto/StatusDtoTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\ObjectStatus;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

it('hydrates a status from the spec example', function () {
    $s = ObjectStatus::fromArray(fixture('object-status'));

    expect($s->objectId)->toBe('14574')
        ->and($s->objectName)->toBe('Renault Clio')
        ->and($s->lastUpdate?->format(DATE_ATOM))->toBe('2026-09-09T14:44:38+00:00')
        ->and($s->location?->latitude)->toBe(42.691143)
        ->and($s->location?->longitude)->toBe(23.352829)
        ->and($s->location?->angle)->toBe(89.0)
        ->and($s->sensorData['road_topology'])->toBe('urban')
        ->and($s->hasReported())->toBeTrue();
});

it('exposes sensor helpers', function () {
    $s = ObjectStatus::fromArray(fixture('object-status'));

    expect($s->sensor('key'))->toBe('key_off')
        ->and($s->sensor('missing'))->toBeNull()
        ->and($s->speed())->toBe(0.0)
        ->and($s->odometer())->toBe(46371.8);
});

it('handles an object that has never reported', function () {
    $s = ObjectStatus::fromArray([
        'objectID' => '1', 'objectName' => 'New unit', 'lastUpdate' => null, 'location' => null, 'sensorData' => [],
    ]);

    expect($s->hasReported())->toBeFalse()
        ->and($s->location)->toBeNull()
        ->and($s->speed())->toBeNull()
        ->and($s->odometer())->toBeNull();
});

it('returns null from numeric helpers for non-numeric readings', function () {
    $data = fixture('object-status');
    $data['sensorData']['speed'] = 'n/a';

    expect(ObjectStatus::fromArray($data)->speed())->toBeNull();
});

it('rejects non-string sensor values', function () {
    $data = fixture('object-status');
    $data['sensorData']['speed'] = 0;

    expect(fn () => ObjectStatus::fromArray($data))
        ->toThrow(InvalidResponseException::class, "ObjectStatus: field 'sensorData' must be a map of strings");
});
```

`tests/Dto/RouteDtoTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Route;

it('hydrates a route with points and addresses', function () {
    $r = Route::fromArray(fixture('route'));

    expect($r->objectId)->toBe('14574')
        ->and($r->startedAt->format(DATE_ATOM))->toBe('2026-08-01T07:10:00+00:00')
        ->and($r->endedAt?->format(DATE_ATOM))->toBe('2026-08-01T08:02:13+00:00')
        ->and($r->startAddress)->toBe('1 Vitosha Blvd, Sofia')
        ->and($r->endAddress)->toBe('25 Tsarigradsko Shose, Sofia')
        ->and($r->startPoint?->latitude)->toBe(42.6977)
        ->and($r->endPoint?->longitude)->toBe(23.3815)
        ->and($r->dataPoints)->toHaveCount(2)
        ->and($r->dataPoints[1]->eventTs)->toBeNull()
        ->and($r->dataPoints[1]->latitude)->toBe(42.0)
        ->and($r->isOpen())->toBeFalse();
});

it('exposes typed getters for every documented aggregation', function () {
    $r = Route::fromArray(fixture('route'));

    expect($r->mileage())->toBe(42.7)
        ->and($r->duration())->toBe(3133)
        ->and($r->durationMoving())->toBe(2900)
        ->and($r->durationIdle())->toBe(233)
        ->and($r->maxSpeed())->toBe(96.0)
        ->and($r->avgSpeed())->toBe(49.1)
        ->and($r->odometerAtStart())->toBe(46329.1)
        ->and($r->odometerAtEnd())->toBe(46371.8)
        ->and($r->fuelLevelAtStart())->toBe(61.0)
        ->and($r->fuelLevelAtEnd())->toBe(57.0);
});

it('handles a minimal open route without optional data', function () {
    $r = Route::fromArray([
        'objectID' => '14574', 'startedAt' => '2026-08-01T07:10:00Z', 'endedAt' => null, 'aggregations' => ['duration' => '12.6'],
    ]);

    expect($r->isOpen())->toBeTrue()
        ->and($r->startAddress)->toBeNull()
        ->and($r->startPoint)->toBeNull()
        ->and($r->dataPoints)->toBe([])
        ->and($r->duration())->toBe(13)
        ->and($r->mileage())->toBeNull();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Dto/StatusDtoTest.php tests/Dto/RouteDtoTest.php`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\Dto\ObjectStatus" not found`.

- [ ] **Step 4: Implement the DTOs**

`src/Dto/Location.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

final readonly class Location implements Hydratable
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        /** Heading in degrees, 0 to 360. */
        public float $angle,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            latitude: Data::float($data, 'latitude', 'Location'),
            longitude: Data::float($data, 'longitude', 'Location'),
            angle: Data::float($data, 'angle', 'Location'),
        );
    }
}
```

`src/Dto/ObjectStatus.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use DateTimeImmutable;
use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** Live status of an object: last position and the device's sensor readings. */
final readonly class ObjectStatus implements Hydratable
{
    /** @param array<string, string> $sensorData Readings by name; keys depend on the device. */
    public function __construct(
        public string $objectId,
        public string $objectName,
        public ?DateTimeImmutable $lastUpdate,
        public ?Location $location,
        public array $sensorData,
    ) {}

    public static function fromArray(array $data): static
    {
        $location = Data::nullableObject($data, 'location', 'ObjectStatus');

        return new self(
            objectId: Data::string($data, 'objectID', 'ObjectStatus'),
            objectName: Data::string($data, 'objectName', 'ObjectStatus'),
            lastUpdate: Data::nullableDateTime($data, 'lastUpdate', 'ObjectStatus'),
            location: $location === null ? null : Location::fromArray($location),
            sensorData: Data::stringMap($data, 'sensorData', 'ObjectStatus'),
        );
    }

    public function sensor(string $key): ?string
    {
        return $this->sensorData[$key] ?? null;
    }

    /** Current speed from the `speed` reading, km/h. */
    public function speed(): ?float
    {
        return self::number($this->sensor('speed'));
    }

    /** Total odometer from the `total_odometer` reading, km. */
    public function odometer(): ?float
    {
        return self::number($this->sensor('total_odometer'));
    }

    public function hasReported(): bool
    {
        return $this->lastUpdate !== null;
    }

    private static function number(?string $value): ?float
    {
        return ($value !== null && is_numeric($value)) ? (float) $value : null;
    }
}
```

`src/Dto/RoutePoint.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use DateTimeImmutable;
use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

final readonly class RoutePoint implements Hydratable
{
    public function __construct(
        public ?DateTimeImmutable $eventTs,
        public float $latitude,
        public float $longitude,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            eventTs: Data::nullableDateTime($data, 'eventTs', 'RoutePoint'),
            latitude: Data::float($data, 'latitude', 'RoutePoint'),
            longitude: Data::float($data, 'longitude', 'RoutePoint'),
        );
    }
}
```

`src/Dto/Route.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use DateTimeImmutable;
use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** One trip driven by an object. */
final readonly class Route implements Hydratable
{
    /**
     * @param  array<string, string>  $aggregations  Totals as strings; see the typed getters.
     * @param  list<RoutePoint>  $dataPoints  Only populated with includePoints=true.
     */
    public function __construct(
        public string $objectId,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public ?string $startAddress,
        public ?string $endAddress,
        public ?RoutePoint $startPoint,
        public ?RoutePoint $endPoint,
        public array $aggregations,
        public array $dataPoints,
    ) {}

    public static function fromArray(array $data): static
    {
        $start = Data::nullableObject($data, 'startPoint', 'Route');
        $end = Data::nullableObject($data, 'endPoint', 'Route');

        return new self(
            objectId: Data::string($data, 'objectID', 'Route'),
            startedAt: Data::dateTime($data, 'startedAt', 'Route'),
            endedAt: Data::nullableDateTime($data, 'endedAt', 'Route'),
            startAddress: Data::nullableString($data, 'startAddress', 'Route'),
            endAddress: Data::nullableString($data, 'endAddress', 'Route'),
            startPoint: $start === null ? null : RoutePoint::fromArray($start),
            endPoint: $end === null ? null : RoutePoint::fromArray($end),
            aggregations: Data::stringMap($data, 'aggregations', 'Route'),
            dataPoints: Data::objects($data, 'dataPoints', 'Route', RoutePoint::fromArray(...), required: false),
        );
    }

    /** True while the route is still being driven. */
    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function mileage(): ?float
    {
        return $this->number('mileage');
    }

    /** Seconds. */
    public function duration(): ?int
    {
        return $this->seconds('duration');
    }

    public function durationMoving(): ?int
    {
        return $this->seconds('duration_moving');
    }

    public function durationIdle(): ?int
    {
        return $this->seconds('duration_idle');
    }

    public function maxSpeed(): ?float
    {
        return $this->number('speed_max');
    }

    public function avgSpeed(): ?float
    {
        return $this->number('speed_avg');
    }

    public function odometerAtStart(): ?float
    {
        return $this->number('odometer_at_start');
    }

    public function odometerAtEnd(): ?float
    {
        return $this->number('odometer_at_end');
    }

    public function fuelLevelAtStart(): ?float
    {
        return $this->number('fuel_level_at_start');
    }

    public function fuelLevelAtEnd(): ?float
    {
        return $this->number('fuel_level_at_end');
    }

    private function number(string $key): ?float
    {
        $value = $this->aggregations[$key] ?? null;

        return ($value !== null && is_numeric($value)) ? (float) $value : null;
    }

    private function seconds(string $key): ?int
    {
        $value = $this->number($key);

        return $value === null ? null : (int) round($value);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Dto`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Dto tests/Dto tests/fixtures
git commit -m "feat(dto): add object status and route dtos with typed helpers"
```

---

### Task 7: Geometry, Zone, ZoneInput

**Files:**
- Create: `src/Dto/Geometry.php`, `src/Dto/Zone.php`, `src/Dto/ZoneInput.php`
- Create: `tests/fixtures/zone.json`
- Test: `tests/Dto/GeometryTest.php`, `tests/Dto/ZoneDtoTest.php`, `tests/Dto/ZoneInputTest.php`

**Interfaces:**
- Consumes: `Data`, `ZoneType`, `GeometryType` (Task 5).
- Produces:
  - `Geometry(GeometryType $type, array $coordinates)`, implementing `Hydratable`, with:
    - `static point(float $lat, float $lng): self`
    - `static lineString(list<array{0: float, 1: float}> $latLngs): self`
    - `static polygon(list<array{0: float, 1: float}> $latLngs): self`
    - `toArray(): array{type: string, coordinates: array}`
  - Factories throw `InvalidArgumentException` on invalid input.
  - `Zone(string $zoneId, ZoneType $zoneType, ?string $name, ?string $color, ?string $address, ?string $tag, bool $onMap, ?Geometry $geometry, ?float $radius, ?float $buffer)`.
  - `ZoneInput`, with a private constructor and:
    - `static circle(?string $name, float $lat, float $lng, float $radius)`
    - `static polygon(?string $name, list<array{float,float}> $latLngs)`
    - `static rectangle(?string $name, array{float,float} $southWest, array{float,float} $northEast)`
    - `static polyline(?string $name, list<array{float,float}> $latLngs, ?float $buffer = null)`
    - `withColor(string)`, `withAddress(string)`, `withTag(string)`, `onMap(bool $onMap = true)`
    - `toArray(): array<string, mixed>`

- [ ] **Step 1: Create the fixture**

`tests/fixtures/zone.json`:
```json
{
    "zoneID": "5930",
    "zoneType": "circle",
    "name": "Depot",
    "color": "#ff0000",
    "address": "1 Vitosha Blvd, Sofia",
    "tag": "depots",
    "onMap": true,
    "geometry": {"type": "Point", "coordinates": [23.3219, 42.6977]},
    "radius": 250
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Dto/GeometryTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Geometry;
use Ux2Dev\GpsBulgaria\Enum\GeometryType;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

it('builds a point with latitude first and emits GeoJSON order', function () {
    expect(Geometry::point(42.6977, 23.3219)->toArray())
        ->toBe(['type' => 'Point', 'coordinates' => [23.3219, 42.6977]]);
});

it('builds a line string', function () {
    expect(Geometry::lineString([[42.69, 23.32], [42.70, 23.33]])->toArray())
        ->toBe(['type' => 'LineString', 'coordinates' => [[23.32, 42.69], [23.33, 42.70]]]);
});

it('closes polygon rings automatically', function () {
    $open = Geometry::polygon([[42.69, 23.32], [42.69, 23.33], [42.70, 23.33]]);
    $closed = Geometry::polygon([[42.69, 23.32], [42.69, 23.33], [42.70, 23.33], [42.69, 23.32]]);

    $ring = [[23.32, 42.69], [23.33, 42.69], [23.33, 42.70], [23.32, 42.69]];
    expect($open->toArray())->toBe(['type' => 'Polygon', 'coordinates' => [$ring]])
        ->and($closed->toArray())->toBe(['type' => 'Polygon', 'coordinates' => [$ring]]);
});

it('rejects invalid factory input', function (callable $fn, string $message) {
    expect($fn)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'lat out of range' => [fn () => Geometry::point(91, 23), 'latitude must be between -90 and 90'],
    'lng out of range' => [fn () => Geometry::point(42, 181), 'longitude must be between -180 and 180'],
    'short line' => [fn () => Geometry::lineString([[42, 23]]), 'a line string needs at least 2 positions'],
    'degenerate polygon' => [fn () => Geometry::polygon([[42, 23], [42, 24], [42, 23]]), 'a polygon needs at least 3 distinct positions'],
    'bad position' => [fn () => Geometry::lineString([[42, 23], [42]]), 'each position must be [latitude, longitude]'],
]);

it('round-trips GeoJSON from the API', function () {
    $g = Geometry::fromArray(['type' => 'LineString', 'coordinates' => [[23.32, 42.69], [23.33, 42.70]]]);

    expect($g->type)->toBe(GeometryType::LineString)
        ->and($g->toArray())->toBe(['type' => 'LineString', 'coordinates' => [[23.32, 42.69], [23.33, 42.70]]]);
});

it('rejects unknown geometry types from the API', function () {
    expect(fn () => Geometry::fromArray(['type' => 'MultiPolygon', 'coordinates' => []]))
        ->toThrow(InvalidResponseException::class, "Geometry: field 'type' has unknown GeometryType value 'MultiPolygon'");
});
```

`tests/Dto/ZoneDtoTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Zone;
use Ux2Dev\GpsBulgaria\Enum\GeometryType;
use Ux2Dev\GpsBulgaria\Enum\ZoneType;

it('hydrates a zone with geometry', function () {
    $z = Zone::fromArray(fixture('zone'));

    expect($z->zoneId)->toBe('5930')
        ->and($z->zoneType)->toBe(ZoneType::Circle)
        ->and($z->name)->toBe('Depot')
        ->and($z->color)->toBe('#ff0000')
        ->and($z->address)->toBe('1 Vitosha Blvd, Sofia')
        ->and($z->tag)->toBe('depots')
        ->and($z->onMap)->toBeTrue()
        ->and($z->geometry?->type)->toBe(GeometryType::Point)
        ->and($z->radius)->toBe(250.0)
        ->and($z->buffer)->toBeNull();
});

it('hydrates a zone listed without geometry and with null labels', function () {
    $z = Zone::fromArray([
        'zoneID' => '14700', 'zoneType' => 'polyline', 'name' => null, 'color' => null,
        'address' => null, 'tag' => null, 'onMap' => false, 'buffer' => 30,
    ]);

    expect($z->geometry)->toBeNull()
        ->and($z->name)->toBeNull()
        ->and($z->buffer)->toBe(30.0)
        ->and($z->radius)->toBeNull();
});
```

`tests/Dto/ZoneInputTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\ZoneInput;

it('builds a circle matching the spec example', function () {
    $input = ZoneInput::circle('Depot', lat: 42.6977, lng: 23.3219, radius: 250)->withColor('#ff0000')->onMap();

    expect($input->toArray())->toBe([
        'zoneType' => 'circle',
        'geometry' => ['type' => 'Point', 'coordinates' => [23.3219, 42.6977]],
        'name' => 'Depot',
        'color' => '#ff0000',
        'radius' => 250.0,
        'onMap' => true,
    ]);
});

it('builds a closed polygon matching the spec example', function () {
    $input = ZoneInput::polygon('Yard', [[42.69, 23.32], [42.69, 23.33], [42.7, 23.33], [42.7, 23.32]]);

    expect($input->toArray())->toBe([
        'zoneType' => 'polygon',
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[23.32, 42.69], [23.33, 42.69], [23.33, 42.7], [23.32, 42.7], [23.32, 42.69]]]],
        'name' => 'Yard',
        'onMap' => false,
    ]);
});

it('builds a rectangle from two corners', function () {
    $input = ZoneInput::rectangle(null, southWest: [42.69, 23.32], northEast: [42.70, 23.33]);

    expect($input->toArray())->toBe([
        'zoneType' => 'rectangle',
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[23.32, 42.69], [23.33, 42.69], [23.33, 42.70], [23.32, 42.70], [23.32, 42.69]]]],
        'onMap' => false,
    ]);
});

it('builds a polyline with a buffer and optional labels', function () {
    $input = ZoneInput::polyline('Route A', [[42.69, 23.32], [42.70, 23.33]], buffer: 30)
        ->withAddress('Ring road')->withTag('routes')->onMap(false);

    expect($input->toArray())->toBe([
        'zoneType' => 'polyline',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[23.32, 42.69], [23.33, 42.70]]],
        'name' => 'Route A',
        'address' => 'Ring road',
        'tag' => 'routes',
        'buffer' => 30.0,
        'onMap' => false,
    ]);
});

it('is immutable', function () {
    $a = ZoneInput::circle('A', 42, 23, 10);
    $b = $a->withColor('#000000');

    expect($a->toArray())->not->toHaveKey('color')
        ->and($b->toArray()['color'])->toBe('#000000');
});

it('rejects invalid shapes', function (callable $fn, string $message) {
    expect($fn)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero radius' => [fn () => ZoneInput::circle('A', 42, 23, 0), 'radius must be greater than 0'],
    'negative buffer' => [fn () => ZoneInput::polyline('A', [[42, 23], [42, 24]], -1), 'buffer must not be negative'],
    'inverted rectangle' => [fn () => ZoneInput::rectangle('A', [42.7, 23.33], [42.69, 23.32]), 'southWest must be south-west of northEast'],
]);
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Dto/GeometryTest.php tests/Dto/ZoneDtoTest.php tests/Dto/ZoneInputTest.php`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\Dto\Geometry" not found`.

- [ ] **Step 4: Implement `Geometry`**

`src/Dto/Geometry.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use InvalidArgumentException;
use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Enum\GeometryType;
use Ux2Dev\GpsBulgaria\Support\Data;

/**
 * GeoJSON geometry. $coordinates is kept in GeoJSON order ([longitude,
 * latitude]); the factories take latitude first and convert for you.
 */
final readonly class Geometry implements Hydratable
{
    /** @param array<mixed> $coordinates */
    public function __construct(
        public GeometryType $type,
        public array $coordinates,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            type: Data::enum(GeometryType::class, $data, 'type', 'Geometry'),
            coordinates: Data::object($data, 'coordinates', 'Geometry'),
        );
    }

    public static function point(float $lat, float $lng): self
    {
        return new self(GeometryType::Point, self::position([$lat, $lng]));
    }

    /** @param list<array{0: float|int, 1: float|int}> $latLngs */
    public static function lineString(array $latLngs): self
    {
        if (count($latLngs) < 2) {
            throw new InvalidArgumentException('a line string needs at least 2 positions');
        }

        return new self(GeometryType::LineString, array_map(self::position(...), $latLngs));
    }

    /**
     * A single-ring polygon. The ring is closed automatically.
     *
     * @param  list<array{0: float|int, 1: float|int}>  $latLngs
     */
    public static function polygon(array $latLngs): self
    {
        $ring = array_map(self::position(...), $latLngs);

        if (count(array_unique(array_map('json_encode', $ring))) < 3) {
            throw new InvalidArgumentException('a polygon needs at least 3 distinct positions');
        }

        if ($ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return new self(GeometryType::Polygon, [$ring]);
    }

    /** @return array{type: string, coordinates: array<mixed>} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'coordinates' => $this->coordinates];
    }

    /**
     * @param  array<mixed>  $latLng
     * @return array{0: float, 1: float} [longitude, latitude]
     */
    private static function position(array $latLng): array
    {
        if (! array_is_list($latLng) || count($latLng) !== 2
            || ! (is_int($latLng[0]) || is_float($latLng[0])) || ! (is_int($latLng[1]) || is_float($latLng[1]))) {
            throw new InvalidArgumentException('each position must be [latitude, longitude]');
        }

        [$lat, $lng] = [(float) $latLng[0], (float) $latLng[1]];

        if ($lat < -90 || $lat > 90) {
            throw new InvalidArgumentException('latitude must be between -90 and 90');
        }

        if ($lng < -180 || $lng > 180) {
            throw new InvalidArgumentException('longitude must be between -180 and 180');
        }

        return [$lng, $lat];
    }
}
```

- [ ] **Step 5: Implement `Zone` and `ZoneInput`**

`src/Dto/Zone.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Enum\ZoneType;
use Ux2Dev\GpsBulgaria\Support\Data;

final readonly class Zone implements Hydratable
{
    public function __construct(
        public string $zoneId,
        public ZoneType $zoneType,
        public ?string $name,
        public ?string $color,
        public ?string $address,
        public ?string $tag,
        public bool $onMap,
        /** Only present when requested with includeGeometry=true. */
        public ?Geometry $geometry,
        /** Metres; circles only. */
        public ?float $radius,
        /** Metres around a polyline. */
        public ?float $buffer,
    ) {}

    public static function fromArray(array $data): static
    {
        $geometry = Data::nullableObject($data, 'geometry', 'Zone');

        return new self(
            zoneId: Data::string($data, 'zoneID', 'Zone'),
            zoneType: Data::enum(ZoneType::class, $data, 'zoneType', 'Zone'),
            name: Data::nullableString($data, 'name', 'Zone'),
            color: Data::nullableString($data, 'color', 'Zone'),
            address: Data::nullableString($data, 'address', 'Zone'),
            tag: Data::nullableString($data, 'tag', 'Zone'),
            onMap: Data::bool($data, 'onMap', 'Zone'),
            geometry: $geometry === null ? null : Geometry::fromArray($geometry),
            radius: Data::nullableFloat($data, 'radius', 'Zone'),
            buffer: Data::nullableFloat($data, 'buffer', 'Zone'),
        );
    }
}
```

`src/Dto/ZoneInput.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use InvalidArgumentException;
use Ux2Dev\GpsBulgaria\Enum\ZoneType;

/**
 * Body for zones()->create(). Build it with a named constructor so the
 * zone type, geometry and radius/buffer always agree.
 */
final readonly class ZoneInput
{
    private function __construct(
        private ZoneType $zoneType,
        private Geometry $geometry,
        private ?string $name,
        private ?float $radius = null,
        private ?float $buffer = null,
        private ?string $color = null,
        private ?string $address = null,
        private ?string $tag = null,
        private bool $onMap = false,
    ) {}

    /** @param float $radius Metres, > 0. */
    public static function circle(?string $name, float $lat, float $lng, float $radius): self
    {
        if ($radius <= 0) {
            throw new InvalidArgumentException('radius must be greater than 0');
        }

        return new self(ZoneType::Circle, Geometry::point($lat, $lng), $name, radius: $radius);
    }

    /** @param list<array{0: float|int, 1: float|int}> $latLngs [latitude, longitude] vertices; closed automatically. */
    public static function polygon(?string $name, array $latLngs): self
    {
        return new self(ZoneType::Polygon, Geometry::polygon($latLngs), $name);
    }

    /**
     * @param  array{0: float|int, 1: float|int}  $southWest  [latitude, longitude]
     * @param  array{0: float|int, 1: float|int}  $northEast  [latitude, longitude]
     */
    public static function rectangle(?string $name, array $southWest, array $northEast): self
    {
        [$south, $west] = $southWest;
        [$north, $east] = $northEast;

        if ($south >= $north || $west >= $east) {
            throw new InvalidArgumentException('southWest must be south-west of northEast');
        }

        $ring = [[$south, $west], [$south, $east], [$north, $east], [$north, $west]];

        return new self(ZoneType::Rectangle, Geometry::polygon($ring), $name);
    }

    /**
     * @param  list<array{0: float|int, 1: float|int}>  $latLngs  [latitude, longitude] positions.
     * @param  float|null  $buffer  Metres around the line.
     */
    public static function polyline(?string $name, array $latLngs, ?float $buffer = null): self
    {
        if ($buffer !== null && $buffer < 0) {
            throw new InvalidArgumentException('buffer must not be negative');
        }

        return new self(ZoneType::Polyline, Geometry::lineString($latLngs), $name, buffer: $buffer);
    }

    public function withColor(string $color): self
    {
        return $this->copy(color: $color);
    }

    public function withAddress(string $address): self
    {
        return $this->copy(address: $address);
    }

    public function withTag(string $tag): self
    {
        return $this->copy(tag: $tag);
    }

    public function onMap(bool $onMap = true): self
    {
        return $this->copy(onMap: $onMap);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'zoneType' => $this->zoneType->value,
            'geometry' => $this->geometry->toArray(),
            'name' => $this->name,
            'color' => $this->color,
            'address' => $this->address,
            'tag' => $this->tag,
            'radius' => $this->radius,
            'buffer' => $this->buffer,
            'onMap' => $this->onMap,
        ], static fn (mixed $v): bool => $v !== null);
    }

    private function copy(?string $color = null, ?string $address = null, ?string $tag = null, ?bool $onMap = null): self
    {
        return new self(
            zoneType: $this->zoneType,
            geometry: $this->geometry,
            name: $this->name,
            radius: $this->radius,
            buffer: $this->buffer,
            color: $color ?? $this->color,
            address: $address ?? $this->address,
            tag: $tag ?? $this->tag,
            onMap: $onMap ?? $this->onMap,
        );
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Dto`
Expected: PASS. If the `circle` test fails on key order, compare against `toArray()`: the order is `zoneType, geometry, name, color, address, tag, radius, buffer, onMap` with nulls removed, and the test expectations above follow that order.

- [ ] **Step 7: Commit**

```bash
git add src/Dto tests/Dto tests/fixtures
git commit -m "feat(dto): add geometry with lat-first factories, zone and zone input"
```

---

### Task 8: Alert DTOs and the path-segment guard

**Files:**
- Create: `src/Dto/ConditionNode.php`, `src/Dto/AlertRuleGroup.php`, `src/Dto/AlertRules.php`, `src/Dto/Alert.php`
- Create: `src/Support/Path.php`
- Create: `tests/fixtures/alert.json`
- Test: `tests/Dto/AlertDtoTest.php`, `tests/Support/PathTest.php`

**Interfaces:**
- Consumes: `Data`, `ConditionOperator`, `ConditionPrimitive` (Task 5).
- Produces:
  - `ConditionNode(ConditionOperator $op, list<ConditionNode> $children, ?string $field, string|int|float|bool|array|null $value, ?ConditionPrimitive $primitive)` with `isLogical()` and `isZoneOperator()`.
  - `AlertRuleGroup(int $durationSeconds, ConditionNode $rules)`.
  - `AlertRules(AlertRuleGroup $trigger, AlertRuleGroup $clear)`.
  - `Alert(string $eventId, string $objectId, string $reason, string $definitionId, DateTimeImmutable $triggeredAt, ?DateTimeImmutable $clearedAt, array<string,string> $sensorData, AlertRules $rules)` with `isActive()`.
  - `Support\Path::segment(string $id, string $name): string` returns `'/'.rawurlencode($id)`, and throws `InvalidArgumentException("{$name} must not be empty")` when `trim($id) === ''`.

- [ ] **Step 1: Create the fixture**

`tests/fixtures/alert.json` (the spec example):
```json
{
    "eventID": "6b1e9c2a-f1cd-4748-9d6a-4f7a6e9b2c21",
    "objectID": "14574",
    "reason": "Speeding in an urban area",
    "definitionID": "def-urban-speeding",
    "triggeredAt": "2026-09-09T14:44:38Z",
    "clearedAt": null,
    "sensorData": {"speed": "96", "speed_limit": "50", "road_topology": "urban", "km": "46371.8"},
    "rules": {
        "trigger": {
            "durationSeconds": 60,
            "rules": {
                "op": "AND",
                "children": [
                    {"op": "GT", "field": "speed", "primitive": "double", "value": 90},
                    {"op": "EQ", "field": "road_topology", "primitive": "string", "value": "urban"}
                ]
            }
        },
        "clear": {
            "durationSeconds": 0,
            "rules": {"op": "LTE", "field": "speed", "primitive": "double", "value": 90}
        }
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Dto/AlertDtoTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Alert;
use Ux2Dev\GpsBulgaria\Dto\ConditionNode;
use Ux2Dev\GpsBulgaria\Enum\ConditionOperator;
use Ux2Dev\GpsBulgaria\Enum\ConditionPrimitive;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

it('hydrates an alert with its recursive rule tree', function () {
    $a = Alert::fromArray(fixture('alert'));

    expect($a->eventId)->toBe('6b1e9c2a-f1cd-4748-9d6a-4f7a6e9b2c21')
        ->and($a->objectId)->toBe('14574')
        ->and($a->reason)->toBe('Speeding in an urban area')
        ->and($a->definitionId)->toBe('def-urban-speeding')
        ->and($a->triggeredAt->format(DATE_ATOM))->toBe('2026-09-09T14:44:38+00:00')
        ->and($a->clearedAt)->toBeNull()
        ->and($a->isActive())->toBeTrue()
        ->and($a->sensorData['speed'])->toBe('96');

    $trigger = $a->rules->trigger;
    expect($trigger->durationSeconds)->toBe(60)
        ->and($trigger->rules->op)->toBe(ConditionOperator::LogicalAnd)
        ->and($trigger->rules->isLogical())->toBeTrue()
        ->and($trigger->rules->children)->toHaveCount(2)
        ->and($trigger->rules->children[0]->op)->toBe(ConditionOperator::Gt)
        ->and($trigger->rules->children[0]->field)->toBe('speed')
        ->and($trigger->rules->children[0]->value)->toBe(90)
        ->and($trigger->rules->children[0]->primitive)->toBe(ConditionPrimitive::Double)
        ->and($trigger->rules->children[1]->value)->toBe('urban')
        ->and($a->rules->clear->durationSeconds)->toBe(0)
        ->and($a->rules->clear->rules->children)->toBe([]);
});

it('hydrates zone operators and list values', function () {
    $zone = ConditionNode::fromArray(['op' => 'IN_ZONE', 'value' => '5930']);
    $list = ConditionNode::fromArray(['op' => 'EQ', 'field' => 'key', 'primitive' => 'n/a', 'value' => ['key_on', 'key_off']]);

    expect($zone->isZoneOperator())->toBeTrue()
        ->and($zone->isLogical())->toBeFalse()
        ->and($zone->field)->toBeNull()
        ->and($zone->primitive)->toBeNull()
        ->and($list->value)->toBe(['key_on', 'key_off'])
        ->and($list->primitive)->toBe(ConditionPrimitive::NotApplicable);
});

it('rejects an object as a condition value', function () {
    expect(fn () => ConditionNode::fromArray(['op' => 'EQ', 'value' => ['a' => 1]]))
        ->toThrow(InvalidResponseException::class, "ConditionNode: field 'value' must be a scalar or a list of scalars");
});

it('marks a cleared alert inactive', function () {
    $data = fixture('alert');
    $data['clearedAt'] = '2026-09-09T15:00:00Z';

    expect(Alert::fromArray($data)->isActive())->toBeFalse();
});
```

`tests/Support/PathTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Support\Path;

it('encodes a path segment', function () {
    expect(Path::segment('14574', 'objectId'))->toBe('/14574')
        ->and(Path::segment('a/b c', 'objectId'))->toBe('/a%2Fb%20c');
});

it('rejects empty and blank ids before a request is built', function (string $id) {
    expect(fn () => Path::segment($id, 'objectId'))->toThrow(InvalidArgumentException::class, 'objectId must not be empty');
})->with(['', '   ']);
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Dto/AlertDtoTest.php tests/Support/PathTest.php`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\Dto\Alert" not found`.

- [ ] **Step 4: Implement `Path` and the alert DTOs**

`src/Support/Path.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Support;

use InvalidArgumentException;

/** @internal Builds URL path segments from caller-supplied ids. */
final class Path
{
    /**
     * An empty id would turn `/objects/{id}` into `/objects/`, which is the
     * list endpoint, so it is rejected before any request is sent.
     */
    public static function segment(string $id, string $name): string
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException("{$name} must not be empty");
        }

        return '/'.rawurlencode($id);
    }
}
```

`src/Dto/ConditionNode.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Enum\ConditionOperator;
use Ux2Dev\GpsBulgaria\Enum\ConditionPrimitive;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Support\Data;

/**
 * One node of an alert condition tree. Logical nodes (NOT/AND/OR) carry
 * children; comparisons carry field/value/primitive; zone operators carry
 * only a value.
 *
 * @experimental The alerts API is not yet live upstream.
 */
final readonly class ConditionNode implements Hydratable
{
    /**
     * @param  list<ConditionNode>  $children
     * @param  string|int|float|bool|list<string|int|float|bool>|null  $value
     */
    public function __construct(
        public ConditionOperator $op,
        public array $children,
        public ?string $field,
        public string|int|float|bool|array|null $value,
        public ?ConditionPrimitive $primitive,
    ) {}

    public static function fromArray(array $data): static
    {
        $primitive = Data::nullableString($data, 'primitive', 'ConditionNode');

        return new self(
            op: Data::enum(ConditionOperator::class, $data, 'op', 'ConditionNode'),
            children: Data::objects($data, 'children', 'ConditionNode', self::fromArray(...), required: false),
            field: Data::nullableString($data, 'field', 'ConditionNode'),
            value: self::value($data['value'] ?? null),
            primitive: $primitive === null ? null : Data::enum(ConditionPrimitive::class, $data, 'primitive', 'ConditionNode'),
        );
    }

    public function isLogical(): bool
    {
        return in_array($this->op, [ConditionOperator::LogicalNot, ConditionOperator::LogicalAnd, ConditionOperator::LogicalOr], true);
    }

    public function isZoneOperator(): bool
    {
        return $this->op === ConditionOperator::InZone || $this->op === ConditionOperator::OutsideZone;
    }

    /** @return string|int|float|bool|list<string|int|float|bool>|null */
    private static function value(mixed $value): string|int|float|bool|array|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value) && array_is_list($value) && array_filter($value, is_scalar(...)) === $value) {
            /** @var list<string|int|float|bool> $value */
            return $value;
        }

        throw new InvalidResponseException("ConditionNode: field 'value' must be a scalar or a list of scalars");
    }
}
```

`src/Dto/AlertRuleGroup.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** @experimental The alerts API is not yet live upstream. */
final readonly class AlertRuleGroup implements Hydratable
{
    public function __construct(
        /** How long the rules must hold before taking effect. */
        public int $durationSeconds,
        public ConditionNode $rules,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            durationSeconds: Data::int($data, 'durationSeconds', 'AlertRuleGroup'),
            rules: ConditionNode::fromArray(Data::object($data, 'rules', 'AlertRuleGroup')),
        );
    }
}
```

`src/Dto/AlertRules.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** @experimental The alerts API is not yet live upstream. */
final readonly class AlertRules implements Hydratable
{
    public function __construct(
        public AlertRuleGroup $trigger,
        public AlertRuleGroup $clear,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            trigger: AlertRuleGroup::fromArray(Data::object($data, 'trigger', 'AlertRules')),
            clear: AlertRuleGroup::fromArray(Data::object($data, 'clear', 'AlertRules')),
        );
    }
}
```

`src/Dto/Alert.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use DateTimeImmutable;
use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** @experimental The alerts API is not yet live upstream; the shape follows the published spec. */
final readonly class Alert implements Hydratable
{
    /** @param array<string, string> $sensorData Readings at the moment the alert was raised. */
    public function __construct(
        public string $eventId,
        public string $objectId,
        public string $reason,
        public string $definitionId,
        public DateTimeImmutable $triggeredAt,
        public ?DateTimeImmutable $clearedAt,
        public array $sensorData,
        public AlertRules $rules,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            eventId: Data::string($data, 'eventID', 'Alert'),
            objectId: Data::string($data, 'objectID', 'Alert'),
            reason: Data::string($data, 'reason', 'Alert'),
            definitionId: Data::string($data, 'definitionID', 'Alert'),
            triggeredAt: Data::dateTime($data, 'triggeredAt', 'Alert'),
            clearedAt: Data::nullableDateTime($data, 'clearedAt', 'Alert'),
            sensorData: Data::stringMap($data, 'sensorData', 'Alert'),
            rules: AlertRules::fromArray(Data::object($data, 'rules', 'Alert')),
        );
    }

    public function isActive(): bool
    {
        return $this->clearedAt === null;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Dto tests/Support`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Dto src/Support tests/Dto tests/Support tests/fixtures
git commit -m "feat(dto): add experimental alert dtos and path segment guard"
```

---

### Task 9: Root client, objects and object-types resources

**Files:**
- Create: `src/GpsBulgaria.php`, `src/Resource/ObjectsResource.php`, `src/Resource/ObjectTypesResource.php`
- Create stubs (filled in by Task 10): `src/Resource/ZonesResource.php`, `src/Resource/AlertsResource.php`
- Test: `tests/GpsBulgariaTest.php`, `tests/Resource/ObjectsResourceTest.php`, `tests/Resource/ObjectTypesResourceTest.php`

**Interfaces:**
- Consumes: `Transport::request()` (Task 3), `Data::rows()` (Task 5), `Path::segment()` (Task 8), and the DTOs `GpsObject`, `ObjectStatus`, `Route`, `ObjectType`.
- Produces:
  - `final class GpsBulgaria`: `__construct(GpsBulgariaConfig, ClientInterface, RequestFactoryInterface, StreamFactoryInterface)` plus `objects(): ObjectsResource`, `objectTypes(): ObjectTypesResource`, `zones(): ZonesResource` and `alerts(): AlertsResource`.
  - `final class ObjectsResource(Transport $transport)` with:
    - `list(): list<GpsObject>`
    - `get(string $objectId): GpsObject`
    - `statuses(): list<ObjectStatus>`
    - `status(string $objectId): ObjectStatus`
    - `routes(string $objectId, DateTimeInterface $from, DateTimeInterface $to, bool $includeAddresses = false, bool $includePoints = false): list<Route>`
  - `final class ObjectTypesResource(Transport $transport)` with `list(): list<ObjectType>`.

- [ ] **Step 1: Write the failing tests**

`tests/Resource/ObjectsResourceTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\GpsObject;
use Ux2Dev\GpsBulgaria\Dto\ObjectStatus;
use Ux2Dev\GpsBulgaria\Dto\Route;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Exception\NotFoundException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('lists objects', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [fixture('object')])]);

    $objects = gps($http)->objects()->list();

    expect($objects)->toHaveCount(1)
        ->and($objects[0])->toBeInstanceOf(GpsObject::class)
        ->and($http->captured[0]->getMethod())->toBe('GET')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects');
});

it('gets one object with an encoded id', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, fixture('object'))]);

    $object = gps($http)->objects()->get('14574');

    expect($object->objectId)->toBe('14574')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects/14574');
});

it('surfaces a 404 as NotFoundException', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(404, ['code' => 'NOT_FOUND', 'message' => 'object not found'])]);

    expect(fn () => gps($http)->objects()->get('999'))->toThrow(NotFoundException::class, 'object not found');
});

it('lists statuses and gets one status', function () {
    $http = new FakeHttpClient([
        FakeHttpClient::json(200, [fixture('object-status')]),
        FakeHttpClient::json(200, fixture('object-status')),
    ]);
    $objects = gps($http)->objects();

    expect($objects->statuses()[0])->toBeInstanceOf(ObjectStatus::class)
        ->and($objects->status('14574')->objectId)->toBe('14574')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects/statuses')
        ->and((string) $http->captured[1]->getUri())->toBe('https://iot.gps.bg/api/v2/objects/14574/status');
});

it('lists routes with a UTC time range and flags', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [fixture('route')])]);
    $from = new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('Europe/Sofia'));
    $to = new DateTimeImmutable('2026-08-07T23:59:59Z');

    $routes = gps($http)->objects()->routes('14574', $from, $to, includeAddresses: true);

    $uri = $http->captured[0]->getUri();
    expect($routes[0])->toBeInstanceOf(Route::class)
        ->and($uri->getPath())->toBe('/api/v2/objects/14574/routes')
        ->and(urldecode($uri->getQuery()))
        ->toBe('from=2026-07-31T21:00:00Z&to=2026-08-07T23:59:59Z&includeAddresses=true&includePoints=false');
});

it('rejects a reversed time range before sending', function () {
    $http = new FakeHttpClient;

    expect(fn () => gps($http)->objects()->routes('14574', new DateTimeImmutable('2026-08-07'), new DateTimeImmutable('2026-08-01')))
        ->toThrow(InvalidArgumentException::class, 'from must not be after to');
    expect($http->captured)->toBe([]);
});

it('rejects empty ids before sending', function (callable $call) {
    $http = new FakeHttpClient;

    expect(fn () => $call(gps($http)->objects()))->toThrow(InvalidArgumentException::class, 'objectId must not be empty');
    expect($http->captured)->toBe([]);
})->with([
    'get' => [fn ($o) => $o->get('')],
    'status' => [fn ($o) => $o->status(' ')],
    'routes' => [fn ($o) => $o->routes('', new DateTimeImmutable, new DateTimeImmutable)],
]);

it('rejects an object response where a list was expected', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, fixture('object'))]);

    expect(fn () => gps($http)->objects()->list())
        ->toThrow(InvalidResponseException::class, 'GpsObject: expected a JSON array of objects');
});
```

`tests/Resource/ObjectTypesResourceTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\ObjectType;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('lists object types', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [fixture('object-type')])]);

    $types = gps($http)->objectTypes()->list();

    expect($types[0])->toBeInstanceOf(ObjectType::class)
        ->and($types[0]->name)->toBe('Vehicle')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/object-types');
});
```

`tests/GpsBulgariaTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Resource\AlertsResource;
use Ux2Dev\GpsBulgaria\Resource\ObjectsResource;
use Ux2Dev\GpsBulgaria\Resource\ObjectTypesResource;
use Ux2Dev\GpsBulgaria\Resource\ZonesResource;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('exposes each resource lazily and caches it', function () {
    $gps = gps(new FakeHttpClient);

    expect($gps->objects())->toBeInstanceOf(ObjectsResource::class)->toBe($gps->objects())
        ->and($gps->objectTypes())->toBeInstanceOf(ObjectTypesResource::class)->toBe($gps->objectTypes())
        ->and($gps->zones())->toBeInstanceOf(ZonesResource::class)->toBe($gps->zones())
        ->and($gps->alerts())->toBeInstanceOf(AlertsResource::class)->toBe($gps->alerts());
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/GpsBulgariaTest.php tests/Resource`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\GpsBulgaria" not found`.

- [ ] **Step 3: Implement the resources and root client**

`src/Resource/ObjectsResource.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use DateTimeInterface;
use InvalidArgumentException;
use Ux2Dev\GpsBulgaria\Dto\GpsObject;
use Ux2Dev\GpsBulgaria\Dto\ObjectStatus;
use Ux2Dev\GpsBulgaria\Dto\Route;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Support\Data;
use Ux2Dev\GpsBulgaria\Support\Path;

final class ObjectsResource
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /objects: your objects with type, tags and parameter values.
     *
     * @return list<GpsObject>
     */
    public function list(): array
    {
        return array_map(GpsObject::fromArray(...), Data::rows($this->transport->request('GET', '/objects'), 'GpsObject'));
    }

    /** GET /objects/{objectID} */
    public function get(string $objectId): GpsObject
    {
        return GpsObject::fromArray($this->transport->request('GET', '/objects'.Path::segment($objectId, 'objectId')));
    }

    /**
     * GET /objects/statuses: live status of every object.
     *
     * @return list<ObjectStatus>
     */
    public function statuses(): array
    {
        return array_map(ObjectStatus::fromArray(...), Data::rows($this->transport->request('GET', '/objects/statuses'), 'ObjectStatus'));
    }

    /** GET /objects/{objectID}/status */
    public function status(string $objectId): ObjectStatus
    {
        return ObjectStatus::fromArray($this->transport->request('GET', '/objects'.Path::segment($objectId, 'objectId').'/status'));
    }

    /**
     * GET /objects/{objectID}/routes: trips driven between $from and $to.
     * Times are sent as UTC instants regardless of the input's time zone.
     *
     * @return list<Route>
     */
    public function routes(
        string $objectId,
        DateTimeInterface $from,
        DateTimeInterface $to,
        bool $includeAddresses = false,
        bool $includePoints = false,
    ): array {
        $path = '/objects'.Path::segment($objectId, 'objectId').'/routes';

        if ($from > $to) {
            throw new InvalidArgumentException('from must not be after to');
        }

        $rows = $this->transport->request('GET', $path, [
            'from' => $from,
            'to' => $to,
            'includeAddresses' => $includeAddresses,
            'includePoints' => $includePoints,
        ]);

        return array_map(Route::fromArray(...), Data::rows($rows, 'Route'));
    }
}
```

`src/Resource/ObjectTypesResource.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use Ux2Dev\GpsBulgaria\Dto\ObjectType;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Support\Data;

final class ObjectTypesResource
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /object-types: type definitions that describe object parameters.
     *
     * @return list<ObjectType>
     */
    public function list(): array
    {
        return array_map(ObjectType::fromArray(...), Data::rows($this->transport->request('GET', '/object-types'), 'ObjectType'));
    }
}
```

`src/Resource/ZonesResource.php` and `src/Resource/AlertsResource.php` are temporary stubs. Task 10 adds their methods:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use Ux2Dev\GpsBulgaria\Http\Transport;

final class ZonesResource
{
    public function __construct(private readonly Transport $transport) {}
}
```
(`AlertsResource` is identical apart from the class name.)

`src/GpsBulgaria.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Resource\AlertsResource;
use Ux2Dev\GpsBulgaria\Resource\ObjectsResource;
use Ux2Dev\GpsBulgaria\Resource\ObjectTypesResource;
use Ux2Dev\GpsBulgaria\Resource\ZonesResource;

/**
 * Entry point for the GPS Bulgaria IoT API v2. One instance per API key.
 */
final class GpsBulgaria
{
    private readonly Transport $transport;

    private ?ObjectsResource $objects = null;

    private ?ObjectTypesResource $objectTypes = null;

    private ?ZonesResource $zones = null;

    private ?AlertsResource $alerts = null;

    public function __construct(
        GpsBulgariaConfig $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
    ) {
        $this->transport = new Transport($config, $httpClient, $requestFactory, $streamFactory);
    }

    public function objects(): ObjectsResource
    {
        return $this->objects ??= new ObjectsResource($this->transport);
    }

    public function objectTypes(): ObjectTypesResource
    {
        return $this->objectTypes ??= new ObjectTypesResource($this->transport);
    }

    public function zones(): ZonesResource
    {
        return $this->zones ??= new ZonesResource($this->transport);
    }

    /** @experimental The alerts API is not yet live upstream. */
    public function alerts(): AlertsResource
    {
        return $this->alerts ??= new AlertsResource($this->transport);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS (the whole suite).

- [ ] **Step 5: Commit**

```bash
git add src/GpsBulgaria.php src/Resource tests/GpsBulgariaTest.php tests/Resource
git commit -m "feat(client): add root client with objects and object types resources"
```

---

### Task 10: Zones and alerts resources

**Files:**
- Modify: `src/Resource/ZonesResource.php`, `src/Resource/AlertsResource.php` (replace the stubs)
- Test: `tests/Resource/ZonesResourceTest.php`, `tests/Resource/AlertsResourceTest.php`

**Interfaces:**
- Consumes: `Transport`, `Data::rows()`, `Path::segment()`, `Zone`, `ZoneInput::toArray()`, `Alert`.
- Produces:
  - `ZonesResource` with:
    - `list(bool $includeGeometry = false): list<Zone>`
    - `get(string $zoneId, bool $includeGeometry = false): Zone`
    - `search(list<string> $zoneIds, bool $includeGeometry = false): list<Zone>`
    - `create(ZoneInput $input): Zone`
  - `AlertsResource` with:
    - `list(?DateTimeInterface $from = null, ?DateTimeInterface $to = null): list<Alert>`
    - `forObject(string $objectId, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): list<Alert>`

- [ ] **Step 1: Write the failing tests**

`tests/Resource/ZonesResourceTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Dto\ZoneInput;
use Ux2Dev\GpsBulgaria\Exception\PermissionDeniedException;
use Ux2Dev\GpsBulgaria\Exception\ServiceUnavailableException;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('lists zones without geometry by default', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [fixture('zone')])]);

    $zones = gps($http)->zones()->list();

    expect($zones[0]->zoneId)->toBe('5930')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/zones?includeGeometry=false');
});

it('gets one zone with geometry', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, fixture('zone'))]);

    $zone = gps($http)->zones()->get('5930', includeGeometry: true);

    expect($zone->geometry)->not->toBeNull()
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/zones/5930?includeGeometry=true');
});

it('searches zones by id with a JSON body', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [fixture('zone')])]);

    gps($http)->zones()->search(['5930', '14700']);

    $r = $http->captured[0];
    expect($r->getMethod())->toBe('POST')
        ->and((string) $r->getUri())->toBe('https://iot.gps.bg/api/v2/zones/search?includeGeometry=false')
        ->and((string) $r->getBody())->toBe('{"zoneIDs":["5930","14700"]}');
});

it('rejects an empty search before sending', function () {
    $http = new FakeHttpClient;

    expect(fn () => gps($http)->zones()->search([]))->toThrow(InvalidArgumentException::class, 'zoneIds must not be empty');
    expect($http->captured)->toBe([]);
});

it('rejects an empty zone id before sending', function () {
    $http = new FakeHttpClient;

    expect(fn () => gps($http)->zones()->get(''))->toThrow(InvalidArgumentException::class, 'zoneId must not be empty');
    expect($http->captured)->toBe([]);
});

it('creates a zone from a ZoneInput', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(201, fixture('zone'))]);

    $zone = gps($http)->zones()->create(ZoneInput::circle('Depot', 42.6977, 23.3219, 250)->withColor('#ff0000')->onMap());

    $r = $http->captured[0];
    expect($zone->zoneId)->toBe('5930')
        ->and($r->getMethod())->toBe('POST')
        ->and((string) $r->getUri())->toBe('https://iot.gps.bg/api/v2/zones')
        ->and(json_decode((string) $r->getBody(), true))->toBe([
            'zoneType' => 'circle',
            'geometry' => ['type' => 'Point', 'coordinates' => [23.3219, 42.6977]],
            'name' => 'Depot',
            'color' => '#ff0000',
            'radius' => 250.0,
            'onMap' => true,
        ])
        ->and((string) $r->getBody())->toContain('"radius":250.0');
});

it('surfaces a 403 on create', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(403, ['code' => 'FORBIDDEN', 'message' => 'not permitted to create zones'])]);

    expect(fn () => gps($http)->zones()->create(ZoneInput::circle('A', 42, 23, 10)))
        ->toThrow(PermissionDeniedException::class, 'not permitted to create zones');
});

it('does not retry create even with a retry policy', function () {
    $http = new FakeHttpClient([
        FakeHttpClient::json(503, ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'x']),
        FakeHttpClient::json(201, fixture('zone')),
    ]);

    expect(fn () => gps($http, RetryPolicy::attempts(3))->zones()->create(ZoneInput::circle('A', 42, 23, 10)))
        ->toThrow(ServiceUnavailableException::class);
    expect($http->captured)->toHaveCount(1);
});
```

`tests/Resource/AlertsResourceTest.php`:
```php
<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Alert;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

it('lists alerts for all objects without a range', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [fixture('alert')])]);

    $alerts = gps($http)->alerts()->list();

    expect($alerts[0])->toBeInstanceOf(Alert::class)
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects/alerts');
});

it('lists alerts for one object within a range', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

    $alerts = gps($http)->alerts()->forObject('14574', new DateTimeImmutable('2026-08-01T00:00:00Z'), new DateTimeImmutable('2026-08-07T23:59:59Z'));

    $uri = $http->captured[0]->getUri();
    expect($alerts)->toBe([])
        ->and($uri->getPath())->toBe('/api/v2/objects/14574/alerts')
        ->and(urldecode($uri->getQuery()))->toBe('from=2026-08-01T00:00:00Z&to=2026-08-07T23:59:59Z');
});

it('rejects a reversed range and an empty object id', function () {
    $http = new FakeHttpClient;
    $alerts = gps($http)->alerts();

    expect(fn () => $alerts->list(new DateTimeImmutable('2026-08-07'), new DateTimeImmutable('2026-08-01')))
        ->toThrow(InvalidArgumentException::class, 'from must not be after to');
    expect(fn () => $alerts->forObject(''))->toThrow(InvalidArgumentException::class, 'objectId must not be empty');
    expect($http->captured)->toBe([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Resource/ZonesResourceTest.php tests/Resource/AlertsResourceTest.php`
Expected: FAIL with `Call to undefined method Ux2Dev\GpsBulgaria\Resource\ZonesResource::list()`.

- [ ] **Step 3: Implement the resources**

`src/Resource/ZonesResource.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use InvalidArgumentException;
use Ux2Dev\GpsBulgaria\Dto\Zone;
use Ux2Dev\GpsBulgaria\Dto\ZoneInput;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Support\Data;
use Ux2Dev\GpsBulgaria\Support\Path;

final class ZonesResource
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /zones
     *
     * @return list<Zone>
     */
    public function list(bool $includeGeometry = false): array
    {
        $rows = $this->transport->request('GET', '/zones', ['includeGeometry' => $includeGeometry]);

        return array_map(Zone::fromArray(...), Data::rows($rows, 'Zone'));
    }

    /** GET /zones/{zoneID} */
    public function get(string $zoneId, bool $includeGeometry = false): Zone
    {
        return Zone::fromArray($this->transport->request(
            'GET',
            '/zones'.Path::segment($zoneId, 'zoneId'),
            ['includeGeometry' => $includeGeometry],
        ));
    }

    /**
     * POST /zones/search: the zones matching the given ids.
     *
     * @param  list<string>  $zoneIds
     * @return list<Zone>
     */
    public function search(array $zoneIds, bool $includeGeometry = false): array
    {
        if ($zoneIds === []) {
            throw new InvalidArgumentException('zoneIds must not be empty');
        }

        $rows = $this->transport->request(
            'POST',
            '/zones/search',
            ['includeGeometry' => $includeGeometry],
            ['zoneIDs' => array_values($zoneIds)],
        );

        return array_map(Zone::fromArray(...), Data::rows($rows, 'Zone'));
    }

    /** POST /zones: never retried, as creation is not idempotent. */
    public function create(ZoneInput $input): Zone
    {
        return Zone::fromArray($this->transport->request('POST', '/zones', [], $input->toArray()));
    }
}
```

`src/Resource/AlertsResource.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use DateTimeInterface;
use InvalidArgumentException;
use Ux2Dev\GpsBulgaria\Dto\Alert;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Support\Data;
use Ux2Dev\GpsBulgaria\Support\Path;

/**
 * @experimental Upstream marks these endpoints "not yet available; planned
 * for a later release". Shapes follow the published spec and may change.
 */
final class AlertsResource
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /objects/alerts: alerts on any of your objects.
     *
     * @return list<Alert>
     */
    public function list(?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        return $this->fetch('/objects/alerts', $from, $to);
    }

    /**
     * GET /objects/{objectID}/alerts
     *
     * @return list<Alert>
     */
    public function forObject(string $objectId, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        return $this->fetch('/objects'.Path::segment($objectId, 'objectId').'/alerts', $from, $to);
    }

    /** @return list<Alert> */
    private function fetch(string $path, ?DateTimeInterface $from, ?DateTimeInterface $to): array
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('from must not be after to');
        }

        $rows = $this->transport->request('GET', $path, ['from' => $from, 'to' => $to]);

        return array_map(Alert::fromArray(...), Data::rows($rows, 'Alert'));
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Resource tests/Resource
git commit -m "feat(resources): add zones and experimental alerts resources"
```

---

### Task 11: Laravel integration

**Files:**
- Create: `src/Laravel/config/gps-bulgaria.php`, `src/Laravel/GpsBulgariaManager.php`, `src/Laravel/GpsBulgariaServiceProvider.php`, `src/Laravel/Facades/GpsBulgaria.php`
- Modify: `tests/Laravel/TestCase.php` (replace the placeholder)
- Test: `tests/Laravel/ManagerTest.php`, `tests/Laravel/ServiceProviderTest.php`

**Interfaces:**
- Consumes: `GpsBulgaria`, `GpsBulgariaConfig`, `RetryPolicy` and `ConfigurationException`.
- Produces:
  - `final class GpsBulgariaManager(array $config, ?ClientInterface $httpClient = null, ?RequestFactoryInterface $requestFactory = null, ?StreamFactoryInterface $streamFactory = null)` with `tenant(string): self`, `currentTenant(): string`, `client(): GpsBulgaria`, `forKey(string $apiKey, array $overrides = []): GpsBulgaria`, and `__call` forwarding.
  - Facade accessor `'gps-bulgaria'`.

- [ ] **Step 1: Replace the Laravel TestCase**

`tests/Laravel/TestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Tests\Laravel;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Ux2Dev\GpsBulgaria\Laravel\Facades\GpsBulgaria;
use Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [GpsBulgariaServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['GpsBulgaria' => GpsBulgaria::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('gps-bulgaria.default', 'main');
        $app['config']->set('gps-bulgaria.tenants.main', [
            'api_key' => 'key_main',
            'base_url' => 'https://iot.gps.bg/api/v2',
            'timeout' => 30,
            'retry' => 3,
        ]);
        $app['config']->set('gps-bulgaria.tenants.other', [
            'api_key' => 'key_other',
            'base_url' => 'https://staging.example.test/api/v2',
            'timeout' => 10,
            'retry' => 1,
        ]);
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Laravel/ManagerTest.php`:
```php
<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Ux2Dev\GpsBulgaria\Exception\ConfigurationException;
use Ux2Dev\GpsBulgaria\GpsBulgaria;
use Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaManager;
use Ux2Dev\GpsBulgaria\Resource\ObjectsResource;
use Ux2Dev\GpsBulgaria\Tests\Support\FakeHttpClient;

function manager(FakeHttpClient $http, array $config): GpsBulgariaManager
{
    $factory = new HttpFactory;

    return new GpsBulgariaManager($config, $http, $factory, $factory);
}

function tenants(): array
{
    return [
        'default' => 'main',
        'tenants' => [
            'main' => ['api_key' => 'key_main', 'timeout' => 30, 'retry' => 1],
            'other' => ['api_key' => 'key_other', 'base_url' => 'https://staging.example.test/api/v2'],
            'blank' => ['api_key' => ''],
        ],
    ];
}

it('resolves the default tenant and caches its client', function () {
    $m = manager(new FakeHttpClient, tenants());

    expect($m->currentTenant())->toBe('main')
        ->and($m->client())->toBeInstanceOf(GpsBulgaria::class)
        ->and($m->client())->toBe($m->client());
});

it('switches tenants immutably', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, []), FakeHttpClient::json(200, [])]);
    $m = manager($http, tenants());

    $other = $m->tenant('other');
    $other->objects()->list();
    $m->objects()->list();

    expect($m->currentTenant())->toBe('main')
        ->and($other->currentTenant())->toBe('other')
        ->and($http->captured[0]->getHeaderLine('X-API-Key'))->toBe('key_other')
        ->and((string) $http->captured[0]->getUri())->toBe('https://staging.example.test/api/v2/objects')
        ->and($http->captured[1]->getHeaderLine('X-API-Key'))->toBe('key_main');
});

it('forwards resource accessors to the current client', function () {
    expect(manager(new FakeHttpClient, tenants())->objects())->toBeInstanceOf(ObjectsResource::class);
});

it('rejects unknown tenants and tenants without a key', function () {
    $m = manager(new FakeHttpClient, tenants());

    expect(fn () => $m->tenant('nope')->client())->toThrow(ConfigurationException::class, 'GPS Bulgaria tenant "nope" is not configured')
        ->and(fn () => $m->tenant('blank')->client())->toThrow(ConfigurationException::class, 'apiKey must not be empty');
});

it('builds an uncached client for a runtime key, inheriting default settings', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, []), FakeHttpClient::json(200, [])]);
    $m = manager($http, tenants());

    $a = $m->forKey('customer-key');
    $b = $m->forKey('customer-key');
    $a->objects()->list();
    $m->forKey('customer-key-2', ['base_url' => 'https://staging.example.test/api/v2'])->objects()->list();

    expect($a)->not->toBe($b)
        ->and($http->captured[0]->getHeaderLine('X-API-Key'))->toBe('customer-key')
        ->and((string) $http->captured[0]->getUri())->toBe('https://iot.gps.bg/api/v2/objects')
        ->and($http->captured[1]->getHeaderLine('X-API-Key'))->toBe('customer-key-2')
        ->and((string) $http->captured[1]->getUri())->toBe('https://staging.example.test/api/v2/objects');
});

it('lets forKey work without any configured tenants', function () {
    $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

    manager($http, [])->forKey('k')->objects()->list();

    expect($http->captured[0]->getHeaderLine('X-API-Key'))->toBe('k');
});

it('rejects an empty runtime key', function () {
    expect(fn () => manager(new FakeHttpClient, tenants())->forKey(''))
        ->toThrow(ConfigurationException::class, 'apiKey must not be empty');
});

// Retry behaviour itself is covered in TransportRetryTest; this checks only the
// config wiring, so it needs no real backoff sleeps.
it('maps the tenant retry setting onto the config', function () {
    $m = manager(new FakeHttpClient, tenants());

    $config = (new ReflectionMethod($m, 'configFor'))->invoke($m, ['api_key' => 'k', 'retry' => 4, 'timeout' => 12]);

    expect($config->retry->maxAttempts)->toBe(4)->and($config->timeout)->toBe(12);
});
```

`tests/Laravel/ServiceProviderTest.php`:
```php
<?php

declare(strict_types=1);

use Psr\Http\Message\RequestFactoryInterface;
use Ux2Dev\GpsBulgaria\GpsBulgaria;
use Ux2Dev\GpsBulgaria\Laravel\Facades\GpsBulgaria as GpsBulgariaFacade;
use Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaManager;
use Ux2Dev\GpsBulgaria\Resource\ZonesResource;

it('registers the manager as a singleton with an alias', function () {
    expect(app(GpsBulgariaManager::class))->toBe(app('gps-bulgaria'))
        ->and(app(GpsBulgariaManager::class))->toBe(app(GpsBulgariaManager::class));
});

it('merges the package config', function () {
    expect(config('gps-bulgaria.tenants.main.api_key'))->toBe('key_main');
});

it('builds a client from config through the facade', function () {
    expect(GpsBulgariaFacade::client())->toBeInstanceOf(GpsBulgaria::class)
        ->and(GpsBulgariaFacade::zones())->toBeInstanceOf(ZonesResource::class)
        ->and(GpsBulgariaFacade::tenant('other')->currentTenant())->toBe('other')
        ->and(GpsBulgariaFacade::forKey('runtime'))->toBeInstanceOf(GpsBulgaria::class);
});

it('binds the PSR-17 factories when guzzle is installed', function () {
    expect(app()->bound(RequestFactoryInterface::class))->toBeTrue();
});

it('publishes the config file under the gps-bulgaria-config tag', function () {
    $paths = Illuminate\Support\ServiceProvider::pathsToPublish(
        Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaServiceProvider::class,
        'gps-bulgaria-config',
    );

    expect(array_values($paths))->toBe([config_path('gps-bulgaria.php')]);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Laravel`
Expected: FAIL with `Class "Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaServiceProvider" not found`.

- [ ] **Step 4: Implement the Laravel layer**

`src/Laravel/config/gps-bulgaria.php`:
```php
<?php

declare(strict_types=1);

return [
    /*
    | The tenant used when you call GpsBulgaria::objects() etc. directly.
    */
    'default' => env('GPS_BULGARIA_DEFAULT', 'main'),

    /*
    | One entry per API key known at deploy time. For keys stored per
    | customer in your database, use GpsBulgaria::forKey($key) instead;
    | it inherits base_url/timeout/retry from the default tenant.
    */
    'tenants' => [
        'main' => [
            'api_key' => env('GPS_BULGARIA_API_KEY'),
            'base_url' => env('GPS_BULGARIA_BASE_URL', 'https://iot.gps.bg/api/v2'),
            'timeout' => (int) env('GPS_BULGARIA_TIMEOUT', 30),
            // Total attempts for GET requests on 503 / network errors. 1 = no retry.
            'retry' => (int) env('GPS_BULGARIA_RETRY_ATTEMPTS', 1),
        ],
    ],
];
```

`src/Laravel/GpsBulgariaManager.php`:
```php
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
    /** @var array<string, GpsBulgaria> */
    private array $instances = [];

    private string $currentTenant;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?RequestFactoryInterface $requestFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
    ) {
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
        return $this->instances[$this->currentTenant] ??= $this->make($this->configFor($this->tenantConfig($this->currentTenant)));
    }

    /**
     * A client for a key known only at runtime (e.g. a customer's key from
     * the database). Inherits base_url/timeout/retry from the default tenant;
     * $overrides wins. Never cached, so keys never leak between customers
     * and rotations apply immediately in long-running workers.
     *
     * @param  array<string, mixed>  $overrides  Keys: base_url, timeout, retry.
     */
    public function forKey(string $apiKey, array $overrides = []): GpsBulgaria
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

        if ($httpClient === null || $requestFactory === null || $streamFactory === null) {
            throw new ConfigurationException(
                'No PSR-18 client / PSR-17 factories available: install guzzlehttp/guzzle or bind them in the container',
            );
        }

        return new GpsBulgaria($config, $httpClient, $requestFactory, $streamFactory);
    }
}
```

`src/Laravel/GpsBulgariaServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class GpsBulgariaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/gps-bulgaria.php', 'gps-bulgaria');

        if (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $this->app->bindIf(RequestFactoryInterface::class, \GuzzleHttp\Psr7\HttpFactory::class);
            $this->app->bindIf(StreamFactoryInterface::class, \GuzzleHttp\Psr7\HttpFactory::class);
        }

        $this->app->singleton(GpsBulgariaManager::class, static function (Application $app): GpsBulgariaManager {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('gps-bulgaria', []);

            // A PSR-18 client is only passed when the app bound one itself;
            // otherwise the manager builds Guzzle per tenant with its timeout.
            return new GpsBulgariaManager(
                $config,
                $app->bound(ClientInterface::class) ? $app->make(ClientInterface::class) : null,
                $app->bound(RequestFactoryInterface::class) ? $app->make(RequestFactoryInterface::class) : null,
                $app->bound(StreamFactoryInterface::class) ? $app->make(StreamFactoryInterface::class) : null,
            );
        });

        $this->app->alias(GpsBulgariaManager::class, 'gps-bulgaria');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/gps-bulgaria.php' => config_path('gps-bulgaria.php'),
            ], 'gps-bulgaria-config');
        }
    }
}
```

`src/Laravel/Facades/GpsBulgaria.php`:
```php
<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Ux2Dev\GpsBulgaria\Resource\ObjectsResource objects()
 * @method static \Ux2Dev\GpsBulgaria\Resource\ObjectTypesResource objectTypes()
 * @method static \Ux2Dev\GpsBulgaria\Resource\ZonesResource zones()
 * @method static \Ux2Dev\GpsBulgaria\Resource\AlertsResource alerts()
 * @method static \Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaManager tenant(string $name)
 * @method static string currentTenant()
 * @method static \Ux2Dev\GpsBulgaria\GpsBulgaria client()
 * @method static \Ux2Dev\GpsBulgaria\GpsBulgaria forKey(string $apiKey, array<string, mixed> $overrides = [])
 *
 * @see \Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaManager
 */
final class GpsBulgaria extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'gps-bulgaria';
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS (the whole suite).

- [ ] **Step 6: Commit**

```bash
git add src/Laravel tests/Laravel
git commit -m "feat(laravel): add manager with tenants and forKey, provider and facade"
```

---

### Task 12: Vendored spec and spec-drift test

**Files:**
- Create: `spec/openapi.yaml` (downloaded)
- Test: `tests/SpecDriftTest.php`

**Interfaces:**
- Consumes: every resource and DTO class.
- Produces: a test that fails when the vendored spec gains an operation or a schema property that the SDK doesn't handle, or when a spec example no longer hydrates.

- [ ] **Step 1: Vendor the spec**

```bash
mkdir -p spec
curl -fsSL https://iot.gps.bg/api/v2/docs/openapi.yaml -o spec/openapi.yaml
head -3 spec/openapi.yaml
```
Expected: `openapi: 3.0.3` followed by `info:` / `title: GPS IoT`.

- [ ] **Step 2: Write the drift test**

`tests/SpecDriftTest.php`:
```php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Ux2Dev\GpsBulgaria\Dto;
use Ux2Dev\GpsBulgaria\Resource;

/*
 * Guards against upstream spec changes. When it fails after re-downloading
 * spec/openapi.yaml, update the SDK (and these maps) to match.
 */

function spec(): array
{
    static $spec;

    return $spec ??= Yaml::parseFile(__DIR__.'/../spec/openapi.yaml');
}

const OPERATIONS = [
    'listObjects' => [Resource\ObjectsResource::class, 'list'],
    'getObject' => [Resource\ObjectsResource::class, 'get'],
    'listObjectStatuses' => [Resource\ObjectsResource::class, 'statuses'],
    'getObjectStatus' => [Resource\ObjectsResource::class, 'status'],
    'listObjectRoutes' => [Resource\ObjectsResource::class, 'routes'],
    'listObjectTypes' => [Resource\ObjectTypesResource::class, 'list'],
    'listZones' => [Resource\ZonesResource::class, 'list'],
    'getZone' => [Resource\ZonesResource::class, 'get'],
    'searchZones' => [Resource\ZonesResource::class, 'search'],
    'createZone' => [Resource\ZonesResource::class, 'create'],
    'listAlerts' => [Resource\AlertsResource::class, 'list'],
    'listObjectAlerts' => [Resource\AlertsResource::class, 'forObject'],
];

const SCHEMAS = [
    'Object' => Dto\GpsObject::class,
    'Parameter' => Dto\Parameter::class,
    'ParameterDefinition' => Dto\ParameterDefinition::class,
    'ObjectType' => Dto\ObjectType::class,
    'ObjectStatus' => Dto\ObjectStatus::class,
    'Location' => Dto\Location::class,
    'RoutePoint' => Dto\RoutePoint::class,
    'Route' => Dto\Route::class,
    'Zone' => Dto\Zone::class,
    'ZoneInput' => Dto\ZoneInput::class,
    'Geometry' => Dto\Geometry::class,
    'Alert' => Dto\Alert::class,
    'AlertRules' => Dto\AlertRules::class,
    'AlertRuleGroup' => Dto\AlertRuleGroup::class,
    'ConditionASTNode' => Dto\ConditionNode::class,
];

/** Wire name → PHP property name: objectID → objectId, zoneID → zoneId. */
function phpProperty(string $wire): string
{
    return preg_replace('/ID$/', 'Id', $wire) ?? $wire;
}

it('maps every spec operation to an existing resource method', function () {
    $operationIds = [];
    foreach (spec()['paths'] as $path) {
        foreach ($path as $verb => $operation) {
            if (is_array($operation) && isset($operation['operationId'])) {
                $operationIds[] = $operation['operationId'];
            }
        }
    }

    sort($operationIds);
    $mapped = array_keys(OPERATIONS);
    sort($mapped);

    expect($operationIds)->toBe($mapped);

    foreach (OPERATIONS as $operationId => [$class, $method]) {
        expect(method_exists($class, $method))->toBeTrue("{$operationId} → {$class}::{$method} is missing");
    }
});

it('has a DTO property for every schema property', function (string $schema, string $class) {
    $properties = array_keys(spec()['components']['schemas'][$schema]['properties'] ?? []);
    $reflection = new ReflectionClass($class);

    foreach ($properties as $wire) {
        expect($reflection->hasProperty(phpProperty($wire)))
            ->toBeTrue("{$schema}.{$wire} has no {$class}::\$".phpProperty($wire));
    }
})->with(array_map(fn ($class, $schema) => [$schema, $class], SCHEMAS, array_keys(SCHEMAS)));

it('hydrates every schema example published in the spec', function (string $schema, string $class) {
    $example = spec()['components']['schemas'][$schema]['example'] ?? null;

    if ($example === null || ! method_exists($class, 'fromArray')) {
        expect(true)->toBeTrue();

        return;
    }

    expect($class::fromArray($example))->toBeInstanceOf($class);
})->with(array_map(fn ($class, $schema) => [$schema, $class], SCHEMAS, array_keys(SCHEMAS)));

it('knows every enum value the spec declares', function (string $schemaPath, string $enum) {
    $node = spec()['components']['schemas'];
    foreach (explode('.', $schemaPath) as $segment) {
        $node = $node[$segment];
    }

    $values = array_map(fn (BackedEnum $case) => $case->value, $enum::cases());
    sort($values);
    $declared = $node['enum'];
    sort($declared);

    expect($values)->toBe($declared);
})->with([
    ['ZoneType', Ux2Dev\GpsBulgaria\Enum\ZoneType::class],
    ['PrimitiveType', Ux2Dev\GpsBulgaria\Enum\PrimitiveType::class],
    ['Geometry.properties.type', Ux2Dev\GpsBulgaria\Enum\GeometryType::class],
    ['ConditionASTNode.properties.op', Ux2Dev\GpsBulgaria\Enum\ConditionOperator::class],
    ['ConditionASTNode.properties.primitive', Ux2Dev\GpsBulgaria\Enum\ConditionPrimitive::class],
]);
```

- [ ] **Step 3: Run the drift test**

Run: `vendor/bin/pest tests/SpecDriftTest.php`
Expected: PASS. If a property assertion fails, the message names the missing `Schema.field`. Fix the DTO (never the map) unless the field is genuinely renamed in PHP. `ZoneSearchInput` is deliberately not mapped, because `search()` takes a plain `list<string>`.

- [ ] **Step 4: Prove the test catches drift**

Temporarily inject an extra property, then run the drift test:

```bash
php -r '$f="spec/openapi.yaml"; file_put_contents($f, preg_replace("/(\n    Object:\n      type: object\n(?:.*\n)*?      properties:\n)/", "$1        odometer:\n          type: string\n", file_get_contents($f), 1));'
vendor/bin/pest tests/SpecDriftTest.php
```
Expected: FAIL with `Object.odometer has no Ux2Dev\GpsBulgaria\Dto\GpsObject::$odometer`. Then revert: `git checkout spec/openapi.yaml`.

- [ ] **Step 5: Commit**

```bash
git add spec tests/SpecDriftTest.php
git commit -m "test(spec): vendor openapi spec and add drift test"
```

---

### Task 13: Live smoke tests, static analysis, CI, docs, coverage gate

**Files:**
- Create: `tests/Live/LiveSmokeTest.php`
- Create: `.github/workflows/ci.yml`, `README.md`, `CHANGELOG.md`, `SECURITY.md`
- Modify: any `src/` file PHPStan flags (type annotations only; no behaviour changes)

**Interfaces:**
- Consumes: the whole SDK.
- Produces: a release-ready repository.

- [ ] **Step 1: Write the live smoke test**

`tests/Live/LiveSmokeTest.php`:
```php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Config\RetryPolicy;
use Ux2Dev\GpsBulgaria\Dto\GpsObject;
use Ux2Dev\GpsBulgaria\GpsBulgaria;

/*
 * Read-only calls against the real API. Never creates or mutates anything.
 * Run with: GPS_BULGARIA_LIVE_KEY=... vendor/bin/pest --group=live
 */

function live(): GpsBulgaria
{
    $key = getenv('GPS_BULGARIA_LIVE_KEY');
    $factory = new HttpFactory;

    return new GpsBulgaria(
        new GpsBulgariaConfig((string) $key, retry: RetryPolicy::attempts(3)),
        new Client(['timeout' => 30]),
        $factory,
        $factory,
    );
}

beforeEach(function () {
    if (! getenv('GPS_BULGARIA_LIVE_KEY')) {
        $this->markTestSkipped('GPS_BULGARIA_LIVE_KEY is not set');
    }
});

it('lists objects and fetches the first one', function () {
    $objects = live()->objects()->list();
    expect($objects)->each->toBeInstanceOf(GpsObject::class);

    if ($objects !== []) {
        expect(live()->objects()->get($objects[0]->objectId)->objectId)->toBe($objects[0]->objectId);
    }
})->group('live');

it('lists object types and statuses', function () {
    expect(live()->objectTypes()->list())->toBeArray()
        ->and(live()->objects()->statuses())->toBeArray();
})->group('live');

it('lists the last day of routes for the first object', function () {
    $objects = live()->objects()->list();
    if ($objects === []) {
        $this->markTestSkipped('account has no objects');
    }

    $to = new DateTimeImmutable;
    expect(live()->objects()->routes($objects[0]->objectId, $to->modify('-1 day'), $to))->toBeArray();
})->group('live');

it('lists zones with geometry', function () {
    expect(live()->zones()->list(includeGeometry: true))->toBeArray();
})->group('live');
```

- [ ] **Step 2: Run the live tests with your key**

Run: `GPS_BULGARIA_LIVE_KEY=<key> vendor/bin/pest --group=live`
Expected: 4 passed. Without the env var: 4 skipped. If a live response fails hydration, the `InvalidResponseException` names the DTO and field. Fix the DTO and add the real payload shape as a fixture in the matching `tests/Dto/*Test.php`, rather than loosening `Data`.

- [ ] **Step 3: Run Pint and PHPStan, and fix the findings**

Run: `vendor/bin/pint && vendor/bin/phpstan analyse`
Expected: Pint reformats as needed, and PHPStan reports `[OK] No errors`. For any PHPStan error, fix the type: add precise `@param`/`@return`/`@var` generics, or narrow with `is_*` checks. Don't add a baseline or `ignoreErrors`. The likely spots are `GpsBulgariaManager` config reads and the Laravel `$app->make()` returns, which are already narrowed with `is_*` checks in Task 11.

- [ ] **Step 4: Run the full suite under the coverage gate**

Run: `pecl install pcov` (once, if not installed), then `php -d pcov.enabled=1 vendor/bin/pest --coverage --min=100`
Expected: PASS at 100.0%. For any uncovered line, add a test in the owning task's test file rather than excluding the line. The usual suspects are:
- `Transport`'s `InvalidArgumentException` path for unencodable bodies: test it with `['bad' => "\xB1\x31"]`.
- `Data::nullableObject` with a wrong type: test it with `['k' => 'x']`.
- `GpsBulgariaManager::make()`'s no-Guzzle branch.

The no-Guzzle branch can't be reached while Guzzle is installed. Mark only that `if` block with `// @codeCoverageIgnoreStart` / `// @codeCoverageIgnoreEnd` and a comment explaining why.

- [ ] **Step 5: Create the CI workflow**

`.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
      - run: composer update --no-interaction --no-progress
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --no-progress

  tests:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4', '8.5']
        stability: [prefer-lowest, prefer-stable]
    name: PHP ${{ matrix.php }} / ${{ matrix.stability }}
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: ${{ matrix.php == '8.4' && matrix.stability == 'prefer-stable' && 'pcov' || 'none' }}
      - run: composer update --${{ matrix.stability }} --no-interaction --no-progress
      - if: ${{ !(matrix.php == '8.4' && matrix.stability == 'prefer-stable') }}
        run: vendor/bin/pest
      - if: ${{ matrix.php == '8.4' && matrix.stability == 'prefer-stable' }}
        run: vendor/bin/pest --coverage --min=100
```

- [ ] **Step 6: Write `README.md`**

````markdown
# GPS Bulgaria PHP SDK

[![CI](https://github.com/ux2dev/gps-bulgaria/actions/workflows/ci.yml/badge.svg)](https://github.com/ux2dev/gps-bulgaria/actions/workflows/ci.yml)

Framework-agnostic PHP SDK for the [GPS Bulgaria IoT API v2](https://iot.gps.bg/api/v2/docs/), with an optional Laravel integration.

- Typed, immutable DTOs for objects, live statuses, routes, zones and alerts
- PSR-18 / PSR-17: bring any HTTP client (Guzzle works out of the box)
- An exception class per HTTP status, opt-in retry for reads
- Laravel: config tenants, per-customer runtime keys, facade

## Requirements

- PHP 8.3+
- A PSR-18 HTTP client and PSR-17 factories (e.g. `guzzlehttp/guzzle`)
- Laravel 12 or 13 for the optional integration

## Installation

```bash
composer require ux2dev/gps-bulgaria guzzlehttp/guzzle
```

## Quick start

### Plain PHP

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\GpsBulgaria;

$factory = new HttpFactory();
$gps = new GpsBulgaria(
    new GpsBulgariaConfig(apiKey: getenv('GPS_BULGARIA_API_KEY')),
    new Client(['timeout' => 30]),
    $factory,
    $factory,
);

foreach ($gps->objects()->statuses() as $status) {
    echo $status->objectName, ': ', $status->speed() ?? 'n/a', " km/h\n";
}
```

### Laravel

The service provider and the `GpsBulgaria` facade are auto-discovered.

```dotenv
GPS_BULGARIA_API_KEY=your-key
```

```php
use Ux2Dev\GpsBulgaria\Laravel\Facades\GpsBulgaria;

$objects = GpsBulgaria::objects()->list();
```

## Configuration

`GpsBulgariaConfig` takes the following options:

| Option | Default | Notes |
|---|---|---|
| `apiKey` | none | Required. Sent as `X-API-Key`. Redacted from `var_dump`/`print_r`, and cannot be serialized. |
| `baseUrl` | `https://iot.gps.bg/api/v2` | Must be `https://`. |
| `timeout` | `30` | Seconds. Applied by the Laravel integration; in plain PHP, set it on your own HTTP client. |
| `retry` | `RetryPolicy::none()` | See [Retries](#retries). |

### Laravel tenants

Publish the config with `php artisan vendor:publish --tag=gps-bulgaria-config`, then add one tenant per key you know at deploy time:

```php
'tenants' => [
    'main'  => ['api_key' => env('GPS_BULGARIA_API_KEY'), 'retry' => 3],
    'fleet' => ['api_key' => env('GPS_BULGARIA_FLEET_KEY')],
],
```

```php
GpsBulgaria::tenant('fleet')->zones()->list();
```

### Per-customer keys

When each of your customers connects their own GPS Bulgaria account, build a client from the key you stored:

```php
$gps = GpsBulgaria::forKey($customer->gps_api_key);
$gps->objects()->list();
```

`forKey()` inherits `base_url`, `timeout` and `retry` from the default tenant. The client is **not cached**, so keys never leak between customers, and a rotated key takes effect immediately in queue workers.

### Retries

```php
new GpsBulgariaConfig(apiKey: $key, retry: RetryPolicy::attempts(3));
```

Retries apply only to **GET** requests, and only when the API returns `503` or the connection fails. Delays use exponential backoff with full jitter (200 ms base, 2 s cap by default), and a `Retry-After` header is honoured. `POST` requests (`zones()->create()`, `zones()->search()`) are never retried.

## Resources

| Method | Endpoint | Returns |
|---|---|---|
| `objects()->list()` | `GET /objects` | `list<GpsObject>` |
| `objects()->get($id)` | `GET /objects/{id}` | `GpsObject` |
| `objects()->statuses()` | `GET /objects/statuses` | `list<ObjectStatus>` |
| `objects()->status($id)` | `GET /objects/{id}/status` | `ObjectStatus` |
| `objects()->routes($id, $from, $to, includeAddresses: false, includePoints: false)` | `GET /objects/{id}/routes` | `list<Route>` |
| `objectTypes()->list()` | `GET /object-types` | `list<ObjectType>` |
| `zones()->list(includeGeometry: false)` | `GET /zones` | `list<Zone>` |
| `zones()->get($id, includeGeometry: false)` | `GET /zones/{id}` | `Zone` |
| `zones()->search($ids, includeGeometry: false)` | `POST /zones/search` | `list<Zone>` |
| `zones()->create(ZoneInput $input)` | `POST /zones` | `Zone` |
| `alerts()->list($from = null, $to = null)` | `GET /objects/alerts` | `list<Alert>` (experimental) |
| `alerts()->forObject($id, $from = null, $to = null)` | `GET /objects/{id}/alerts` | `list<Alert>` (experimental) |

`$from` and `$to` accept any `DateTimeInterface` and are sent as UTC instants, so `Europe/Sofia` midnight is sent as `21:00Z` (summer) or `22:00Z` (winter) of the previous day.

### Object parameters

Parameter values are always strings. Match them by **id**: the API notes that display names can change.

```php
$plate = $object->parameterValue(3);          // "CA0000CA"
$date  = $object->parameter(7)?->asDate();    // DateTimeImmutable|null
```

### Status sensors and route aggregations

```php
$status->speed();          // ?float, from sensorData['speed']
$status->odometer();       // ?float, from sensorData['total_odometer']
$status->sensor('key');    // raw string reading, e.g. "key_off"

$route->mileage();         // ?float km
$route->duration();        // ?int seconds
$route->maxSpeed();        // ?float
$route->isOpen();          // still driving?
```

## Zones and geometry

The API uses GeoJSON, which orders positions as **`[longitude, latitude]`**. The SDK's factories take **latitude first** and convert for you:

```php
use Ux2Dev\GpsBulgaria\Dto\ZoneInput;

$depot = GpsBulgaria::zones()->create(
    ZoneInput::circle('Depot', lat: 42.6977, lng: 23.3219, radius: 250)
        ->withColor('#ff0000')
        ->onMap(),
);

ZoneInput::polygon('Yard', [[42.69, 23.32], [42.69, 23.33], [42.70, 23.33]]);          // ring closed automatically
ZoneInput::rectangle('Lot', southWest: [42.69, 23.32], northEast: [42.70, 23.33]);
ZoneInput::polyline('Route A', [[42.69, 23.32], [42.70, 23.33]], buffer: 30);
```

`Zone::$geometry` is only populated when you pass `includeGeometry: true`. Its `coordinates` are raw GeoJSON (`[lng, lat]`).

## Exceptions

Every exception extends `Ux2Dev\GpsBulgaria\Exception\GpsBulgariaException`.

| Exception | When |
|---|---|
| `ValidationException` | HTTP 400 |
| `AuthenticationException` | HTTP 401: key missing, unknown, revoked or expired |
| `PermissionDeniedException` | HTTP 403 |
| `NotFoundException` | HTTP 404 |
| `ServiceUnavailableException` | HTTP 503 |
| `ApiException` | Any other non-2xx; parent of the five above |
| `TransportException` | Network, DNS, TLS or timeout failure |
| `InvalidResponseException` | A 2xx body that is empty, not JSON, or doesn't match the schema |
| `ConfigurationException` | Invalid config or unknown tenant |

`ApiException` exposes `httpStatus`, `errorCode` (e.g. `NOT_FOUND`), `traceId`, `body` and `retryAfterSeconds`. Invalid arguments (an empty id, `from` after `to`, a zero radius) throw PHP's `InvalidArgumentException` before any request is sent.

```php
try {
    $gps->objects()->get($id);
} catch (NotFoundException) {
    // gone
} catch (ApiException $e) {
    logger()->error('GPS Bulgaria error', ['code' => $e->errorCode, 'trace' => $e->traceId]);
}
```

## Experimental: alerts

GPS Bulgaria lists the alerts endpoints as "planned for a later release". `alerts()->list()`, `alerts()->forObject()` and the `Alert`/`ConditionNode` DTOs follow the published spec and are marked `@experimental`. They may change in a minor release once the endpoints go live.

## Testing

```bash
composer test            # unit + Laravel tests
composer test:coverage   # 100% coverage gate (needs pcov or xdebug)
composer lint            # Pint
composer stan            # PHPStan level max
GPS_BULGARIA_LIVE_KEY=... composer test:live   # read-only calls against the real API
```

`spec/openapi.yaml` is a vendored copy of the upstream spec. `tests/SpecDriftTest.php` fails when it contains an operation, schema property or enum value the SDK doesn't handle. To pick up API changes, re-download the spec:

```bash
curl -fsSL https://iot.gps.bg/api/v2/docs/openapi.yaml -o spec/openapi.yaml && composer test
```

## License

MIT. See [LICENSE](LICENSE).
````

- [ ] **Step 7: Write `CHANGELOG.md` and `SECURITY.md`**

`CHANGELOG.md`:
```markdown
# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-06

### Added
- Objects: list, get, statuses, status, routes.
- Object types: list.
- Zones: list, get, search, create, with lat-first geometry factories.
- Alerts (experimental): list, forObject.
- An exception per HTTP status, and opt-in retry for GET on 503 / transport errors.
- Laravel integration: tenants, `forKey()` runtime keys, facade.
```

`SECURITY.md`:
```markdown
# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.x     | Yes       |

## Reporting a vulnerability

Please email **security@ux2.dev** rather than opening a public issue. Include
steps to reproduce and the affected version. You will get an acknowledgement
within 3 working days.

Never include real GPS Bulgaria API keys in reports or issues.
```

- [ ] **Step 8: Final verification**

Run: `vendor/bin/pint --test && vendor/bin/phpstan analyse && php -d pcov.enabled=1 vendor/bin/pest --coverage --min=100`
Expected: Pint clean, `[OK] No errors`, all tests pass, coverage 100.0%.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore(release): add live smoke tests, ci, readme, changelog and security policy"
```

Tagging `v0.1.0` and creating the GitHub repo or Packagist entry are outward-facing steps. **Ask before doing either.**
