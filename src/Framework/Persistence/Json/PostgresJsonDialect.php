<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\Sql;

/**
 * PostgreSQL dialect. Builds the object by concatenating text:
 *
 *     '{' || array_to_string(array['"id":' || coalesce(to_json(t.id), 'null'), ...], ',') || '}'
 *
 * instead of `json_build_object(...)`, which takes 2 arguments per field and hits PostgreSQL's
 * limit of 100 function arguments (50 fields). `array[...]` has no such limit.
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
                null => "coalesce({$value}, 'null')",                                    // already JSON
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

    /** PostgreSQL's `encode(..., 'base64')` inserts a line break every 76 characters. */
    public function decodeBinary(string $encoded): string
    {
        $bytes = base64_decode((string) preg_replace('/\s+/', '', $encoded), true);

        return $bytes === false ? '' : $bytes;
    }
}
