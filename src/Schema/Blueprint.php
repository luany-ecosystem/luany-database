<?php

namespace Luany\Database\Schema;

/**
 * Blueprint — the table definition passed to Schema::create() / table().
 *
 *   Schema::for($pdo)->create('posts', function (Blueprint $table) {
 *       $table->id();
 *       $table->foreignId('user_id')->constrained()->cascadeOnDelete();
 *       $table->string('title');
 *       $table->string('slug')->unique();
 *       $table->text('body')->nullable();
 *       $table->boolean('published')->default(false);
 *       $table->timestamps();
 *       $table->softDeletes();
 *   });
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<array{type: string, columns: list<string>, name: ?string}> */
    private array $indexes = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var list<array{0: string, 1: mixed, 2?: mixed}> Alter commands */
    private array $commands = [];

    public function __construct(public readonly string $table) {}

    // ── Keys ────────────────────────────────────────────────────────────────

    /** Auto-incrementing BIGINT primary key. */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($name);
    }

    public function increments(string $name): ColumnDefinition
    {
        $c = $this->add($name, 'integer')->unsigned();
        $c->autoIncrement = true;
        $c->primary = true;
        return $c;
    }

    public function bigIncrements(string $name): ColumnDefinition
    {
        $c = $this->add($name, 'bigInteger')->unsigned();
        $c->autoIncrement = true;
        $c->primary = true;
        return $c;
    }

    /** UNSIGNED BIGINT column meant for a foreign key: ->constrained(). */
    public function foreignId(string $name): ForeignIdColumnDefinition
    {
        return new ForeignIdColumnDefinition($this, $this->add($name, 'bigInteger')->unsigned());
    }

    // ── Strings ─────────────────────────────────────────────────────────────

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add($name, 'string', ['length' => $length]);
    }

    public function char(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add($name, 'char', ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->add($name, 'text');
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->add($name, 'longText');
    }

    public function uuid(string $name = 'uuid'): ColumnDefinition
    {
        return $this->add($name, 'uuid');
    }

    /**
     * @param list<string> $values
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        if ($values === []) {
            throw new \InvalidArgumentException("enum() column [{$name}] needs at least one value.");
        }
        return $this->add($name, 'enum', ['values' => array_values(array_map('strval', $values))]);
    }

    // ── Numbers ─────────────────────────────────────────────────────────────

    public function integer(string $name): ColumnDefinition       { return $this->add($name, 'integer'); }
    public function bigInteger(string $name): ColumnDefinition    { return $this->add($name, 'bigInteger'); }
    public function smallInteger(string $name): ColumnDefinition  { return $this->add($name, 'smallInteger'); }
    public function tinyInteger(string $name): ColumnDefinition   { return $this->add($name, 'tinyInteger'); }
    public function unsignedInteger(string $name): ColumnDefinition    { return $this->integer($name)->unsigned(); }
    public function unsignedBigInteger(string $name): ColumnDefinition { return $this->bigInteger($name)->unsigned(); }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->add($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function float(string $name): ColumnDefinition  { return $this->add($name, 'float'); }
    public function double(string $name): ColumnDefinition { return $this->add($name, 'double'); }
    public function boolean(string $name): ColumnDefinition { return $this->add($name, 'boolean'); }

    // ── Dates, JSON, binary ─────────────────────────────────────────────────

    public function date(string $name): ColumnDefinition      { return $this->add($name, 'date'); }
    public function dateTime(string $name): ColumnDefinition  { return $this->add($name, 'dateTime'); }
    public function time(string $name): ColumnDefinition      { return $this->add($name, 'time'); }
    public function timestamp(string $name): ColumnDefinition { return $this->add($name, 'timestamp'); }
    public function json(string $name): ColumnDefinition      { return $this->add($name, 'json'); }
    public function binary(string $name): ColumnDefinition    { return $this->add($name, 'binary'); }

    /** Nullable created_at / updated_at (written by models with $timestamps = true). */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    /** Nullable deleted_at for the SoftDeletes trait. */
    public function softDeletes(string $column = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($column)->nullable();
    }

    /** {name}_type + {name}_id columns and an index, for polymorphic relations. */
    public function morphs(string $name, bool $nullable = false): void
    {
        $this->string("{$name}_type")->nullable($nullable);
        $this->unsignedBigInteger("{$name}_id")->nullable($nullable);
        $this->index(["{$name}_type", "{$name}_id"]);
    }

    // ── Indexes & foreign keys ──────────────────────────────────────────────

    /** @param string|list<string> $columns */
    public function primary(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'primary', 'columns' => (array) $columns, 'name' => $name];
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'unique', 'columns' => (array) $columns, 'name' => $name];
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'index', 'columns' => (array) $columns, 'name' => $name];
    }

    /** @param string|list<string> $columns */
    public function foreign(string|array $columns): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition(array_values((array) $columns));
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    // ── Alter commands (Schema::table) ──────────────────────────────────────

    /** @param string|list<string> $columns */
    public function dropColumn(string|array $columns): void
    {
        foreach ((array) $columns as $column) {
            $this->commands[] = ['dropColumn', $column];
        }
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->commands[] = ['renameColumn', $from, $to];
    }

    /** Drop an index by name (or by its columns: the conventional name is used). */
    /** @param string|list<string> $index */
    public function dropIndex(string|array $index): void
    {
        $this->commands[] = ['dropIndex', $this->indexName('index', $index)];
    }

    /** @param string|list<string> $index */
    public function dropUnique(string|array $index): void
    {
        $this->commands[] = ['dropIndex', $this->indexName('unique', $index)];
    }

    /** @param string|list<string> $foreign */
    public function dropForeign(string|array $foreign): void
    {
        $this->commands[] = ['dropForeign', $this->indexName('foreign', $foreign)];
    }

    // ── Accessors for grammars ──────────────────────────────────────────────

    /** @return list<ColumnDefinition> */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Explicit indexes plus those declared with ->unique() / ->index() on columns.
     *
     * @return list<array{type: string, columns: list<string>, name: string}>
     */
    public function getIndexes(): array
    {
        $indexes = $this->indexes;

        foreach ($this->columns as $column) {
            if ($column->unique) {
                $indexes[] = ['type' => 'unique', 'columns' => [$column->name], 'name' => null];
            }
            if ($column->index) {
                $indexes[] = ['type' => 'index', 'columns' => [$column->name], 'name' => null];
            }
        }

        return array_map(fn (array $i) => [
            'type'    => $i['type'],
            'columns' => array_values($i['columns']),
            'name'    => $i['name'] ?? $this->indexName($i['type'], $i['columns']),
        ], $indexes);
    }

    /** @return list<ForeignKeyDefinition> */
    public function getForeignKeys(): array
    {
        foreach ($this->foreignKeys as $fk) {
            if ($fk->on === null) {
                throw new \InvalidArgumentException('Foreign key on [' . implode(', ', $fk->columns) . "] in [{$this->table}] needs ->on('table').");
            }
            $fk->name ??= $this->indexName('foreign', $fk->columns);
        }
        return $this->foreignKeys;
    }

    /** @return list<array{0: string, 1: mixed, 2?: mixed}> */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Conventional index name: {table}_{columns}_{type}.
     *
     * @param string|list<string> $columns
     */
    public function indexName(string $type, string|array $columns): string
    {
        if (is_string($columns) && !str_contains($columns, ',')) {
            // A plain string may already be a full index name.
            if (str_starts_with($columns, $this->table . '_') && preg_match('/_(index|unique|foreign|primary)$/', $columns) === 1) {
                return $columns;
            }
            $columns = [$columns];
        }

        $name = strtolower($this->table . '_' . implode('_', (array) $columns) . '_' . $type);

        return (string) preg_replace('/[^a-z0-9_]/', '_', $name);
    }

    public static function pluralize(string $word): string
    {
        return match (true) {
            (bool) preg_match('/[^aeiou]y$/', $word)     => substr($word, 0, -1) . 'ies',
            (bool) preg_match('/(s|x|z|ch|sh)$/', $word) => $word . 'es',
            default                                      => $word . 's',
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function add(string $name, string $type, array $options = []): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $type, $options);
        $this->columns[] = $column;
        return $column;
    }
}
