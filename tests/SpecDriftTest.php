<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Ux2Dev\GpsBulgaria\Dto;
use Ux2Dev\GpsBulgaria\Enum\ConditionOperator;
use Ux2Dev\GpsBulgaria\Enum\ConditionPrimitive;
use Ux2Dev\GpsBulgaria\Enum\GeometryType;
use Ux2Dev\GpsBulgaria\Enum\PrimitiveType;
use Ux2Dev\GpsBulgaria\Enum\ZoneType;
use Ux2Dev\GpsBulgaria\Resource;

/*
 * Guards against upstream spec changes. When it fails after re-downloading
 * spec/openapi.yaml, update the SDK (and these maps) to match.
 */

function spec(): array
{
    static $spec;

    return $spec ??= Yaml::parseFile(__DIR__.'/../spec/openapi.yaml');
}

const OPERATIONS = [
    'listObjects' => [Resource\ObjectsResource::class, 'list'],
    'getObject' => [Resource\ObjectsResource::class, 'get'],
    'listObjectStatuses' => [Resource\ObjectsResource::class, 'statuses'],
    'getObjectStatus' => [Resource\ObjectsResource::class, 'status'],
    'listObjectRoutes' => [Resource\ObjectsResource::class, 'routes'],
    'listObjectTypes' => [Resource\ObjectTypesResource::class, 'list'],
    'listZones' => [Resource\ZonesResource::class, 'list'],
    'getZone' => [Resource\ZonesResource::class, 'get'],
    'searchZones' => [Resource\ZonesResource::class, 'search'],
    'createZone' => [Resource\ZonesResource::class, 'create'],
    'listAlerts' => [Resource\AlertsResource::class, 'list'],
    'listObjectAlerts' => [Resource\AlertsResource::class, 'forObject'],
];

const SCHEMAS = [
    'Object' => Dto\GpsObject::class,
    'Parameter' => Dto\Parameter::class,
    'ParameterDefinition' => Dto\ParameterDefinition::class,
    'ObjectType' => Dto\ObjectType::class,
    'ObjectStatus' => Dto\ObjectStatus::class,
    'Location' => Dto\Location::class,
    'RoutePoint' => Dto\RoutePoint::class,
    'Route' => Dto\Route::class,
    'Zone' => Dto\Zone::class,
    'ZoneInput' => Dto\ZoneInput::class,
    'Geometry' => Dto\Geometry::class,
    'Alert' => Dto\Alert::class,
    'AlertRules' => Dto\AlertRules::class,
    'AlertRuleGroup' => Dto\AlertRuleGroup::class,
    'ConditionASTNode' => Dto\ConditionNode::class,
];

/** Wire name → PHP property name: objectID → objectId, zoneID → zoneId. */
function phpProperty(string $wire): string
{
    return preg_replace('/ID$/', 'Id', $wire) ?? $wire;
}

it('maps every spec operation to an existing resource method', function () {
    $operationIds = [];
    foreach (spec()['paths'] as $path) {
        foreach ($path as $verb => $operation) {
            if (is_array($operation) && isset($operation['operationId'])) {
                $operationIds[] = $operation['operationId'];
            }
        }
    }

    sort($operationIds);
    $mapped = array_keys(OPERATIONS);
    sort($mapped);

    expect($operationIds)->toBe($mapped);

    foreach (OPERATIONS as $operationId => [$class, $method]) {
        expect(method_exists($class, $method))->toBeTrue("{$operationId} → {$class}::{$method} is missing");
    }
});

it('has a DTO property for every schema property', function (string $schema, string $class) {
    $properties = array_keys(spec()['components']['schemas'][$schema]['properties'] ?? []);
    $reflection = new ReflectionClass($class);

    foreach ($properties as $wire) {
        expect($reflection->hasProperty(phpProperty($wire)))
            ->toBeTrue("{$schema}.{$wire} has no {$class}::\$".phpProperty($wire));
    }
})->with(array_map(fn ($class, $schema) => [$schema, $class], SCHEMAS, array_keys(SCHEMAS)));

it('hydrates every schema example published in the spec', function (string $schema, string $class) {
    $example = spec()['components']['schemas'][$schema]['example'] ?? null;

    if ($example === null || ! method_exists($class, 'fromArray')) {
        expect(true)->toBeTrue();

        return;
    }

    expect($class::fromArray($example))->toBeInstanceOf($class);
})->with(array_map(fn ($class, $schema) => [$schema, $class], SCHEMAS, array_keys(SCHEMAS)));

it('knows every enum value the spec declares', function (string $schemaPath, string $enum) {
    $node = spec()['components']['schemas'];
    foreach (explode('.', $schemaPath) as $segment) {
        $node = $node[$segment];
    }

    $values = array_map(fn (BackedEnum $case) => $case->value, $enum::cases());
    sort($values);
    $declared = $node['enum'];
    sort($declared);

    expect($values)->toBe($declared);
})->with([
    ['ZoneType', ZoneType::class],
    ['PrimitiveType', PrimitiveType::class],
    ['Geometry.properties.type', GeometryType::class],
    ['ConditionASTNode.properties.op', ConditionOperator::class],
    ['ConditionASTNode.properties.primitive', ConditionPrimitive::class],
]);
