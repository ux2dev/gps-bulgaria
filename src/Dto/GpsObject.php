<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** A tracked object (vehicle, asset, …). Named GpsObject because `object` is reserved in PHP. */
final readonly class GpsObject implements Hydratable
{
    /**
     * @param  list<string>  $tags
     * @param  list<Parameter>  $parameters
     */
    public function __construct(
        public string $objectId,
        /** Name of the object's type; null when the object has no type. */
        public ?string $objectType,
        public string $name,
        public ?string $comment,
        public array $tags,
        public array $parameters,
    ) {}

    public static function fromArray(array $data): static
    {
        $type = Data::string($data, 'objectType', 'GpsObject');

        return new self(
            objectId: Data::string($data, 'objectID', 'GpsObject'),
            objectType: $type === '' ? null : $type,
            name: Data::string($data, 'name', 'GpsObject'),
            comment: Data::nullableString($data, 'comment', 'GpsObject'),
            tags: Data::stringList($data, 'tags', 'GpsObject'),
            parameters: Data::objects($data, 'parameters', 'GpsObject', Parameter::fromArray(...)),
        );
    }

    /** Finds a parameter by its stable definition id. */
    public function parameter(int $id): ?Parameter
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->id === $id) {
                return $parameter;
            }
        }

        return null;
    }

    public function parameterValue(int $id): ?string
    {
        return $this->parameter($id)?->value;
    }
}
