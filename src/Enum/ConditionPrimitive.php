<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

enum ConditionPrimitive: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Long = 'long';
    case Float = 'float';
    case Double = 'double';
    case NotApplicable = 'n/a';
}
