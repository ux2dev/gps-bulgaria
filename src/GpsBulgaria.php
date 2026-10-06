<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Ux2Dev\GpsBulgaria\Config\GpsBulgariaConfig;
use Ux2Dev\GpsBulgaria\Http\Transport;
use Ux2Dev\GpsBulgaria\Resource\AlertsResource;
use Ux2Dev\GpsBulgaria\Resource\ObjectsResource;
use Ux2Dev\GpsBulgaria\Resource\ObjectTypesResource;
use Ux2Dev\GpsBulgaria\Resource\ZonesResource;

/**
 * Entry point for the GPS Bulgaria IoT API v2. One instance per API key.
 */
final class GpsBulgaria
{
    private readonly Transport $transport;

    private ?ObjectsResource $objects = null;

    private ?ObjectTypesResource $objectTypes = null;

    private ?ZonesResource $zones = null;

    private ?AlertsResource $alerts = null;

    public function __construct(
        GpsBulgariaConfig $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
    ) {
        $this->transport = new Transport($config, $httpClient, $requestFactory, $streamFactory);
    }

    public function objects(): ObjectsResource
    {
        return $this->objects ??= new ObjectsResource($this->transport);
    }

    public function objectTypes(): ObjectTypesResource
    {
        return $this->objectTypes ??= new ObjectTypesResource($this->transport);
    }

    public function zones(): ZonesResource
    {
        return $this->zones ??= new ZonesResource;
    }

    /** @experimental The alerts API is not yet live upstream. */
    public function alerts(): AlertsResource
    {
        return $this->alerts ??= new AlertsResource;
    }
}
