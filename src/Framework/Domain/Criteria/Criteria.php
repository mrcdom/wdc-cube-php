<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * An XxxCriteria: aggregates one Criterion per filterable field.
 */
interface Criteria
{
    /** @return list<Criterion<*, *>> */
    public function criterions(): array;
}
