<?php

namespace Luany\Database\Schema;

/**
 * A column being defined in a Blueprint. Modifiers chain:
 *
 *   $table->string('email', 150)->unique();
 *   $table->decimal('price', 10, 2)->default(0);
 *   $table->timestamp('paid_at')->nullable();
 */
class ColumnDefinition
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $unsigned = false;
    public bool $autoIncrement = false;
    public bool $primary = false;
    public bool $unique = false;
    public bool $index = false;
    public bool $useCurrent = false;

    /**
     * @param array<string, mixed> $options Type options (length, precision, scale, values …)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $options = [],
    ) {}

    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;
        return $this;
    }

    /**
     * Default value: scalar, null, or Schema::raw('…') for an SQL expression.
     */
    public function default(mixed $value): static
    {
        $this->hasDefault = true;
        $this->default    = $value;
        return $this;
    }

    public function unsigned(): static
    {
        $this->unsigned = true;
        return $this;
    }

    public function unique(): static
    {
        $this->unique = true;
        return $this;
    }

    public function index(): static
    {
        $this->index = true;
        return $this;
    }

    public function primary(): static
    {
        $this->primary = true;
        return $this;
    }

    /**
     * DEFAULT CURRENT_TIMESTAMP (date/time columns).
     */
    public function useCurrent(): static
    {
        $this->useCurrent = true;
        return $this;
    }
}
