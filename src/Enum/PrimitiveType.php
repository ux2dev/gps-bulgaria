<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

/** Declared type of an object parameter. Values on the wire are always strings. */
enum PrimitiveType: string
{
    case String = 'string';
    case Number = 'number';
    case Date = 'date';
    case File = 'file';
}
