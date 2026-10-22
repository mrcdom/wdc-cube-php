<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Util;

/**
 * Monta e lê a parte "?a=1&b=2" de um intent.
 *
 * - nomes e valores são codificados com rawurlencode (sem isso, um valor com "&" quebraria
 *   a URL);
 * - chave repetida vira lista.
 *
 * Não usamos o `parse_str()` nativo porque ele troca "." e espaço por "_" nos nomes e
 * interpreta "a[]=1" como array — comportamentos que o protocolo não tem.
 */
final class QueryString
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $parameters valores escalares ou listas de escalares
     */
    public static function build(array $parameters): string
    {
        $parts = [];
        foreach ($parameters as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $text = self::asString($item);
                if ($text !== null && trim($text) !== '') {   // nulos e brancos são omitidos
                    $parts[] = rawurlencode($name) . '=' . rawurlencode($text);
                }
            }
        }

        return implode('&', $parts);
    }

    /**
     * Pares sem "=" são ignorados; "+" vira espaço; %XX é decodificado (UTF-8).
     *
     * @return array<string, string|list<string>>
     */
    public static function parse(?string $query): array
    {
        $result = [];
        if ($query === null || trim($query) === '') {
            return $result;
        }

        foreach (explode('&', $query) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = urldecode(substr($pair, 0, $eq));    // urldecode trata "+" como espaço
            $value = urldecode(substr($pair, $eq + 1));
            if (!array_key_exists($name, $result)) {
                $result[$name] = $value;
            } elseif (is_array($result[$name])) {
                $result[$name][] = $value;
            } else {
                $result[$name] = [$result[$name], $value];
            }
        }

        return $result;
    }

    public static function asString(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => null,
        };
    }
}
