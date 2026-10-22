<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\Sql;

/**
 * Dialeto SQLite (exige 3.44+ por causa do ORDER BY dentro do agregado).
 *
 * - `json_object('k', v, ...)` monta o objeto;
 * - subconsultas que já devolvem JSON vão dentro de `json(...)` — sem isso o SQLite as
 *   trataria como texto e o objeto aninhado sairia escapado ("{\"id\":1}");
 * - BLOB não cabe em JSON: vai como hex. `hex(NULL)` devolve "" no SQLite, por isso o CASE.
 */
final class SqliteJsonDialect implements JsonDialect
{
    public function jsonObject(array $fields): Sql
    {
        if ($fields === []) {
            return new Sql("'{}'");
        }
        $parts = [];
        $params = [];
        foreach ($fields as $field) {
            $value = $field->expr->sql;
            $value = match ($field->type) {
                null => "json({$value})",
                ColumnType::Blob => "CASE WHEN {$value} IS NULL THEN NULL ELSE hex({$value}) END",
                default => $value,
            };
            $parts[] = "'" . str_replace("'", "''", $field->key) . "', {$value}";
            array_push($params, ...$field->expr->params);
            if ($field->type === ColumnType::Blob) {
                array_push($params, ...$field->expr->params);   // a expressão aparece duas vezes no CASE
            }
        }

        return new Sql('json_object(' . implode(', ', $parts) . ')', $params);
    }

    public function jsonArrayAgg(Sql $element, string $order): Sql
    {
        $orderBy = $order === '' ? '' : " ORDER BY {$order}";

        return new Sql("json_group_array(json({$element->sql}){$orderBy})", $element->params);
    }

    public function decodeBinary(string $encoded): string
    {
        $bytes = hex2bin($encoded);

        return $bytes === false ? '' : $bytes;
    }
}
