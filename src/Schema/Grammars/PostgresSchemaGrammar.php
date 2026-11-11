<?php

namespace Luany\Database\Schema\Grammars;

use Luany\Database\Schema\Blueprint;
use Luany\Database\Schema\ColumnDefinition;
use Luany\Database\Schema\ForeignKeyDefinition;

final class PostgresSchemaGrammar extends SchemaGrammar
{
    protected function type(ColumnDefinition $column): string
    {
        $o = $column->options;

        return match ($column->type) {
            'string', 'enum' => 'VARCHAR(' . (int) ($o['length'] ?? 255) . ')',
            'char'         => 'CHAR(' . (int) $o['length'] . ')',
            'text', 'longText' => 'TEXT',
            'uuid'         => 'UUID',
            'integer'      => 'INTEGER',
            'bigInteger'   => 'BIGINT',
            'smallInteger', 'tinyInteger' => 'SMALLINT',
            'decimal'      => 'NUMERIC(' . (int) $o['precision'] . ', ' . (int) $o['scale'] . ')',
            'float'        => 'REAL',
            'double'       => 'DOUBLE PRECISION',
            'boolean'      => 'BOOLEAN',
            'date'         => 'DATE',
            'dateTime', 'timestamp' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'time'         => 'TIME(0) WITHOUT TIME ZONE',
            'json'         => 'JSONB',
            'binary'       => 'BYTEA',
            default        => throw new \InvalidArgumentException("Unknown column type [{$column->type}]."),
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    protected function autoIncrementColumn(ColumnDefinition $column): string
    {
        $type = $column->type === 'bigInteger' ? 'BIGSERIAL' : 'SERIAL';
        return $this->grammar->wrap($column->name) . " {$type} PRIMARY KEY";
    }

    protected function compileDropIndex(Blueprint $blueprint, string $name): array
    {
        return ['DROP INDEX ' . $this->grammar->wrap($name)];
    }

    protected function compileDropForeign(Blueprint $blueprint, string $name): array
    {
        return ['ALTER TABLE ' . $this->table($blueprint->table) . ' DROP CONSTRAINT ' . $this->grammar->wrap($name)];
    }

    protected function compileAddForeign(Blueprint $blueprint, ForeignKeyDefinition $fk): array
    {
        return ['ALTER TABLE ' . $this->table($blueprint->table) . ' ADD ' . $this->foreignKey($fk)];
    }
}
