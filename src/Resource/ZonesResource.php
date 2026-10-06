<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use InvalidArgumentException;
use Ux2Dev\GpsBulgaria\Dto\Zone;
use Ux2Dev\GpsBulgaria\Dto\ZoneInput;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Support\Data;
use Ux2Dev\GpsBulgaria\Support\Path;

final class ZonesResource
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /zones
     *
     * @return list<Zone>
     */
    public function list(bool $includeGeometry = false): array
    {
        $rows = $this->transport->request('GET', '/zones', ['includeGeometry' => $includeGeometry]);

        return array_map(Zone::fromArray(...), Data::rows($rows, 'Zone'));
    }

    /** GET /zones/{zoneID} */
    public function get(string $zoneId, bool $includeGeometry = false): Zone
    {
        return Zone::fromArray($this->transport->request(
            'GET',
            '/zones'.Path::segment($zoneId, 'zoneId'),
            ['includeGeometry' => $includeGeometry],
        ));
    }

    /**
     * POST /zones/search: the zones matching the given ids.
     *
     * @param  array<array-key, string>  $zoneIds
     * @return list<Zone>
     */
    public function search(array $zoneIds, bool $includeGeometry = false): array
    {
        if ($zoneIds === []) {
            throw new InvalidArgumentException('zoneIds must not be empty');
        }

        $rows = $this->transport->request(
            'POST',
            '/zones/search',
            ['includeGeometry' => $includeGeometry],
            ['zoneIDs' => array_values($zoneIds)],
        );

        return array_map(Zone::fromArray(...), Data::rows($rows, 'Zone'));
    }

    /** POST /zones: never retried, as creation is not idempotent. */
    public function create(ZoneInput $input): Zone
    {
        return Zone::fromArray($this->transport->request('POST', '/zones', [], $input->toArray()));
    }
}
