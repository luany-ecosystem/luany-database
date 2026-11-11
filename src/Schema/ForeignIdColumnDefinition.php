<?php

namespace Luany\Database\Schema;

/**
 * Result of $table->foreignId('user_id'): column modifiers plus constrained().
 */
final class ForeignIdColumnDefinition
{
    public function __construct(
        private readonly Blueprint $blueprint,
        public readonly ColumnDefinition $column,
    ) {}

    /**
     * Add a foreign key to $table(id); the table defaults to the plural of
     * the column name without _id (user_id → users, category_id → categories).
     */
    public function constrained(?string $table = null, string $column = 'id'): ForeignKeyDefinition
    {
        $table ??= Blueprint::pluralize((string) preg_replace('/_id$/', '', $this->column->name));

        return $this->blueprint->foreign($this->column->name)->references($column)->on($table);
    }

    public function nullable(bool $value = true): self
    {
        $this->column->nullable($value);
        return $this;
    }

    public function index(): self
    {
        $this->column->index();
        return $this;
    }

    public function unique(): self
    {
        $this->column->unique();
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->column->default($value);
        return $this;
    }
}
