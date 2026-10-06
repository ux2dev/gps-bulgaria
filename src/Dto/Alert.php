<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use DateTimeImmutable;
use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** @experimental The alerts API is not yet live upstream; the shape follows the published spec. */
final readonly class Alert implements Hydratable
{
    /** @param array<string, string> $sensorData Readings at the moment the alert was raised. */
    public function __construct(
        public string $eventId,
        public string $objectId,
        public string $reason,
        public string $definitionId,
        public DateTimeImmutable $triggeredAt,
        public ?DateTimeImmutable $clearedAt,
        public array $sensorData,
        public AlertRules $rules,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            eventId: Data::string($data, 'eventID', 'Alert'),
            objectId: Data::string($data, 'objectID', 'Alert'),
            reason: Data::string($data, 'reason', 'Alert'),
            definitionId: Data::string($data, 'definitionID', 'Alert'),
            triggeredAt: Data::dateTime($data, 'triggeredAt', 'Alert'),
            clearedAt: Data::nullableDateTime($data, 'clearedAt', 'Alert'),
            sensorData: Data::stringMap($data, 'sensorData', 'Alert'),
            rules: AlertRules::fromArray(Data::object($data, 'rules', 'Alert')),
        );
    }

    public function isActive(): bool
    {
        return $this->clearedAt === null;
    }
}
