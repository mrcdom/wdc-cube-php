<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

use Wdc\Framework\Persistence\Sql;

/**
 * Como cada banco monta JSON em SQL. Hoje há a implementação para PostgreSQL; outro banco entra como outra implementação.
 */
interface JsonDialect
{
    /** @param list<JsonField> $fields */
    public function jsonObject(array $fields): Sql;

    /**
     * Agrega um objeto JSON por linha num array JSON, na ordem dada (DENTRO do agregado).
     *
     * @param string $order lista de ordenação ("c3.PRICE DESC, c3.ID ASC") ou ""
     */
    public function jsonArrayAgg(Sql $element, string $order): Sql;

    /** Converte o que o dialeto pôs no JSON para os bytes originais de um BLOB. */
    public function decodeBinary(string $encoded): string;
}
