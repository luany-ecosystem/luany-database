<?php

namespace Luany\Database\Schema\Grammars;

use Luany\Database\Schema\Blueprint;
use Luany\Database\Schema\ColumnDefinition;
use Luany\Database\Schema\ForeignKeyDefinition;

final class MySqlSchemaGrammar extends SchemaGrammar
{
    protected function type(ColumnDefinition $column): string
    {
        $o = $column->options;

        return match ($column->type) {
            'string'       => 'VARCHAR(' . (int) $o['length'] . ')',
            'char'         => 'CHAR(' . (int) $o['length'] . ')',
            'text'         => 'TEXT',
            'longText'     => 'LONGTEXT',
            'uuid'         => 'CHAR(36)',
            'enum'         => 'ENUM(' . implode(', ', array_map(fn (string $v) => $this->quote($v), $o['values'])) . ')',
            'integer'      => 'INT',
            'bigInteger'   => 'BIGINT',
            'smallInteger' => 'SMALLINT',
            'tinyInteger'  => 'TINYINT',
            'decimal'      => 'DECIMAL(' . (int) $o['precision'] . ', ' . (int) $o['scale'] . ')',
            'float'        => 'FLOAT',
            'double'       => 'DOUBLE',
            'boolean'      => 'TINYINT(1)',
            'date'         => 'DATE',
            'dateTime'     => 'DATETIME',
            'time'         => 'TIME',
            'timestamp'    => 'TIMESTAMP',
            'json'         => 'JSON',
            'binary'       => 'BLOB',
            default        => throw new \InvalidArgumentException("Unknown column type [{$column->type}]."),
        };
    }

    protected function unsignedSuffix(ColumnDefinition $column): string
    {
        return $column->unsigned ? ' UNSIGNED' : '';
    }

    /** MySQL has a native ENUM — no CHECK needed. */
    protected function checkConstraint(ColumnDefinition $column): string
    {
        return '';
    }

    protected function autoIncrementColumn(ColumnDefinition $column): string
    {
        return $this->grammar->wrap($column->name) . ' ' . $this->type($column) . ' UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function tableOptions(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    public function compileRename(string $from, string $to): string
    {
        return 'RENAME TABLE ' . $this->table($from) . ' TO ' . $this->table($to);
    }

    protected function compileDropIndex(Blueprint $blueprint, string $name): array
    {
        return ['ALTER TABLE ' . $this->table($blueprint->table) . ' DROP INDEX ' . $this->grammar->wrap($name)];
    }

    protected function compileDropForeign(Blueprint $blueprint, string $name): array
    {
        return ['ALTER TABLE ' . $this->table($blueprint->table) . ' DROP FOREIGN KEY ' . $this->grammar->wrap($name)];
    }

    protected function compileAddForeign(Blueprint $blueprint, ForeignKeyDefinition $fk): array
    {
        return ['ALTER TABLE ' . $this->table($blueprint->table) . ' ADD ' . $this->foreignKey($fk)];
    }
}
