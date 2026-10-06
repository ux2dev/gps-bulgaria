<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use Ux2Dev\GpsBulgaria\Dto\ObjectType;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Support\Data;

final class ObjectTypesResource
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /object-types: type definitions that describe object parameters.
     *
     * @return list<ObjectType>
     */
    public function list(): array
    {
        return array_map(ObjectType::fromArray(...), Data::rows($this->transport->request('GET', '/object-types'), 'ObjectType'));
    }
}
