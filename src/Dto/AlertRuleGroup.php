<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** @experimental The alerts API is not yet live upstream. */
final readonly class AlertRuleGroup implements Hydratable
{
    public function __construct(
        /** How long the rules must hold before taking effect. */
        public int $durationSeconds,
        public ConditionNode $rules,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            durationSeconds: Data::int($data, 'durationSeconds', 'AlertRuleGroup'),
            rules: ConditionNode::fromArray(Data::object($data, 'rules', 'AlertRuleGroup')),
        );
    }
}
