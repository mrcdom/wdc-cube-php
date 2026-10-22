<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\PurchaseItem;

/**
 * Ordenações provisionadas de PurchaseItem.
 * PHP não tem tipos aninhados, então o enum mora ao lado de PurchaseItemCriteria.
 */
enum PurchaseItemOrderBy
{
    case OLDEST_FIRST;
    case NEWEST_FIRST;
    case MOST_EXPENSIVE_FIRST;
    case LARGEST_QUANTITY_FIRST;
}
