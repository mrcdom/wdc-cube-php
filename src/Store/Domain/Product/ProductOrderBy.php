<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\Product;

/**
 * Provisioned orderings for Product.
 * PHP has no nested types, so the enum lives next to ProductCriteria.
 */
enum ProductOrderBy
{
    case OLDEST_FIRST;
    case NEWEST_FIRST;
    case NAME_A_TO_Z;
    case CHEAPEST_FIRST;
    case MOST_EXPENSIVE_FIRST;
}
