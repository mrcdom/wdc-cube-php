<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Lang;

/**
 * Converts loose values (formData coming from the client as JSON).
 * Invalid values become null: the caller decides what to do (usually, an error on screen).
 */
final class Coerce
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
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => null,
        };
    }

    /** "42", 42 and 42.0 ⇒ 42; "4x", 4.5, "" and null ⇒ null. */
    public static function asInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return floor($value) === $value ? (int) $value : null;
        }
        if (is_string($value)) {
            $int = filter_var(trim($value), FILTER_VALIDATE_INT);

            return $int === false ? null : $int;
        }

        return null;
    }
}
