<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Serialization;

use DateTimeImmutable;
use DateTimeZone;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * Date and time in ISO-8601 with offset: `2024-01-15T10:30:00Z`,
 * `2024-01-15T10:30:00.25-03:00`. Fraction only when non-zero; a zero offset becomes "Z".
 */
final class IsoDateTime
{
    /** the year may be signed and have up to 9 digits: clients send year -999999999 as a sentinel value in projections */
    private const string PATTERN = '/^[+-]?\d{4,9}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:\d{2}(:\d{2})?)$/';

    private function __construct()
    {
    }

    public static function format(DateTimeImmutable $value): string
    {
        $text = $value->format('Y-m-d\TH:i:s');
        if ((int) $value->format('Y') > 9999) {
            $text = '+' . $text;   // a year outside 0000–9999 gets a sign (extended ISO-8601)
        }
        $micros = (int) $value->format('u');
        if ($micros !== 0) {
            $text .= '.' . rtrim(sprintf('%06d', $micros), '0');
        }

        return $text . ($value->getOffset() === 0 ? 'Z' : $value->format('P'));
    }

    /**
     * An instant in UTC: `2024-01-15T10:30:00Z`, with the
     * fraction (if any) in groups of 3 digits — `.250`, `.123456`.
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

    /** Accepts strict ISO-8601 only — rejects "now", "tomorrow" and the like, which PHP would accept. */
    public static function parse(string $text): DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $text) !== 1) {
            throw new InvalidRequestException("Date and time not in ISO-8601 format with offset: '{$text}'");
        }
        // PHP stores microseconds: cut the fraction to 6 digits (the format allows up to 9, nanoseconds)
        $text = preg_replace('/(\.\d{6})\d+/', '$1', $text) ?? $text;

        return new DateTimeImmutable($text);
    }
}
