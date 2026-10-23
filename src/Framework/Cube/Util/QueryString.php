<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Util;

/**
 * Builds and reads the "?a=1&b=2" part of an intent.
 *
 * - names and values are encoded with rawurlencode (without it, a value containing "&" would
 *   break the URL);
 * - a repeated key becomes a list.
 *
 * We do not use the native `parse_str()` because it replaces "." and spaces with "_" in names
 * and interprets "a[]=1" as an array — behaviours the protocol does not have.
 */
final class QueryString
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $parameters scalar values or lists of scalars
     */
    public static function build(array $parameters): string
    {
        $parts = [];
        foreach ($parameters as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $text = self::asString($item);
                if ($text !== null && trim($text) !== '') {   // nulls and blanks are omitted
                    $parts[] = rawurlencode($name) . '=' . rawurlencode($text);
                }
            }
        }

        return implode('&', $parts);
    }

    /**
     * Pairs without "=" are ignored; "+" becomes a space; %XX is decoded (UTF-8).
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
            $name = urldecode(substr($pair, 0, $eq));    // urldecode treats "+" as a space
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
