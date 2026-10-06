<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use DateTimeInterface;
use InvalidArgumentException;
use Ux2Dev\GpsBulgaria\Dto\GpsObject;
use Ux2Dev\GpsBulgaria\Dto\ObjectStatus;
use Ux2Dev\GpsBulgaria\Dto\Route;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Support\Data;
use Ux2Dev\GpsBulgaria\Support\Path;

final class ObjectsResource
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /objects: your objects with type, tags and parameter values.
     *
     * @return list<GpsObject>
     */
    public function list(): array
    {
        return array_map(GpsObject::fromArray(...), Data::rows($this->transport->request('GET', '/objects'), 'GpsObject'));
    }

    /** GET /objects/{objectID} */
    public function get(string $objectId): GpsObject
    {
        return GpsObject::fromArray($this->transport->request('GET', '/objects'.Path::segment($objectId, 'objectId')));
    }

    /**
     * GET /objects/statuses: live status of every object.
     *
     * @return list<ObjectStatus>
     */
    public function statuses(): array
    {
        return array_map(ObjectStatus::fromArray(...), Data::rows($this->transport->request('GET', '/objects/statuses'), 'ObjectStatus'));
    }

    /** GET /objects/{objectID}/status */
    public function status(string $objectId): ObjectStatus
    {
        return ObjectStatus::fromArray($this->transport->request('GET', '/objects'.Path::segment($objectId, 'objectId').'/status'));
    }

    /**
     * GET /objects/{objectID}/routes: trips driven between $from and $to.
     * Times are sent as UTC instants regardless of the input's time zone.
     *
     * @return list<Route>
     */
    public function routes(
        string $objectId,
        DateTimeInterface $from,
        DateTimeInterface $to,
        bool $includeAddresses = false,
        bool $includePoints = false,
    ): array {
        $path = '/objects'.Path::segment($objectId, 'objectId').'/routes';

        if ($from > $to) {
            throw new InvalidArgumentException('from must not be after to');
        }

        $rows = $this->transport->request('GET', $path, [
            'from' => $from,
            'to' => $to,
            'includeAddresses' => $includeAddresses,
            'includePoints' => $includePoints,
        ]);

        return array_map(Route::fromArray(...), Data::rows($rows, 'Route'));
    }
}
