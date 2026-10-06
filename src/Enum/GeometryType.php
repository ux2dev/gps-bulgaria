<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

enum GeometryType: string
{
    case Point = 'Point';
    case LineString = 'LineString';
    case Polygon = 'Polygon';
}
