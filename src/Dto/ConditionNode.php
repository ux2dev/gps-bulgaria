<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Enum\ConditionOperator;
use Ux2Dev\GpsBulgaria\Enum\ConditionPrimitive;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;
use Ux2Dev\GpsBulgaria\Support\Data;

/**
 * One node of an alert condition tree. Logical nodes (NOT/AND/OR) carry
 * children; comparisons carry field/value/primitive; zone operators carry
 * only a value.
 *
 * @experimental The alerts API is not yet live upstream.
 */
final readonly class ConditionNode implements Hydratable
{
    /**
     * @param  list<ConditionNode>  $children
     * @param  string|int|float|bool|list<string|int|float|bool>|null  $value
     */
    public function __construct(
        public ConditionOperator $op,
        public array $children,
        public ?string $field,
        public string|int|float|bool|array|null $value,
        public ?ConditionPrimitive $primitive,
    ) {}

    public static function fromArray(array $data): static
    {
        $primitive = Data::nullableString($data, 'primitive', 'ConditionNode');

        return new self(
            op: Data::enum(ConditionOperator::class, $data, 'op', 'ConditionNode'),
            children: Data::objects($data, 'children', 'ConditionNode', self::fromArray(...), required: false),
            field: Data::nullableString($data, 'field', 'ConditionNode'),
            value: self::value($data['value'] ?? null),
            primitive: $primitive === null ? null : Data::enum(ConditionPrimitive::class, $data, 'primitive', 'ConditionNode'),
        );
    }

    public function isLogical(): bool
    {
        return in_array($this->op, [ConditionOperator::LogicalNot, ConditionOperator::LogicalAnd, ConditionOperator::LogicalOr], true);
    }

    public function isZoneOperator(): bool
    {
        return $this->op === ConditionOperator::InZone || $this->op === ConditionOperator::OutsideZone;
    }

    /** @return string|int|float|bool|list<string|int|float|bool>|null */
    private static function value(mixed $value): string|int|float|bool|array|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value) && array_is_list($value) && array_filter($value, is_scalar(...)) === $value) {
            /** @var list<string|int|float|bool> $value */
            return $value;
        }

        throw new InvalidResponseException("ConditionNode: field 'value' must be a scalar or a list of scalars");
    }
}
