<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

/**
 * Context of a query: generates unique aliases (p1, u2, pi3...) so that each correlated subquery
 * sees its own table and its parent's without ambiguity.
 */
final class QueryContext
{
    private int $counter = 0;

    public function alias(string $prefix): string
    {
        return $prefix . ++$this->counter;
    }
}
