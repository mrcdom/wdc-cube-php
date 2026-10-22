<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Tipo lógico de uma coluna e a conversão banco ⇄ PHP.
 */
enum ColumnType
{
    case Int;
    case Float;
    case Text;
    /** Gravado como 'Y-m-d H:i:s' em UTC (coluna TIMESTAMP; a sessão do banco também está em UTC). */
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
            self::Float => round((float) $value, 2),   // coluna NUMERIC(20,2)
            self::DateTime => self::formatDate($value),
            self::Blob => new Blob((string) $value),
            default => $value,
        };
    }

    /**
     * Converte data para o formato do banco, sempre em UTC.
     * (A coluna não guarda fuso: convertemos para UTC antes de descartá-lo.)
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
