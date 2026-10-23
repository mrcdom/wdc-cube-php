<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Serialization;

use stdClass;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * Reads a scalar JSON value, accepting its "neighbouring" forms:
 * a number as text ("42"), a boolean as a number, etc. null stays null.
 *
 * An object or array where a scalar was expected is a request error.
 */
final class InputCoerce
{
    private function __construct()
    {
    }

    public static function asString(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => Coerce::asString($value),
            default => throw self::invalid($value, 'text'),
        };
    }

    /** 64-bit integer (PHP's int is already 64 bits). */
    public static function asInt(mixed $value, ?int $default = null): ?int
    {
        return match (true) {
            $value === null => $default,
            is_bool($value) => $value ? 1 : 0,
            is_int($value), is_string($value) => Coerce::asInt($value),
            is_float($value) => Coerce::asInt($value) ?? throw self::invalid($value, 'integer'),
            default => throw self::invalid($value, 'integer'),
        };
    }

    public static function asFloat(mixed $value): ?float
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 1.0 : 0.0,
            is_int($value), is_float($value) => (float) $value,
            is_string($value) => is_numeric(trim($value)) ? (float) trim($value) : null,
            default => throw self::invalid($value, 'number'),
        };
    }

    public static function asBool(mixed $value): ?bool
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value != 0,
            is_string($value) => match (strtolower(trim($value))) {
                'true' => true,
                'false' => false,
                default => null,
            },
            default => throw self::invalid($value, 'boolean'),
        };
    }

    private static function invalid(mixed $value, string $expected): InvalidRequestException
    {
        $got = match (true) {
            $value instanceof stdClass => 'object',
            is_array($value) => 'array',
            default => get_debug_type($value),
        };

        return new InvalidRequestException("Expected {$expected}, got {$got}");
    }
}
