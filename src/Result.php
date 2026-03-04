<?php

namespace Luany\Database;

/**
 * Result
 *
 * Wraps a PDOStatement after execution.
 * Provides clean fetch methods without exposing PDO internals.
 */
class Result
{
    public function __construct(private \PDOStatement $stmt) {}

    /**
     * Fetch the first row as an associative array, or null.
     */
    public function fetchOne(): ?array
    {
        $row = $this->stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch all rows as associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array
    {
        return $this->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch all rows hydrated as instances of the given class.
     *
     * @template T
     * @param class-string<T> $class
     * @return T[]
     */
    public function fetchAllAs(string $class): array
    {
        return $this->stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, $class);
    }

    /**
     * Fetch a single column from all rows.
     *
     * @return list<mixed>
     */
    public function fetchColumn(int $column = 0): array
    {
        return $this->stmt->fetchAll(\PDO::FETCH_COLUMN, $column);
    }

    /**
     * Number of rows affected by the last statement.
     */
    public function rowCount(): int
    {
        return $this->stmt->rowCount();
    }
}