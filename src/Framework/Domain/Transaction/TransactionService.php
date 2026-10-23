<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Transaction;

/**
 * Programmatic transaction demarcation, CMT style.
 *
 * In PHP a function that "returns nothing" returns null, so a single generic method
 * serves both work without a result and work that returns a value.
 *
 * Only REQUIRED is implemented for now — it is the only one in use (checkout).
 */
interface TransactionService
{
    /**
     * Runs $work inside a transaction: joins the current one or opens a new one.
     * Commits on normal return; rolls back on exception (rethrown) or if setRollbackOnly().
     *
     * @template T
     * @param callable(TransactionContext): T $work
     * @return T
     */
    public function required(callable $work): mixed;
}
