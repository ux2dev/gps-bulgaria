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
