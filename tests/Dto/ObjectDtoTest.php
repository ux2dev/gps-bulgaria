<?php

declare(strict_types=1);

use Ux2Dev\GpsBulgaria\Dto\GpsObject;
use Ux2Dev\GpsBulgaria\Dto\ObjectType;
use Ux2Dev\GpsBulgaria\Dto\Parameter;
use Ux2Dev\GpsBulgaria\Enum\PrimitiveType;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

it('hydrates an object from the spec example', function () {
    $o = GpsObject::fromArray(api_fixture('object'));

    expect($o->objectId)->toBe('14574')
        ->and($o->objectType)->toBe('Vehicle')
        ->and($o->name)->toBe('Renault Clio')
        ->and($o->comment)->toBe('Priority delivery unit')
        ->and($o->tags)->toBe(['fleet', 'europe'])
        ->and($o->parameters)->toHaveCount(3)
        ->and($o->parameters[2])->toBeInstanceOf(Parameter::class);
});

it('looks parameters up by id, not by display name', function () {
    $o = GpsObject::fromArray(api_fixture('object'));

    expect($o->parameter(3)?->value)->toBe('CA0000CA')
        ->and($o->parameterValue(1))->toBe('Renault')
        ->and($o->parameter(99))->toBeNull()
        ->and($o->parameterValue(99))->toBeNull();
});

it('normalises an empty objectType and a null comment to null', function () {
    $data = api_fixture('object');
    $data['objectType'] = '';
    $data['comment'] = null;

    $o = GpsObject::fromArray($data);

    expect($o->objectType)->toBeNull()->and($o->comment)->toBeNull();
});

it('fails loudly on a missing required field', function () {
    $data = api_fixture('object');
    unset($data['name']);

    expect(fn () => GpsObject::fromArray($data))
        ->toThrow(InvalidResponseException::class, "GpsObject: missing required field 'name'");
});

it('converts parameter values on request', function () {
    expect((new Parameter(4, 'registered', '2024-03-15'))->asDate()?->format('Y-m-d H:i:s e'))->toBe('2024-03-15 00:00:00 UTC')
        ->and((new Parameter(4, 'registered', '15.03.2024'))->asDate())->toBeNull()
        ->and((new Parameter(4, 'registered', '2024-02-30'))->asDate())->toBeNull()
        ->and((new Parameter(5, 'tank', '55.5'))->asFloat())->toBe(55.5)
        ->and((new Parameter(5, 'tank', 'n/a'))->asFloat())->toBeNull();
});

it('hydrates an object type with its parameter definitions', function () {
    $t = ObjectType::fromArray(api_fixture('object-type'));

    expect($t->name)->toBe('Vehicle')
        ->and($t->parameters)->toHaveCount(3)
        ->and($t->definition(1)?->type)->toBe(PrimitiveType::String)
        ->and($t->definition(1)?->required)->toBeTrue()
        ->and($t->definition(1)?->readOnly)->toBeFalse()
        ->and($t->definition(1)?->values)->toBe(['Renault', 'Peugeot', 'Ford'])
        ->and($t->definition(2)?->values)->toBeNull()
        ->and($t->definition(99))->toBeNull();
});

it('rejects an unknown parameter type', function () {
    $data = api_fixture('object-type');
    $data['parameters'][0]['type'] = 'geopoint';

    expect(fn () => ObjectType::fromArray($data))
        ->toThrow(InvalidResponseException::class, "ParameterDefinition: field 'type' has unknown PrimitiveType value 'geopoint'");
});
