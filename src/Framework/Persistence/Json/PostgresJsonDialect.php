<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\Sql;

/**
 * Dialeto PostgreSQL. Monta o objeto concatenando texto:
 *
 *     '{' || array_to_string(array['"id":' || coalesce(to_json(t.id), 'null'), ...], ',') || '}'
 *
 * em vez de `json_build_object(...)`, que recebe 2 argumentos por campo e esbarra no limite
 * de 100 argumentos de função do PostgreSQL (50 campos). `array[...]` não tem esse limite.
 */
final class PostgresJsonDialect implements JsonDialect
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
                null => "coalesce({$value}, 'null')",                                    // já é JSON
                ColumnType::Blob => "coalesce(to_json(encode({$value}, 'base64')), 'null')",
                default => "coalesce(to_json({$value}), 'null')",
            };
            $key = str_replace(['"', "'"], ['\\"', "''"], $field->key);
            $parts[] = "'\"{$key}\":' || {$value}";
            array_push($params, ...$field->expr->params);
        }

        return new Sql("('{' || array_to_string(array[" . implode(', ', $parts) . "], ',') || '}')", $params);
    }

    public function jsonArrayAgg(Sql $element, string $order): Sql
    {
        $orderBy = $order === '' ? '' : " ORDER BY {$order}";

        return new Sql("('[' || coalesce(string_agg({$element->sql}, ','{$orderBy}), '') || ']')", $element->params);
    }

    /** O `encode(..., 'base64')` do PostgreSQL quebra linha a cada 76 caracteres. */
    public function decodeBinary(string $encoded): string
    {
        $bytes = base64_decode((string) preg_replace('/\s+/', '', $encoded), true);

        return $bytes === false ? '' : $bytes;
    }
}
