<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Serialization;

use DateTimeImmutable;
use DateTimeZone;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * Data e hora em ISO-8601 com deslocamento: `2024-01-15T10:30:00Z`,
 * `2024-01-15T10:30:00.25-03:00`. Fração só quando não é zero; deslocamento zero vira "Z".
 */
final class IsoDateTime
{
    /** o ano pode ter sinal e até 9 dígitos: clientes mandam o ano -999999999 como valor-sentinela nas projeções */
    private const string PATTERN = '/^[+-]?\d{4,9}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:\d{2}(:\d{2})?)$/';

    private function __construct()
    {
    }

    public static function format(DateTimeImmutable $value): string
    {
        $text = $value->format('Y-m-d\TH:i:s');
        if ((int) $value->format('Y') > 9999) {
            $text = '+' . $text;   // ano fora de 0000–9999 leva sinal (ISO-8601 estendido)
        }
        $micros = (int) $value->format('u');
        if ($micros !== 0) {
            $text .= '.' . rtrim(sprintf('%06d', $micros), '0');
        }

        return $text . ($value->getOffset() === 0 ? 'Z' : $value->format('P'));
    }

    /**
     * Um instante em UTC: `2024-01-15T10:30:00Z`, com a
     * fração (quando há) em grupos de 3 dígitos — `.250`, `.123456`.
     */
    public static function formatInstant(DateTimeImmutable $value): string
    {
        $utc = $value->setTimezone(new DateTimeZone('UTC'));
        $micros = (int) $utc->format('u');
        $fraction = match (true) {
            $micros === 0 => '',
            $micros % 1000 === 0 => sprintf('.%03d', intdiv($micros, 1000)),
            default => sprintf('.%06d', $micros),
        };

        return $utc->format('Y-m-d\TH:i:s') . $fraction . 'Z';
    }

    /** Aceita só ISO-8601 estrito — recusa "now", "tomorrow" e cia., que o PHP aceitaria. */
    public static function parse(string $text): DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $text) !== 1) {
            throw new InvalidRequestException("Data e hora fora do formato ISO-8601 com deslocamento: '{$text}'");
        }
        // o PHP guarda microssegundos: corta a fração em 6 dígitos (o formato admite até 9, nanossegundos)
        $text = preg_replace('/(\.\d{6})\d+/', '$1', $text) ?? $text;

        return new DateTimeImmutable($text);
    }
}
