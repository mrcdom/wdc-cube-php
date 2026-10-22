<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Lang;

/**
 * Converte valores soltos (formData vindo do cliente em JSON).
 * Valores inválidos viram null: quem chama decide o que fazer (em geral, um erro na tela).
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

    /** "42", 42 e 42.0 ⇒ 42; "4x", 4.5, "" e null ⇒ null. */
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
