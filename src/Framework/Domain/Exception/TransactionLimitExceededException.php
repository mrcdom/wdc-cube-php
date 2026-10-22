<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Exception;

use RuntimeException;

/** Teto de transações remotas abertas atingido, global ou por dono (equivale a HTTP 429). */
class TransactionLimitExceededException extends RuntimeException
{
}
