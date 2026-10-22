<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\Sql;

/**
 * Uma chave do objeto JSON: nome, expressão SQL e tipo. Tipo null = a expressão já
 * produz JSON (subconsulta de relação) e entra sem conversão.
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
