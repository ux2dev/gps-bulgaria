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
