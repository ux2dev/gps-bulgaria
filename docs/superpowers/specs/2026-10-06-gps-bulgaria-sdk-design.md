# ux2dev/gps-bulgaria: PHP SDK for the GPS Bulgaria IoT API v2

**Date:** 2026-10-06
**Status:** Approved design, pending implementation plan
**API reference:** https://iot.gps.bg/api/v2/docs/ (spec: https://iot.gps.bg/api/v2/docs/openapi.yaml, OpenAPI 3.0.3, "GPS IoT" v2.0)

## 1. Goal and context

A public, framework-agnostic PHP SDK for the GPS Bulgaria IoT API, in the same house style as
`ux2dev/prim` and `ux2dev/borica`, with an optional Laravel layer.

- **Consumers:** Talo (Laravel 13, PHP ^8.3) and Leha (Laravel 13, PHP ^8.5), plus the public via Packagist.
- **Keys:** both a static `.env` key per app *and* per-customer keys stored in a database, resolved at runtime.
- **Testing access:** one real API key, used only by opt-in live smoke tests.
- **Success:** both apps can list objects, read live statuses and routes, and manage zones through typed DTOs.
  The package is publishable with CI, static analysis and a 100% coverage gate.

## 2. API surface covered (12 operations)

| operationId | Verb + path | SDK method | Returns |
|---|---|---|---|
| listObjects | `GET /objects` | `objects()->list()` | `list<GpsObject>` |
| getObject | `GET /objects/{objectID}` | `objects()->get(string $id)` | `GpsObject` |
| listObjectStatuses | `GET /objects/statuses` | `objects()->statuses()` | `list<ObjectStatus>` |
| getObjectStatus | `GET /objects/{objectID}/status` | `objects()->status(string $id)` | `ObjectStatus` |
| listObjectRoutes | `GET /objects/{objectID}/routes` | `objects()->routes(string $id, DateTimeInterface $from, DateTimeInterface $to, bool $includeAddresses = false, bool $includePoints = false)` | `list<Route>` |
| listObjectTypes | `GET /object-types` | `objectTypes()->list()` | `list<ObjectType>` |
| listZones | `GET /zones` | `zones()->list(bool $includeGeometry = false)` | `list<Zone>` |
| getZone | `GET /zones/{zoneID}` | `zones()->get(string $id, bool $includeGeometry = false)` | `Zone` |
| searchZones | `POST /zones/search` (body `{"zoneIDs": [...]}`) | `zones()->search(list<string> $ids, bool $includeGeometry = false)` (an empty `$ids` throws `InvalidArgumentException` before any request) | `list<Zone>` |
| createZone | `POST /zones` | `zones()->create(ZoneInput $input)` | `Zone` |
| listAlerts | `GET /objects/alerts` | `alerts()->list(?DateTimeInterface $from = null, ?DateTimeInterface $to = null)` | `list<Alert>` (**@experimental**) |
| listObjectAlerts | `GET /objects/{objectID}/alerts` | `alerts()->forObject(string $id, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null)` | `list<Alert>` (**@experimental**) |

The spec marks both alerts endpoints "Not yet available; planned for a later release". They ship marked
`@experimental`, are documented as such in the README, and are modelled exactly on the spec's `Alert` schema.

Responses are bare JSON arrays or objects: no envelope, no pagination. List methods return plain `list<T>`;
there is no `ApiResponse<T>` wrapper.

## 3. Package

- Composer name `ux2dev/gps-bulgaria`, namespace `Ux2Dev\GpsBulgaria\` → `src/`, tests `Ux2Dev\GpsBulgaria\Tests\` → `tests/`.
- MIT license, GitHub `ux2dev/gps-bulgaria`, published on Packagist.
- `require`: `php ^8.3`, `ext-json`, `psr/http-client ^1.0`, `psr/http-factory ^1.0`.
- `require-dev`: `pestphp/pest ^4.0`, `guzzlehttp/guzzle ^7.0`, `orchestra/testbench` (Laravel 12/13 compatible),
  `symfony/yaml` (drift test only), `phpstan/phpstan`, `laravel/pint`.
- `suggest`: `guzzlehttp/guzzle`, `illuminate/support ^12|^13`.
- `extra.laravel`: provider `Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaServiceProvider`, alias `GpsBulgaria`.
- Every file uses `declare(strict_types=1)`.

### Layout

```
src/
  GpsBulgaria.php
  Config/GpsBulgariaConfig.php
  Config/RetryPolicy.php
  Http/Transport.php
  Contracts/Hydratable.php
  Exception/
    GpsBulgariaException.php        extends RuntimeException (root)
    ConfigurationException.php
    TransportException.php
    InvalidResponseException.php
    ApiException.php
    ValidationException.php         400, extends ApiException
    AuthenticationException.php     401, extends ApiException
    PermissionDeniedException.php   403, extends ApiException
    NotFoundException.php           404, extends ApiException
    ServiceUnavailableException.php 503, extends ApiException
  Resource/
    ObjectsResource.php  ObjectTypesResource.php  ZonesResource.php  AlertsResource.php
  Dto/
    GpsObject.php  Parameter.php  ObjectType.php  ParameterDefinition.php
    ObjectStatus.php  Location.php  Route.php  RoutePoint.php
    Zone.php  Geometry.php  ZoneInput.php
    Alert.php  AlertRules.php  AlertRuleGroup.php  ConditionNode.php
  Enum/
    ZoneType.php  GeometryType.php  PrimitiveType.php  ConditionOperator.php  ConditionPrimitive.php
  Laravel/
    GpsBulgariaManager.php  GpsBulgariaServiceProvider.php  Facades/GpsBulgaria.php
    config/gps-bulgaria.php
spec/openapi.yaml                    vendored copy of the upstream spec
tests/                               mirrors src/, see §9
```

All exception classes are non-final, as in borica. Resources, DTOs, config, transport and the root client are `final`.

## 4. Root client and config

```php
final class GpsBulgaria
{
    public function __construct(
        GpsBulgariaConfig $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
    ) { /* builds Transport */ }

    public function objects(): ObjectsResource         { return $this->objects ??= new ObjectsResource($this->transport); }
    public function objectTypes(): ObjectTypesResource { /* lazy */ }
    public function zones(): ZonesResource             { /* lazy */ }
    public function alerts(): AlertsResource           { /* lazy */ }
}
```

`GpsBulgariaConfig` is a `final readonly` class:

- `__construct(string $apiKey, string $baseUrl = 'https://iot.gps.bg/api/v2', int $timeout = 30, RetryPolicy $retry = new RetryPolicy())`
- Validates in the constructor and throws `ConfigurationException` when:
  - `apiKey` is empty;
  - `baseUrl` is empty or not `https://`;
  - `timeout < 1`.
- `baseUrl` is right-trimmed of `/`.
- `apiKey` is private, exposed via `apiKey(): string`. `__debugInfo()` redacts it, and `__serialize()`/`__unserialize()` throw `LogicException`.
- `timeout` is informational in plain PHP: the caller's PSR-18 client owns timeouts. The Laravel provider applies it to the Guzzle client it builds.

## 5. Transport

`final class Transport`, with one core method:

```php
/** @return array<mixed> decoded JSON */
public function request(string $method, string $path, array $query = [], ?array $body = null): array
```

**Requests**

- URL = `baseUrl . $path`, plus `?` and the encoded query when there is one.
- Query values:
  - `bool` → `'true'`/`'false'`;
  - `DateTimeInterface` → converted to UTC and formatted RFC 3339 (`Y-m-d\TH:i:s\Z`);
  - `null` → omitted.
- Path segments are `rawurlencode`d by the resources.
- Headers:
  - always `X-API-Key: <key>` and `Accept: application/json`;
  - `Content-Type: application/json; charset=utf-8` when there is a body.
- Body encoding: `json_encode` with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION`.

**Successful responses (2xx)**

- Decode the JSON to an array.
- Throw `InvalidResponseException` (with the HTTP status and a body excerpt) on an empty body, invalid JSON or a non-array result.

**Errors**

- Non-2xx: try to decode the spec's `Error` schema `{code, message, traceId?}`, then pick the class by HTTP status:

  | Status | Exception |
  |---|---|
  | 400 | `ValidationException` |
  | 401 | `AuthenticationException` |
  | 403 | `PermissionDeniedException` |
  | 404 | `NotFoundException` |
  | 503 | `ServiceUnavailableException` |
  | anything else | `ApiException` |

- `ApiException` carries these public readonly properties:
  - `int $httpStatus`
  - `?string $errorCode` (`BAD_REQUEST`, `UNAUTHORIZED`, `FORBIDDEN`, `NOT_FOUND`, `INTERNAL_ERROR`, `SERVICE_UNAVAILABLE`, or unknown future values as-is)
  - `?string $traceId`
  - `array $body`
  - `?int $retryAfterSeconds`

  The message is the API's `message`.
- A non-JSON error body (such as a proxy's HTML) still produces the status-mapped class, with `errorCode = null`, `body = []` and the raw text, truncated to 500 characters, in the message.
- PSR-18 `ClientExceptionInterface` → `TransportException`, with the original as `previous`.

## 6. Retry

`final readonly class RetryPolicy`:

- `__construct(int $maxAttempts = 1, int $baseDelayMs = 200, int $maxDelayMs = 2000)`, validated (`maxAttempts >= 1`, delays >= 0, `base <= max`).
- `static none(): self` (maxAttempts 1) and `static attempts(int $n): self`.

Behaviour in `Transport`:

- Off by default: `maxAttempts = 1`.
- Only `GET` requests retry. `POST` (`createZone`, `searchZones`) never retries.
- A retry happens only on `ServiceUnavailableException` (503) or `TransportException`.
- Delay before attempt *n* (n ≥ 1) is full jitter: `random_int(0, min(maxDelayMs, baseDelayMs * 2^(n-1)))`.
  When a 503 carries `Retry-After` in seconds, that value (×1000, capped at `maxDelayMs`) replaces the computed delay.
- Sleeping goes through an injectable `Closure(int $ms): void`. The default is `usleep`; tests inject a recorder.
- After the last attempt the final exception is rethrown unchanged.

## 7. DTOs

**Common rules**

- Result DTOs are `final readonly` classes that implement `Contracts\Hydratable` (`static fromArray(array $data): static`).
- Property names are camelCase. ID fields are renamed `objectID` → `objectId` etc., and are always `string`.
- A missing required field (per the spec) → `InvalidResponseException("GpsObject: missing required field 'name'")`. Missing optional fields → `null`, or `[]` for collections.
- All `date-time` fields → `DateTimeImmutable` in UTC. An unparsable value → `InvalidResponseException`.
- Enums are **strict**: an unknown value → `InvalidResponseException` naming the enum and the value. Spec drift should be loud. The drift test and a patch release are the remedy.

### Objects

- **`GpsObject`:** `objectId`, `?objectType` (an empty string → `null`), `name`, `?comment`, `list<string> tags`, `list<Parameter> parameters`.
  - `parameter(int $id): ?Parameter` and `parameterValue(int $id): ?string`.
  - Parameters are matched by **id**, because the spec says names may change.
- **`Parameter`:** `int id`, `string name`, `string value`.
  - `asDate(): ?DateTimeImmutable` (format `YYYY-MM-DD`; `null` on mismatch).
  - `asFloat(): ?float` (`null` when not numeric).
- **`ObjectType`:** `name`, `list<ParameterDefinition> parameters`, plus `definition(int $id): ?ParameterDefinition`.
- **`ParameterDefinition`:** `int id`, `name`, `PrimitiveType type` (`string|number|date|file`), `bool required`, `bool readOnly`, `?list<string> values`.

### Status and routes

- **`ObjectStatus`:** `objectId`, `objectName`, `?DateTimeImmutable lastUpdate`, `?Location location`, `array<string,string> sensorData`.
  - `sensor(string $key): ?string`
  - `speed(): ?float` (key `speed`)
  - `odometer(): ?float` (key `total_odometer`)
  - `hasReported(): bool` (`lastUpdate !== null`)
- **`Location`:** `float latitude`, `float longitude`, `float angle`.
- **`Route`:** `objectId`, `DateTimeImmutable startedAt`, `?DateTimeImmutable endedAt`, `?startAddress`, `?endAddress`, `?RoutePoint startPoint`, `?RoutePoint endPoint`, `array<string,string> aggregations`, `list<RoutePoint> dataPoints`.
  - `isOpen(): bool`
  - Typed getters for the 10 documented aggregation keys. Each returns `null` when the key is absent or not numeric:

    | Getter | Key |
    |---|---|
    | `mileage(): ?float` | `mileage` |
    | `duration(): ?int` | `duration` |
    | `durationMoving(): ?int` | `duration_moving` |
    | `durationIdle(): ?int` | `duration_idle` |
    | `maxSpeed(): ?float` | `speed_max` |
    | `avgSpeed(): ?float` | `speed_avg` |
    | `odometerAtStart(): ?float` | `odometer_at_start` |
    | `odometerAtEnd(): ?float` | `odometer_at_end` |
    | `fuelLevelAtStart(): ?float` | `fuel_level_at_start` |
    | `fuelLevelAtEnd(): ?float` | `fuel_level_at_end` |

- **`RoutePoint`:** `?DateTimeImmutable eventTs`, `float latitude`, `float longitude`.

### Zones

- **`Zone`:** `zoneId`, `ZoneType zoneType`, `?name`, `?color`, `?address`, `?tag`, `bool onMap`, `?Geometry geometry`, `?float radius`, `?float buffer`.
- **`Geometry`:** `GeometryType type` (`Point|LineString|Polygon`), `array coordinates` (raw GeoJSON, `[lng, lat]` order).
  - `fromArray()` / `toArray()`.
  - Factories that take **latitude first** and convert to GeoJSON order internally:
    - `point(float $lat, float $lng)`
    - `lineString(list<array{float,float}> $latLngs)` requires at least 2 positions.
    - `polygon(list<array{float,float}> $latLngs)` closes the ring automatically when the first and last positions differ, and requires at least 3 distinct positions.
  - Invalid input to the factories throws `InvalidArgumentException`.
- **`ZoneInput`:** `final readonly`, private constructor. `toArray()` omits nulls. Named constructors:
  - `circle(?string $name, float $lat, float $lng, float $radius)` → `zoneType: circle`, Point, `radius > 0`.
  - `polygon(?string $name, list<array{float,float}> $latLngs)` → Polygon.
  - `rectangle(?string $name, array{float,float} $southWest, array{float,float} $northEast)` → Polygon with a closed 5-position ring.
  - `polyline(?string $name, list<array{float,float}> $latLngs, ?float $buffer = null)` → LineString.
  - Immutable withers: `withColor(string)`, `withAddress(string)`, `withTag(string)`, `onMap(bool $onMap = true)`.

### Alerts (experimental)

- **`Alert`:** `eventId`, `objectId`, `reason`, `definitionId`, `DateTimeImmutable triggeredAt`, `?DateTimeImmutable clearedAt`, `array<string,string> sensorData`, `AlertRules rules`, plus `isActive(): bool`.
- **`AlertRules`:** `AlertRuleGroup trigger`, `AlertRuleGroup clear`.
- **`AlertRuleGroup`:** `int durationSeconds`, `ConditionNode rules`.
- **`ConditionNode`:** a single recursive class.
  - Fields: `ConditionOperator op`, `list<ConditionNode> children`, `?string field`, `string|int|float|bool|array|null value`, `?ConditionPrimitive primitive`.
  - `isLogical(): bool` (NOT/AND/OR), `isZoneOperator(): bool` (IN_ZONE/OUTSIDE_ZONE).

## 8. Laravel layer

`src/Laravel/config/gps-bulgaria.php`:

```php
return [
    'default' => env('GPS_BULGARIA_DEFAULT', 'main'),
    'tenants' => [
        'main' => [
            'api_key'  => env('GPS_BULGARIA_API_KEY'),
            'base_url' => env('GPS_BULGARIA_BASE_URL', 'https://iot.gps.bg/api/v2'),
            'timeout'  => (int) env('GPS_BULGARIA_TIMEOUT', 30),
            'retry'    => (int) env('GPS_BULGARIA_RETRY_ATTEMPTS', 1),
        ],
    ],
];
```

**`GpsBulgariaManager`:** `(array $config, ?ClientInterface, ?RequestFactoryInterface, ?StreamFactoryInterface)`, following the `PrimManager`/`BoricaManager` pattern.

- `tenant(string $name): static` returns an immutable clone.
- `client(): GpsBulgaria` caches one instance per tenant.
- `__call` forwards to `client()`.
- An unknown tenant, or a missing `api_key` at resolve time, throws `ConfigurationException`.
- `forKey(string $apiKey, array $overrides = []): GpsBulgaria` builds a client from a runtime key, e.g. a customer's key from the database.
  - It inherits `base_url`, `timeout` and `retry` from the default tenant, with `$overrides` taking precedence.
  - It is **not cached**: no cross-customer reuse, and key rotation takes effect immediately in long-running workers.

**`GpsBulgariaServiceProvider`**

- Calls `mergeConfigFrom` and registers the manager singleton, aliased `gps-bulgaria`.
- If Guzzle is installed, `bindIf`s the PSR-18 client (Guzzle with the default tenant's `timeout`) and the PSR-17 factories.
- Publishes the config with tag `gps-bulgaria-config`.

**Facade:** `Facades\GpsBulgaria`, with `@method static` for `objects()`, `objectTypes()`, `zones()`, `alerts()`, `tenant()`, `client()` and `forKey()`.

## 9. Testing

Pest 4. `phpunit.xml` has a `tests` suite with source `src`. `tests/Pest.php` binds `Laravel/TestCase` (Testbench) to `tests/Laravel`.

- **`tests/Support/FakeHttpClient`:** implements PSR-18, takes a queue of responses and captures every request. Helpers: `json(int $status, array $body)`, `raw(int $status, string $body, array $headers = [])`, `throw(ClientExceptionInterface)`.
- **Resource tests:** one per method. They assert the exact HTTP method, URI (path and query), `X-API-Key`, `Accept`/`Content-Type` and JSON body, and the hydrated DTO values. Fixtures come from the spec's `example:` blocks.
- **Transport tests:**
  - every status → exception mapping, with `errorCode`, `traceId` and `retryAfterSeconds`;
  - non-JSON error bodies, empty or invalid 2xx bodies, PSR-18 failures;
  - retry: GET retries on 503 and transport errors up to `maxAttempts`, POST never retries, 404 never retries;
  - the jitter bound and `Retry-After` honoured, both via an injected sleep recorder.
- **DTO tests:** required-field errors, null handling, strict enums, date parsing, the `Parameter` helpers, the `Route` aggregation getters.
  `Geometry` lat/lng → GeoJSON order, polygon auto-close, the `ZoneInput` factories and withers, a recursive `ConditionNode`.
- **Config tests:** validation, URL normalisation, key redaction in `__debugInfo`/`var_dump`/`print_r`, the serialize guard, `RetryPolicy` validation.
- **`ExceptionHierarchyTest`:** every specific exception extends `ApiException`, which extends `GpsBulgariaException`, which extends `RuntimeException`.
- **`SpecDriftTest`:** parses `spec/openapi.yaml` with `symfony/yaml`.
  - Every `operationId` appears in an explicit `operationId → [Resource::class, 'method']` map, and that method exists.
  - Every property of each mapped schema is handled by its DTO, checked via a fixture containing all properties and asserting each one is hydrated.
- **Laravel tests:** config merge and publish, default tenant, named tenant, unknown tenant, `forKey` (uncached, overrides), Facade forwarding, Guzzle binding with timeout.
- **Live smoke test:** `tests/Live/`, group `live`, skipped unless `GPS_BULGARIA_LIVE_KEY` is set. Read-only calls only: objects, object types, statuses, zones. It never calls `create`.
- **Coverage gate:** `XDEBUG_MODE=coverage vendor/bin/pest --coverage --min=100`, excluding the `live` group.

## 10. Tooling, CI, docs

- **PHPStan** level `max` on `src/`. **Pint** with the Laravel preset.
- **GitHub Actions** `.github/workflows/ci.yml`:
  - matrix: PHP 8.3/8.4/8.5 × `prefer-lowest`/`prefer-stable`;
  - steps: Pint `--test`, PHPStan, Pest with coverage (on one matrix cell).
- **README:** Requirements, Installation, Quick Start (Plain PHP, Laravel), Configuration (tenants, `forKey`, retry, timeout), Resources table, Zones and geometry (the lat/lng vs GeoJSON note), Status sensors and route aggregations, Exceptions table, Experimental: alerts, Testing (including the live group), License.
- **`CHANGELOG.md`** (Keep a Changelog), **`SECURITY.md`** (borica style), **`LICENSE`** (MIT, 2026 ux2dev).
- **`docs/superpowers/{specs,plans}/`** is committed, as in prim.
- **Versioning:** semver. The first tag is `v0.1.0`; `v1.0.0` follows once Talo and Leha run it in production.

## 11. Out of scope for v1

- PSR-3 logging (it can be added non-breakingly later).
- Async or concurrent requests.
- Typed per-parameter coercion driven by `ObjectType` definitions (the raw string plus the `asDate`/`asFloat` helpers cover it).
- Any endpoint not in the upstream spec as of 2026-10-06.
- Artisan commands, events, caching of API responses.
