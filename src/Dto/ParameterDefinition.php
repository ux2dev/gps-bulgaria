<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Enum\PrimitiveType;
use Ux2Dev\GpsBulgaria\Support\Data;

final readonly class ParameterDefinition implements Hydratable
{
    /** @param list<string>|null $values Allowed values for fixed-list parameters; null otherwise. */
    public function __construct(
        public int $id,
        public string $name,
        public PrimitiveType $type,
        public bool $required,
        public bool $readOnly,
        public ?array $values,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            id: Data::int($data, 'id', 'ParameterDefinition'),
            name: Data::string($data, 'name', 'ParameterDefinition'),
            type: Data::enum(PrimitiveType::class, $data, 'type', 'ParameterDefinition'),
            required: Data::bool($data, 'required', 'ParameterDefinition'),
            readOnly: Data::bool($data, 'readOnly', 'ParameterDefinition'),
            values: ($data['values'] ?? null) === null ? null : Data::stringList($data, 'values', 'ParameterDefinition'),
        );
    }
}
