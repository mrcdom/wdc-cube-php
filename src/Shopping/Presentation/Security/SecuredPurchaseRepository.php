<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Security;

use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseCriteria;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;

/**
 * @extends SecuredRepository<Purchase, PurchaseCriteria>
 */
final class SecuredPurchaseRepository extends SecuredRepository implements PurchaseRepository
{
}
