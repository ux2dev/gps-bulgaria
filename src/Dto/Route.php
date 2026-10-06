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
