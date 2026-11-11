<?php

namespace Luany\Database\Schema\Grammars;

use Luany\Database\Grammar;
use Luany\Database\Schema\Blueprint;
use Luany\Database\Schema\ColumnDefinition;
use Luany\Database\Schema\Expression;
use Luany\Database\Schema\ForeignKeyDefinition;

/**
 * Turns a Blueprint into DDL statements for one driver. Identifiers are
 * validated and quoted by the query Grammar; literal defaults are quoted
 * by PDO.
 */
abstract class SchemaGrammar
{
    public function __construct(
        protected readonly Grammar $grammar,
        protected readonly \PDO $pdo,
    ) {}

    /** SQL type for a column (without NULL / DEFAULT). */
    abstract protected function type(ColumnDefinition $column): string;

    /** Column definition for an auto-incrementing primary key. */
    abstract protected function autoIncrementColumn(ColumnDefinition $column): string;

    /** @return list<string> */
    abstract protected function compileDropIndex(Blueprint $blueprint, string $name): array;

    /** @return list<string> */
    abstract protected function compileDropForeign(Blueprint $blueprint, string $name): array;

    /** @return list<string> */
    abstract protected function compileAddForeign(Blueprint $blueprint, ForeignKeyDefinition $fk): array;

    /**
     * @return list<string>
     */
    public function compileCreate(Blueprint $blueprint, bool $ifNotExists = false): array
    {
        $lines   = [];
        $primary = [];

        foreach ($blueprint->getColumns() as $column) {
            $lines[] = $this->column($column);
            if ($column->primary && !$column->autoIncrement) {
                $primary[] = $column->name;
            }
        }

        $indexes = $blueprint->getIndexes();
        foreach ($indexes as $index) {
            if ($index['type'] === 'primary') {
                $primary = array_merge($primary, $index['columns']);
            }
        }
        if ($primary !== []) {
            $lines[] = 'PRIMARY KEY (' . $this->columnize($primary) . ')';
        }

        foreach ($blueprint->getForeignKeys() as $fk) {
            $lines[] = $this->foreignKey($fk);
        }

        $sql = 'CREATE TABLE ' . ($ifNotExists ? 'IF NOT EXISTS ' : '') . $this->table($blueprint->table)
            . " (\n    " . implode(",\n    ", $lines) . "\n)" . $this->tableOptions();

        $statements = [$sql];
        foreach ($indexes as $index) {
            if ($index['type'] !== 'primary') {
                $statements[] = $this->createIndex($blueprint, $index);
            }
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $table      = $this->table($blueprint->table);
        $statements = [];

        // Drops and renames first, then additions.
        foreach ($blueprint->getCommands() as $command) {
            switch ($command[0]) {
                case 'dropForeign':
                    array_push($statements, ...$this->compileDropForeign($blueprint, (string) $command[1]));
                    break;
                case 'dropIndex':
                    array_push($statements, ...$this->compileDropIndex($blueprint, (string) $command[1]));
                    break;
            }
        }
        foreach ($blueprint->getCommands() as $command) {
            switch ($command[0]) {
                case 'dropColumn':
                    $statements[] = "ALTER TABLE {$table} DROP COLUMN " . $this->grammar->wrap((string) $command[1]);
                    break;
                case 'renameColumn':
                    $statements[] = "ALTER TABLE {$table} RENAME COLUMN " . $this->grammar->wrap((string) $command[1])
                        . ' TO ' . $this->grammar->wrap((string) $command[2]);
                    break;
            }
        }

        foreach ($blueprint->getColumns() as $column) {
            if ($column->autoIncrement) {
                throw new \LogicException("Adding the auto-increment column [{$column->name}] to an existing table is not supported.");
            }
            $statements[] = "ALTER TABLE {$table} ADD COLUMN " . $this->column($column);
        }

        foreach ($blueprint->getIndexes() as $index) {
            if ($index['type'] === 'primary') {
                throw new \LogicException('Adding a primary key to an existing table is not supported; define it in Schema::create().');
            }
            $statements[] = $this->createIndex($blueprint, $index);
        }

        foreach ($blueprint->getForeignKeys() as $fk) {
            array_push($statements, ...$this->compileAddForeign($blueprint, $fk));
        }

        return $statements;
    }

    public function compileDrop(string $table, bool $ifExists): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->table($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->table($from) . ' RENAME TO ' . $this->table($to);
    }

    // ── Pieces ──────────────────────────────────────────────────────────────

    protected function column(ColumnDefinition $column): string
    {
        if ($column->autoIncrement) {
            return $this->autoIncrementColumn($column);
        }

        $sql = $this->grammar->wrap($column->name) . ' ' . $this->type($column) . $this->unsignedSuffix($column);
        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';

        if ($column->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($column);
        }

        return $sql . $this->checkConstraint($column);
    }

    protected function unsignedSuffix(ColumnDefinition $column): string
    {
        return '';
    }

    /** CHECK constraint for enum() on drivers without a native ENUM. */
    protected function checkConstraint(ColumnDefinition $column): string
    {
        if ($column->type !== 'enum') {
            return '';
        }

        $values = array_map(fn (string $v) => $this->quote($v), $column->options['values']);
        return ' CHECK (' . $this->grammar->wrap($column->name) . ' IN (' . implode(', ', $values) . '))';
    }

    protected function defaultValue(ColumnDefinition $column): string
    {
        $value = $column->default;

        return match (true) {
            $value instanceof Expression => $value->sql,
            $value === null              => 'NULL',
            is_bool($value)              => $this->booleanLiteral($value),
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \BackedEnum => $this->quote((string) $value->value),
            is_string($value), $value instanceof \Stringable => $this->quote((string) $value),
            default                      => throw new \InvalidArgumentException("Unsupported default for column [{$column->name}]."),
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function foreignKey(ForeignKeyDefinition $fk): string
    {
        return 'CONSTRAINT ' . $this->grammar->wrap((string) $fk->name)
            . ' FOREIGN KEY (' . $this->columnize($fk->columns) . ')'
            . ' REFERENCES ' . $this->table((string) $fk->on) . ' (' . $this->grammar->wrap($fk->references) . ')'
            . " ON DELETE {$fk->onDelete} ON UPDATE {$fk->onUpdate}";
    }

    /**
     * @param array{type: string, columns: list<string>, name: string} $index
     */
    protected function createIndex(Blueprint $blueprint, array $index): string
    {
        return 'CREATE ' . ($index['type'] === 'unique' ? 'UNIQUE ' : '') . 'INDEX ' . $this->grammar->wrap($index['name'])
            . ' ON ' . $this->table($blueprint->table) . ' (' . $this->columnize($index['columns']) . ')';
    }

    protected function tableOptions(): string
    {
        return '';
    }

    protected function table(string $table): string
    {
        return $this->grammar->wrapTable($table);
    }

    /**
     * @param list<string> $columns
     */
    protected function columnize(array $columns): string
    {
        return implode(', ', array_map(fn (string $c) => $this->grammar->wrap($c), $columns));
    }

    protected function quote(string $value): string
    {
        return (string) $this->pdo->quote($value);
    }
}
