<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

final readonly class ObjectType implements Hydratable
{
    /** @param list<ParameterDefinition> $parameters */
    public function __construct(
        public string $name,
        public array $parameters,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            name: Data::string($data, 'name', 'ObjectType'),
            parameters: Data::objects($data, 'parameters', 'ObjectType', ParameterDefinition::fromArray(...)),
        );
    }

    public function definition(int $id): ?ParameterDefinition
    {
        foreach ($this->parameters as $definition) {
            if ($definition->id === $id) {
                return $definition;
            }
        }

        return null;
    }
}
