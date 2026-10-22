<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Throwable;
use Wdc\Framework\Domain\Transaction\TransactionContext;
use Wdc\Framework\Domain\Transaction\TransactionService;

/**
 * TransactionService sobre a conexão PDO única do processo.
 *
 * REQUIRED reentrante: a chamada mais externa é a "dona" (begin/commit/rollback);
 * as internas só participam. Exceção numa participante marca rollback-only.
 *
 * Cuidado com o event loop: a transação pertence à conexão, não à Fiber. Não suspenda
 * (await/delay) dentro de `required()`, senão outra sessão rodaria SQL dentro dela.
 */
final class PdoTransactionService implements TransactionService
{
    private ?PdoTransactionContext $current = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function required(callable $work): mixed
    {
        if ($this->current !== null) {
            try {
                return $work($this->current);
            } catch (Throwable $e) {
                $this->current->setRollbackOnly();
                throw $e;
            }
        }

        $pdo = $this->db->pdo();
        $remote = $pdo->inTransaction();
        if ($remote) {
            // conexão de uma transação remota ligada: participa; a fronteira é do cliente REST
            return $work(new PdoTransactionContext());
        }

        $context = new PdoTransactionContext();
        $this->current = $context;
        $pdo->beginTransaction();
        try {
            $result = $work($context);
            if ($context->isRollbackOnly()) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $context->close();
            $this->current = null;
        }
    }
}
