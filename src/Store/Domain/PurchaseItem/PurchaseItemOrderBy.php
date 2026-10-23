<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\PurchaseItem;

/**
 * Provisioned orderings for PurchaseItem.
 * PHP has no nested types, so the enum lives next to PurchaseItemCriteria.
 */
enum PurchaseItemOrderBy
{
    case OLDEST_FIRST;
    case NEWEST_FIRST;
    case MOST_EXPENSIVE_FIRST;
    case LARGEST_QUANTITY_FIRST;
}
