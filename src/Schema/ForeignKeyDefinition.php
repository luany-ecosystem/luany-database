<?php

namespace Luany\Database\Schema;

/**
 * A foreign key constraint:
 *
 *   $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
 *   $table->foreignId('user_id')->constrained();      // users(id)
 */
final class ForeignKeyDefinition
{
    public string $references = 'id';
    public ?string $on = null;
    public string $onDelete = 'RESTRICT';
    public string $onUpdate = 'CASCADE';
    public ?string $name = null;

    private const ACTIONS = ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION', 'SET DEFAULT'];

    /**
     * @param list<string> $columns
     */
    public function __construct(public readonly array $columns) {}

    public function references(string $column): self
    {
        $this->references = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->on = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = self::action($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::action($action);
        return $this;
    }

    public function cascadeOnDelete(): self   { return $this->onDelete('CASCADE'); }
    public function restrictOnDelete(): self  { return $this->onDelete('RESTRICT'); }
    public function nullOnDelete(): self      { return $this->onDelete('SET NULL'); }
    public function cascadeOnUpdate(): self   { return $this->onUpdate('CASCADE'); }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    private static function action(string $action): string
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Invalid foreign key action [{$action}]. Use: " . implode(', ', self::ACTIONS) . '.');
        }
        return $action;
    }
}
