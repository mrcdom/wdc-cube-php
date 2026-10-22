<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Security;

use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;

/**
 * @extends SecuredRepository<PurchaseItem, PurchaseItemCriteria>
 */
final class SecuredPurchaseItemRepository extends SecuredRepository implements PurchaseItemRepository
{
}
