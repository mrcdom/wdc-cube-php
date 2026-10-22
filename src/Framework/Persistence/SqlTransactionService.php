<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Throwable;
use Wdc\Framework\Commons\Concurrent\FiberValue;
use Wdc\Framework\Domain\Transaction\TransactionService;

/**
 * TransactionService sobre o {@see Database}.
 *
 * REQUIRED reentrante: a chamada mais externa é a "dona" — pega uma conexão do pool, abre a
 * transação e a liga à Fiber corrente; as chamadas internas só participam. Exceção numa
 * participante marca rollback-only.
 *
 * A transação é da Fiber, não do processo: enquanto uma sessão está dentro de `required()`,
 * as consultas das outras sessões continuam indo para o pool, fora dela. O que for disparado
 * em outra Fiber (um `async()`, um timer) também roda fora da transação.
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
            // transação remota ligada a esta Fiber: participa; a fronteira é do cliente REST
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
