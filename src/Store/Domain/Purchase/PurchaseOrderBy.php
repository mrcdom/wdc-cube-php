<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\Purchase;

/**
 * Provisioned orderings for Purchase.
 * PHP has no nested types, so the enum lives next to PurchaseCriteria.
 */
enum PurchaseOrderBy
{
    case OLDEST_FIRST;
    case NEWEST_FIRST;
    case MOST_RECENT_PURCHASE_FIRST;
    case EARLIEST_PURCHASE_FIRST;
}
