<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Serialization;

use JsonException;
use stdClass;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * JSON do transporte REST.
 *
 * Decodifica com objetos (`stdClass`), não arrays associativos: assim `{}` e `[]` continuam
 * distintos — e o codec precisa disso para separar uma coleção projetada (objeto) de uma lista
 * de resultados (array) olhando o tipo do valor antes de lê-lo.
 */
final class Json
{
    public const int ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;   // 10.0 sai "10.0": o cliente distingue decimal de inteiro

    private function __construct()
    {
    }

    /** Corpo vazio vale como `{}`. JSON inválido ⇒ InvalidRequestException (400). */
    public static function decode(string $text): mixed
    {
        if (trim($text) === '') {
            return new stdClass();
        }
        try {
            return json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidRequestException('JSON inválido: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }

    /**
     * Os campos de um objeto JSON, na ordem em que vieram.
     *
     * @return array<string, mixed>
     */
    public static function fields(mixed $value, string $what = 'corpo'): array
    {
        if (!$value instanceof stdClass) {
            throw new InvalidRequestException("Esperado um objeto JSON em {$what}");
        }

        return get_object_vars($value);
    }

    /**
     * Os elementos de um array JSON.
     *
     * @return list<mixed>
     */
    public static function elements(mixed $value, string $what): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidRequestException("Esperado um array JSON em {$what}");
        }

        return $value;
    }
}
