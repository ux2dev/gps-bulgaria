<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use DateTimeImmutable;
use DateTimeZone;
use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** A parameter value on an object. The value is always a string on the wire. */
final readonly class Parameter implements Hydratable
{
    public function __construct(
        /** Matches ParameterDefinition::$id. Use this, not $name, as the key. */
        public int $id,
        /** Display label at read time; may change. */
        public string $name,
        public string $value,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            id: Data::int($data, 'id', 'Parameter'),
            name: Data::string($data, 'name', 'Parameter'),
            value: Data::string($data, 'value', 'Parameter'),
        );
    }

    /** Parses a `YYYY-MM-DD` value as UTC midnight; null if it is not one. */
    public function asDate(): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->value, new DateTimeZone('UTC'));

        return ($date !== false && $date->format('Y-m-d') === $this->value) ? $date : null;
    }

    public function asFloat(): ?float
    {
        return is_numeric($this->value) ? (float) $this->value : null;
    }
}
