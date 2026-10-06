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
