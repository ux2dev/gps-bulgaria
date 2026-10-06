<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

/** Zone shape; selects the geometry: circle=Point+radius, polyline=LineString, polygon/rectangle=Polygon. */
enum ZoneType: string
{
    case Circle = 'circle';
    case Polygon = 'polygon';
    case Polyline = 'polyline';
    case Rectangle = 'rectangle';
}
