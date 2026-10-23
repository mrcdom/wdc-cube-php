<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Exception;

use RuntimeException;

/** Cap on open remote transactions reached, globally or per owner (maps to HTTP 429). */
class TransactionLimitExceededException extends RuntimeException
{
}
