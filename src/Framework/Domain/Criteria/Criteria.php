<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * Um XxxCriteria: agrega um Criterion por campo filtrável.
 */
interface Criteria
{
    /** @return list<Criterion<*, *>> */
    public function criterions(): array;
}
