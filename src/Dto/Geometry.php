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

        $distinct = [];
        foreach ($ring as $position) {
            if (! in_array($position, $distinct, true)) {
                $distinct[] = $position;
            }
        }

        if (count($distinct) < 3) {
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
