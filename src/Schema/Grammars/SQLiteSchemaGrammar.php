<?php

namespace Luany\Database\Schema\Grammars;

use Luany\Database\Schema\Blueprint;
use Luany\Database\Schema\ColumnDefinition;
use Luany\Database\Schema\ForeignKeyDefinition;

final class SQLiteSchemaGrammar extends SchemaGrammar
{
    protected function type(ColumnDefinition $column): string
    {
        $o = $column->options;

        return match ($column->type) {
            'string', 'enum' => 'VARCHAR(' . (int) ($o['length'] ?? 255) . ')',
            'char'         => 'CHAR(' . (int) $o['length'] . ')',
            'text', 'longText', 'json' => 'TEXT',
            'uuid'         => 'CHAR(36)',
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'boolean' => 'INTEGER',
            'decimal'      => 'NUMERIC',
            'float', 'double' => 'REAL',
            'date'         => 'DATE',
            'dateTime', 'timestamp' => 'DATETIME',
            'time'         => 'TIME',
            'binary'       => 'BLOB',
            default        => throw new \InvalidArgumentException("Unknown column type [{$column->type}]."),
        };
    }

    /** SQLite requires exactly "INTEGER PRIMARY KEY" for rowid aliasing. */
    protected function autoIncrementColumn(ColumnDefinition $column): string
    {
        return $this->grammar->wrap($column->name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function compileDropIndex(Blueprint $blueprint, string $name): array
    {
        return ['DROP INDEX ' . $this->grammar->wrap($name)];
    }

    protected function compileDropForeign(Blueprint $blueprint, string $name): array
    {
        throw new \LogicException('SQLite cannot drop a foreign key from an existing table; recreate the table instead.');
    }

    protected function compileAddForeign(Blueprint $blueprint, ForeignKeyDefinition $fk): array
    {
        throw new \LogicException('SQLite cannot add a foreign key to an existing table; declare it in Schema::create().');
    }
}
