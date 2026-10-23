<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Throwable;
use Wdc\Framework\Commons\Concurrent\FiberValue;
use Wdc\Framework\Domain\Transaction\TransactionService;

/**
 * TransactionService on top of {@see Database}.
 *
 * Reentrant REQUIRED: the outermost call is the "owner" — it takes a pool connection, opens the
 * transaction and binds it to the current Fiber; inner calls only participate. An exception in a
 * participant marks the transaction rollback-only.
 *
 * The transaction belongs to the Fiber, not the process: while one session is inside
 * `required()`, the other sessions' queries keep going to the pool, outside it. Anything started
 * on another Fiber (an `async()`, a timer) also runs outside the transaction.
 */
final class SqlTransactionService implements TransactionService
{
    /** @var FiberValue<SqlTransactionContext> */
    private readonly FiberValue $current;

    public function __construct(private readonly Database $db)
    {
        $this->current = new FiberValue(SqlTransactionContext::class);
    }

    public function required(callable $work): mixed
    {
        $context = $this->current->get();
        if ($context !== null) {
            try {
                return $work($context);
            } catch (Throwable $e) {
                $context->setRollbackOnly();
                throw $e;
            }
        }

        if ($this->db->inTransaction()) {
            // remote transaction bound to this Fiber: participate; the REST client owns the boundary
            return $work(new SqlTransactionContext());
        }

        $context = new SqlTransactionContext();
        $transaction = $this->db->beginTransaction();
        $this->current->set($context);
        $previous = $this->db->bind($transaction);
        try {
            $result = $work($context);
            if ($context->isRollbackOnly()) {
                $transaction->rollback();
            } else {
                $transaction->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        } finally {
            $context->close();
            $this->current->set(null);
            $this->db->bind($previous);
        }
    }
}
