<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

/**
 * Marks a parameter as binary. In PHP, bytes are just a `string`; without the marker the value
 * would reach the database as text rather than BYTEA.
 */
final readonly class Blob
{
    public function __construct(public string $bytes)
    {
    }
}
