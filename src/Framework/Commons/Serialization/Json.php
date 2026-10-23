<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Serialization;

use JsonException;
use stdClass;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * JSON for the REST transport.
 *
 * Decodes into objects (`stdClass`), not associative arrays: that keeps `{}` and `[]` distinct —
 * and the codec needs this to tell a projected collection (object) from a result list (array)
 * by looking at the value's type before reading it.
 */
final class Json
{
    public const int ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;   // 10.0 is written as "10.0": the client tells decimals from integers

    private function __construct()
    {
    }

    /** An empty body counts as `{}`. Invalid JSON ⇒ InvalidRequestException (400). */
    public static function decode(string $text): mixed
    {
        if (trim($text) === '') {
            return new stdClass();
        }
        try {
            return json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidRequestException('Invalid JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }

    /**
     * The fields of a JSON object, in the order they arrived.
     *
     * @return array<string, mixed>
     */
    public static function fields(mixed $value, string $what = 'body'): array
    {
        if (!$value instanceof stdClass) {
            throw new InvalidRequestException("Expected a JSON object in {$what}");
        }

        return get_object_vars($value);
    }

    /**
     * The elements of a JSON array.
     *
     * @return list<mixed>
     */
    public static function elements(mixed $value, string $what): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidRequestException("Expected a JSON array in {$what}");
        }

        return $value;
    }
}
