<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Wdc\Framework\Domain\Transaction\TransactionContext;

/** @internal usado só pelo PdoTransactionService */
final class PdoTransactionContext implements TransactionContext
{
    private bool $rollbackOnly = false;

    private bool $active = true;

    public function setRollbackOnly(): void
    {
        $this->rollbackOnly = true;
    }

    public function isRollbackOnly(): bool
    {
        return $this->rollbackOnly;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function close(): void
    {
        $this->active = false;
    }
}
