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
