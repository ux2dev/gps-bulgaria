<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Resource;

use DateTimeInterface;
use InvalidArgumentException;
use Ux2Dev\GpsBulgaria\Dto\Alert;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Support\Data;
use Ux2Dev\GpsBulgaria\Support\Path;

/**
 * @experimental Upstream marks these endpoints "not yet available; planned
 * for a later release". Shapes follow the published spec and may change.
 */
final class AlertsResource
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /objects/alerts: alerts on any of your objects.
     *
     * @return list<Alert>
     */
    public function list(?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        return $this->fetch('/objects/alerts', $from, $to);
    }

    /**
     * GET /objects/{objectID}/alerts
     *
     * @return list<Alert>
     */
    public function forObject(string $objectId, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        return $this->fetch('/objects'.Path::segment($objectId, 'objectId').'/alerts', $from, $to);
    }

    /** @return list<Alert> */
    private function fetch(string $path, ?DateTimeInterface $from, ?DateTimeInterface $to): array
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('from must not be after to');
        }

        $rows = $this->transport->request('GET', $path, ['from' => $from, 'to' => $to]);

        return array_map(Alert::fromArray(...), Data::rows($rows, 'Alert'));
    }
}
