<?php

namespace Luany\Database;

/**
 * QueryBuilder
 *
 * Fluent query builder with prepared-statement safety.
 *
 * Fluent usage:
 *   $qb = new QueryBuilder($connection);
 *   $users = $qb->table('users')->select('id', 'name')->where('active', '=', 1)->get();
 *   $user  = $qb->table('users')->where('id', '=', 42)->first();
 *   $qb->table('users')->insert(['name' => 'João', 'email' => 'j@example.com']);
 *   $qb->table('users')->where('id', '=', 42)->update(['name' => 'João Silva']);
 *   $qb->table('users')->where('id', '=', 42)->delete();
 *
 * Raw usage (backward-compatible):
 *   $qb->query('SELECT * FROM users WHERE active = ?', [1])->fetchAll();
 *   $qb->statement('DELETE FROM users WHERE id = ?', [42]);
 */
class QueryBuilder
{
    private Connection $connection;

    private ?string $table   = null;
    private array $columns   = ['*'];
    private array $wheres    = [];
    private array $bindings  = [];
    private ?string $orderBy = null;
    private ?int $limitVal   = null;
    private ?int $offsetVal  = null;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    // ── Fluent API ──────────────────────────────────────────────────────────────

    /**
     * Set the target table and return a fresh builder instance.
     * Each call to table() returns a clean builder to prevent state leakage.
     */
    public function table(string $table): static
    {
        $builder = new static($this->connection);
        $builder->table = $table;
        return $builder;
    }

    /**
     * Set columns for SELECT.
     */
    public function select(string ...$columns): static
    {
        $this->columns = $columns ?: ['*'];
        return $this;
    }

    /**
     * Add a WHERE clause (AND).
     */
    public function where(string $column, string $operator, mixed $value): static
    {
        $this->wheres[]   = ['AND', "`{$column}` {$operator} ?"];
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Add an OR WHERE clause.
     */
    public function orWhere(string $column, string $operator, mixed $value): static
    {
        $this->wheres[]   = ['OR', "`{$column}` {$operator} ?"];
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Add a WHERE IN clause.
     */
    public function whereIn(string $column, array $values): static
    {
        if (empty($values)) {
            // Always false — no rows can match an empty IN set
            $this->wheres[] = ['AND', '0 = 1'];
            return $this;
        }
        $placeholders     = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[]   = ['AND', "`{$column}` IN ({$placeholders})"];
        $this->bindings   = array_merge($this->bindings, array_values($values));
        return $this;
    }

    /**
     * Add a WHERE NULL clause.
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = ['AND', "`{$column}` IS NULL"];
        return $this;
    }

    /**
     * Add a WHERE NOT NULL clause.
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['AND', "`{$column}` IS NOT NULL"];
        return $this;
    }

    /**
     * Set ORDER BY clause.
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        if ($this->orderBy === null) {
            $this->orderBy = "`{$column}` {$direction}";
        } else {
            $this->orderBy .= ", `{$column}` {$direction}";
        }
        return $this;
    }

    /**
     * Set LIMIT.
     */
    public function limit(int $limit): static
    {
        $this->limitVal = $limit;
        return $this;
    }

    /**
     * Set OFFSET.
     */
    public function offset(int $offset): static
    {
        $this->offsetVal = $offset;
        return $this;
    }

    // ── Fluent terminators ──────────────────────────────────────────────────────

    /**
     * Execute SELECT and return all matching rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        $this->requireTable();
        $sql = $this->compileSelect();
        return $this->query($sql, $this->bindings)->fetchAll();
    }

    /**
     * Execute SELECT and return the first matching row, or null.
     */
    public function first(): ?array
    {
        $this->requireTable();
        $this->limitVal = 1;
        $sql = $this->compileSelect();
        return $this->query($sql, $this->bindings)->fetchOne();
    }

    /**
     * Insert a row. Returns true on success.
     */
    public function insert(array $data): bool
    {
        $this->requireTable();
        $cols  = implode('`, `', array_keys($data));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $sql   = "INSERT INTO `{$this->table}` (`{$cols}`) VALUES ({$marks})";
        $this->connection->execute($sql, array_values($data));
        return true;
    }

    /**
     * Update matching rows. Returns number of affected rows.
     */
    public function update(array $data): int
    {
        $this->requireTable();
        $sets     = implode(', ', array_map(fn(string $col) => "`{$col}` = ?", array_keys($data)));
        $values   = array_values($data);
        $sql      = "UPDATE `{$this->table}` SET {$sets}";
        $sql     .= $this->compileWheres();
        $bindings = array_merge($values, $this->bindings);
        $stmt     = $this->connection->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Delete matching rows. Returns number of affected rows.
     */
    public function delete(): int
    {
        $this->requireTable();
        $sql  = "DELETE FROM `{$this->table}`";
        $sql .= $this->compileWheres();
        $stmt = $this->connection->execute($sql, $this->bindings);
        return $stmt->rowCount();
    }

    /**
     * Count matching rows.
     */
    public function count(): int
    {
        $this->requireTable();
        $sql  = "SELECT COUNT(*) FROM `{$this->table}`";
        $sql .= $this->compileWheres();
        $result = $this->query($sql, $this->bindings)->fetchColumn();
        return (int) ($result[0] ?? 0);
    }

    /**
     * Check if any matching rows exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // ── Raw methods (backward-compatible) ───────────────────────────────────────

    /**
     * Execute a raw SELECT and return a Result.
     */
    public function query(string $sql, array $bindings = []): Result
    {
        $stmt = $this->connection->execute($sql, $bindings);
        return new Result($stmt);
    }

    /**
     * Alias for query() — execute raw SQL and return a Result.
     */
    public function raw(string $sql, array $bindings = []): Result
    {
        return $this->query($sql, $bindings);
    }

    /**
     * Execute a raw INSERT, UPDATE or DELETE.
     * Returns the number of affected rows.
     */
    public function statement(string $sql, array $bindings = []): int
    {
        $stmt = $this->connection->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Get the last inserted row ID.
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Get the underlying Connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    // ── SQL compilation ─────────────────────────────────────────────────────────

    private function compileSelect(): string
    {
        $cols = $this->columns === ['*'] ? '*' : '`' . implode('`, `', $this->columns) . '`';
        $sql  = "SELECT {$cols} FROM `{$this->table}`";
        $sql .= $this->compileWheres();

        if ($this->orderBy !== null) {
            $sql .= " ORDER BY {$this->orderBy}";
        }
        if ($this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }
        if ($this->offsetVal !== null) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return $sql;
    }

    private function compileWheres(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $clauses = [];
        foreach ($this->wheres as $i => [$boolean, $clause]) {
            if ($i === 0) {
                $clauses[] = $clause;
            } else {
                $clauses[] = "{$boolean} {$clause}";
            }
        }

        return ' WHERE ' . implode(' ', $clauses);
    }

    private function requireTable(): void
    {
        if ($this->table === null) {
            throw new \RuntimeException('No table specified. Call table() before executing a query.');
        }
    }
}
