<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Purchase;

/**
 * Ordenações provisionadas de Purchase.
 * PHP não tem tipos aninhados, então o enum mora ao lado de PurchaseCriteria.
 */
enum PurchaseOrderBy
{
    case OLDEST_FIRST;
    case NEWEST_FIRST;
    case MOST_RECENT_PURCHASE_FIRST;
    case EARLIEST_PURCHASE_FIRST;
}
