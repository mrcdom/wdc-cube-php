<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Transaction;

interface TransactionContext
{
    public function setRollbackOnly(): void;

    public function isRollbackOnly(): bool;

    public function isActive(): bool;
}
