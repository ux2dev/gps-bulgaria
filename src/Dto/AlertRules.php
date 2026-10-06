<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Dto;

use Ux2Dev\GpsBulgaria\Contracts\Hydratable;
use Ux2Dev\GpsBulgaria\Support\Data;

/** @experimental The alerts API is not yet live upstream. */
final readonly class AlertRules implements Hydratable
{
    public function __construct(
        public AlertRuleGroup $trigger,
        public AlertRuleGroup $clear,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            trigger: AlertRuleGroup::fromArray(Data::object($data, 'trigger', 'AlertRules')),
            clear: AlertRuleGroup::fromArray(Data::object($data, 'clear', 'AlertRules')),
        );
    }
}
