<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use RuntimeException;

/** Limite de sessões atingido (o WebSocket fecha com 4003 capacity_exceeded). */
final class CapacityExceededException extends RuntimeException
{
    public function __construct(public readonly int $maxInstances)
    {
        parent::__construct("Session capacity exceeded: {$maxInstances}");
    }
}
