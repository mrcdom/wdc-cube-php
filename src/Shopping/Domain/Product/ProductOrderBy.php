<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Product;

/**
 * Ordenações provisionadas de Product.
 * PHP não tem tipos aninhados, então o enum mora ao lado de ProductCriteria.
 */
enum ProductOrderBy
{
    case OLDEST_FIRST;
    case NEWEST_FIRST;
    case NAME_A_TO_Z;
    case CHEAPEST_FIRST;
    case MOST_EXPENSIVE_FIRST;
}
