<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Support;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Ux2Dev\GpsBulgaria\Exception\InvalidResponseException;

/**
 * Typed readers over decoded JSON, used by every DTO's fromArray(). Each
 * failure names the DTO and the field so spec drift is easy to diagnose.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class Data
{
    private const RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/i';

    /** @param array<mixed> $d */
    public static function string(array $d, string $key, string $dto): string
    {
        $v = self::required($d, $key, $dto);

        return is_string($v) ? $v : throw self::type($dto, $key, 'string', $v);
    }

    /** @param array<mixed> $d */
    public static function nullableString(array $d, string $key, string $dto): ?string
    {
        $v = $d[$key] ?? null;

        return ($v === null || is_string($v)) ? $v : throw self::type($dto, $key, 'string', $v);
    }

    /** @param array<mixed> $d */
    public static function int(array $d, string $key, string $dto): int
    {
        $v = self::required($d, $key, $dto);

        return is_int($v) ? $v : throw self::type($dto, $key, 'int', $v);
    }

    /** @param array<mixed> $d */
    public static function float(array $d, string $key, string $dto): float
    {
        $v = self::required($d, $key, $dto);

        return (is_int($v) || is_float($v)) ? (float) $v : throw self::type($dto, $key, 'number', $v);
    }

    /** @param array<mixed> $d */
    public static function nullableFloat(array $d, string $key, string $dto): ?float
    {
        $v = $d[$key] ?? null;

        return match (true) {
            $v === null => null,
            is_int($v), is_float($v) => (float) $v,
            default => throw self::type($dto, $key, 'number', $v),
        };
    }

    /** @param array<mixed> $d */
    public static function bool(array $d, string $key, string $dto, ?bool $default = null): bool
    {
        if ($default !== null && ! array_key_exists($key, $d)) {
            return $default;
        }

        $v = self::required($d, $key, $dto);

        return is_bool($v) ? $v : throw self::type($dto, $key, 'bool', $v);
    }

    /** @param array<mixed> $d */
    public static function dateTime(array $d, string $key, string $dto): DateTimeImmutable
    {
        return self::parseDateTime(self::string($d, $key, $dto), $key, $dto);
    }

    /** @param array<mixed> $d */
    public static function nullableDateTime(array $d, string $key, string $dto): ?DateTimeImmutable
    {
        $v = self::nullableString($d, $key, $dto);

        return $v === null ? null : self::parseDateTime($v, $key, $dto);
    }

    /**
     * @param  array<mixed>  $d
     * @return array<string, string>
     */
    public static function stringMap(array $d, string $key, string $dto): array
    {
        $v = self::required($d, $key, $dto);
        if (! is_array($v)) {
            throw new InvalidResponseException("{$dto}: field '{$key}' must be a map of strings");
        }

        $out = [];
        foreach ($v as $k => $item) {
            if (! is_string($item)) {
                throw new InvalidResponseException("{$dto}: field '{$key}' must be a map of strings");
            }
            $out[(string) $k] = $item;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $d
     * @return list<string>
     */
    public static function stringList(array $d, string $key, string $dto, bool $required = true): array
    {
        if (! $required && ($d[$key] ?? null) === null) {
            return [];
        }

        $v = self::required($d, $key, $dto);
        if (! is_array($v) || ! array_is_list($v)) {
            throw new InvalidResponseException("{$dto}: field '{$key}' must be a list of strings");
        }

        foreach ($v as $item) {
            if (! is_string($item)) {
                throw new InvalidResponseException("{$dto}: field '{$key}' must be a list of strings");
            }
        }

        /** @var list<string> $v */
        return $v;
    }

    /**
     * @param  array<mixed>  $d
     * @return array<mixed>
     */
    public static function object(array $d, string $key, string $dto): array
    {
        $v = self::required($d, $key, $dto);

        return is_array($v) ? $v : throw self::type($dto, $key, 'object', $v);
    }

    /**
     * @param  array<mixed>  $d
     * @return array<mixed>|null
     */
    public static function nullableObject(array $d, string $key, string $dto): ?array
    {
        $v = $d[$key] ?? null;

        return ($v === null || is_array($v)) ? $v : throw self::type($dto, $key, 'object', $v);
    }

    /**
     * @template T
     *
     * @param  array<mixed>  $d
     * @param  callable(array<mixed>): T  $map
     * @return list<T>
     */
    public static function objects(array $d, string $key, string $dto, callable $map, bool $required = true): array
    {
        if (! $required && ($d[$key] ?? null) === null) {
            return [];
        }

        $v = self::required($d, $key, $dto);
        if (! is_array($v) || ! array_is_list($v)) {
            throw new InvalidResponseException("{$dto}: field '{$key}' must be a list of objects");
        }

        $out = [];
        foreach ($v as $row) {
            if (! is_array($row)) {
                throw new InvalidResponseException("{$dto}: field '{$key}' must be a list of objects");
            }
            $out[] = $map($row);
        }

        return $out;
    }

    /**
     * @template E of BackedEnum
     *
     * @param  class-string<E>  $enum
     * @param  array<mixed>  $d
     * @return E
     */
    public static function enum(string $enum, array $d, string $key, string $dto): BackedEnum
    {
        $raw = self::string($d, $key, $dto);
        $short = substr($enum, (int) strrpos($enum, '\\') + 1);

        return $enum::tryFrom($raw)
            ?? throw new InvalidResponseException("{$dto}: field '{$key}' has unknown {$short} value '{$raw}'");
    }

    /**
     * Validates a top-level JSON array response whose items are objects.
     *
     * @param  array<mixed>  $decoded
     * @return list<array<mixed>>
     */
    public static function rows(array $decoded, string $dto): array
    {
        if (! array_is_list($decoded)) {
            throw new InvalidResponseException("{$dto}: expected a JSON array of objects");
        }

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                throw new InvalidResponseException("{$dto}: expected a JSON array of objects");
            }
        }

        /** @var list<array<mixed>> $decoded */
        return $decoded;
    }

    private static function parseDateTime(string $value, string $key, string $dto): DateTimeImmutable
    {
        if (! preg_match(self::RFC3339, $value)) {
            throw new InvalidResponseException("{$dto}: field '{$key}' is not an RFC 3339 date-time: '{$value}'");
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception $e) {
            throw new InvalidResponseException("{$dto}: field '{$key}' is not an RFC 3339 date-time: '{$value}'", 0, $e);
        }
    }

    /** @param array<mixed> $d */
    private static function required(array $d, string $key, string $dto): mixed
    {
        if (! array_key_exists($key, $d) || $d[$key] === null) {
            throw new InvalidResponseException("{$dto}: missing required field '{$key}'");
        }

        return $d[$key];
    }

    private static function type(string $dto, string $key, string $expected, mixed $actual): InvalidResponseException
    {
        return new InvalidResponseException("{$dto}: field '{$key}' must be {$expected}, got ".get_debug_type($actual));
    }
}
