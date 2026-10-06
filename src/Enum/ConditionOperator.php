<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Enum;

enum ConditionOperator: string
{
    case LogicalNot = 'NOT';
    case LogicalAnd = 'AND';
    case LogicalOr = 'OR';
    case Eq = 'EQ';
    case NotEq = 'NOT_EQ';
    case Lt = 'LT';
    case Lte = 'LTE';
    case Gt = 'GT';
    case Gte = 'GTE';
    case StartsWith = 'STARTS_WITH';
    case NotStartsWith = 'NOT_STARTS_WITH';
    case Contains = 'CONTAINS';
    case NotContains = 'NOT_CONTAINS';
    case InZone = 'IN_ZONE';
    case OutsideZone = 'OUTSIDE_ZONE';
}
