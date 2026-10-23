<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\Purchase;

use Wdc\Framework\Domain\Repository\Repository;

/**
 * @extends Repository<Purchase, PurchaseCriteria>
 */
interface PurchaseRepository extends Repository
{
}
