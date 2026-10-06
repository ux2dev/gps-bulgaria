<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Ux2Dev\GpsBulgaria\Laravel\GpsBulgariaManager;

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
 * @see GpsBulgariaManager
 */
final class GpsBulgaria extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'gps-bulgaria';
    }
}
