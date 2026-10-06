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
