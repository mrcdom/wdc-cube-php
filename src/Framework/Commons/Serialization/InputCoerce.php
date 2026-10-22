<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Serialization;

use stdClass;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * Lê um valor JSON escalar aceitando a forma "vizinha":
 * número em texto ("42"), booleano como número, etc. null continua null.
 *
 * Objeto ou array onde se esperava um escalar é erro de pedido.
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
            default => throw self::invalid($value, 'texto'),
        };
    }

    /** Inteiro de 64 bits (o int do PHP já tem 64 bits). */
    public static function asInt(mixed $value, ?int $default = null): ?int
    {
        return match (true) {
            $value === null => $default,
            is_bool($value) => $value ? 1 : 0,
            is_int($value), is_string($value) => Coerce::asInt($value),
            is_float($value) => Coerce::asInt($value) ?? throw self::invalid($value, 'inteiro'),
            default => throw self::invalid($value, 'inteiro'),
        };
    }

    public static function asFloat(mixed $value): ?float
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 1.0 : 0.0,
            is_int($value), is_float($value) => (float) $value,
            is_string($value) => is_numeric(trim($value)) ? (float) trim($value) : null,
            default => throw self::invalid($value, 'número'),
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
            default => throw self::invalid($value, 'booleano'),
        };
    }

    private static function invalid(mixed $value, string $expected): InvalidRequestException
    {
        $got = match (true) {
            $value instanceof stdClass => 'objeto',
            is_array($value) => 'array',
            default => get_debug_type($value),
        };

        return new InvalidRequestException("Esperado {$expected}, veio {$got}");
    }
}
