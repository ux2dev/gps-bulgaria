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
