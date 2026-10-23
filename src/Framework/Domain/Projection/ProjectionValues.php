<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Projection;

use DateTimeImmutable;

/**
 * Sentinel values for building projections.
 *
 * A projection is the entity itself used as a "mask": non-null field = column to select.
 * The value itself does not matter; these are just a convention (shared with the clients).
 */
final class ProjectionValues
{
    public const bool BOOL = true;
    public const int INT = 1;
    public const float FLOAT = 1.0;
    public const string STR = '~';
    public const string BIN = '';

    private function __construct()
    {
    }

    /** Objects cannot be class constants in PHP, so the date is a method. */
    public static function dateTime(): DateTimeImmutable
    {
        static $value = null;   // function-level static variable: created once, reused

        return $value ??= new DateTimeImmutable('@0');
    }

    /**
     * @template E of object
     * @param E $shape
     * @return ProjectionList<E>
     */
    public static function singletonList(object $shape, ?object $criteria = null): ProjectionList
    {
        return new ProjectionList($shape, $criteria);
    }
}
