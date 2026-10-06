<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\Alert;
use Ux2Dev\GpsBulgaria\Dto\ConditionNode;
use Ux2Dev\GpsBulgaria\Enum\ConditionOperator;
use Ux2Dev\GpsBulgaria\Enum\ConditionPrimitive;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

it('hydrates an alert with its recursive rule tree', function () {
    $a = Alert::fromArray(api_fixture('alert'));

    expect($a->eventId)->toBe('6b1e9c2a-f1cd-4748-9d6a-4f7a6e9b2c21')
        ->and($a->objectId)->toBe('14574')
        ->and($a->reason)->toBe('Speeding in an urban area')
        ->and($a->definitionId)->toBe('def-urban-speeding')
        ->and($a->triggeredAt->format(DATE_ATOM))->toBe('2026-09-09T14:44:38+00:00')
        ->and($a->clearedAt)->toBeNull()
        ->and($a->isActive())->toBeTrue()
        ->and($a->sensorData['speed'])->toBe('96');

    $trigger = $a->rules->trigger;
    expect($trigger->durationSeconds)->toBe(60)
        ->and($trigger->rules->op)->toBe(ConditionOperator::LogicalAnd)
        ->and($trigger->rules->isLogical())->toBeTrue()
        ->and($trigger->rules->children)->toHaveCount(2)
        ->and($trigger->rules->children[0]->op)->toBe(ConditionOperator::Gt)
        ->and($trigger->rules->children[0]->field)->toBe('speed')
        ->and($trigger->rules->children[0]->value)->toBe(90)
        ->and($trigger->rules->children[0]->primitive)->toBe(ConditionPrimitive::Double)
        ->and($trigger->rules->children[1]->value)->toBe('urban')
        ->and($a->rules->clear->durationSeconds)->toBe(0)
        ->and($a->rules->clear->rules->children)->toBe([]);
});

it('hydrates zone operators and list values', function () {
    $zone = ConditionNode::fromArray(['op' => 'IN_ZONE', 'value' => '5930']);
    $list = ConditionNode::fromArray(['op' => 'EQ', 'field' => 'key', 'primitive' => 'n/a', 'value' => ['key_on', 'key_off']]);

    expect($zone->isZoneOperator())->toBeTrue()
        ->and($zone->isLogical())->toBeFalse()
        ->and($zone->field)->toBeNull()
        ->and($zone->primitive)->toBeNull()
        ->and($list->value)->toBe(['key_on', 'key_off'])
        ->and($list->primitive)->toBe(ConditionPrimitive::NotApplicable);
});

it('rejects an object as a condition value', function () {
    expect(fn () => ConditionNode::fromArray(['op' => 'EQ', 'value' => ['a' => 1]]))
        ->toThrow(InvalidResponseException::class, "ConditionNode: field 'value' must be a scalar or a list of scalars");
});

it('marks a cleared alert inactive', function () {
    $data = api_fixture('alert');
    $data['clearedAt'] = '2026-09-09T15:00:00Z';

    expect(Alert::fromArray($data)->isActive())->toBeFalse();
});
