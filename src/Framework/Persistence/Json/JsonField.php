<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\Sql;

/**
 * A key of the JSON object: name, SQL expression and type. A null type means the expression
 * already produces JSON (a relation subquery) and goes in without conversion.
 */
final readonly class JsonField
{
    public function __construct(
        public string $key,
        public Sql $expr,
        public ?ColumnType $type,
    ) {
    }
}
