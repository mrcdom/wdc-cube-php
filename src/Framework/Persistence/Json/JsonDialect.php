<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

use Wdc\Framework\Persistence\Sql;

/**
 * How each database builds JSON in SQL. Today there is a PostgreSQL implementation; another database comes in as another implementation.
 */
interface JsonDialect
{
    /** @param list<JsonField> $fields */
    public function jsonObject(array $fields): Sql;

    /**
     * Aggregates one JSON object per row into a JSON array, in the given order (INSIDE the aggregate).
     *
     * @param string $order ordering list ("c3.PRICE DESC, c3.ID ASC") or ""
     */
    public function jsonArrayAgg(Sql $element, string $order): Sql;

    /** Converts what the dialect put into the JSON back to the original bytes of a BLOB. */
    public function decodeBinary(string $encoded): string;
}
