<?php

namespace App\services;

/* 
    This class will be used for generating sql statmenets that will be identical across quotes, contracts, and invoices
*/
class SqlStatementFactory
{
    public static function makeInsertStatement(string $table, array $values): string
    {
        $columns = array_keys($values);
        $placeholders = array_map(fn($column) => ":$column", $columns);
        return "INSERT INTO {$table} (" . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
    }

    public static function makeEditStatement() {

    }


}
