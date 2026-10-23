<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Logical type of a column and the database ⇄ PHP conversion.
 */
enum ColumnType
{
    case Int;
    case Float;
    case Text;
    /** Stored as 'Y-m-d H:i:s' in UTC (TIMESTAMP column; the database session is also in UTC). */
    case DateTime;
    case Blob;

    public const string DATE_FORMAT = 'Y-m-d H:i:s';

    public function toPhp(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Int => (int) $value,
            self::Float => (float) $value,
            self::Text => (string) $value,
            self::DateTime => new DateTimeImmutable((string) $value, new DateTimeZone('UTC')),
            self::Blob => (string) $value,
        };
    }

    public function toDb(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Float => round((float) $value, 2),   // NUMERIC(20,2) column
            self::DateTime => self::formatDate($value),
            self::Blob => new Blob((string) $value),
            default => $value,
        };
    }

    /**
     * Converts a date to the database format, always in UTC.
     * (The column stores no time zone: we convert to UTC before dropping it.)
     */
    public static function formatDate(mixed $value): mixed
    {
        if (!$value instanceof DateTimeInterface) {
            return $value;
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::DATE_FORMAT);
    }
}
