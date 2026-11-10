<?php

namespace Luany\Database;

use Luany\Database\PaginationResult;

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
    private Grammar $grammar;

    private ?string $table   = null;
    /** @var array<int, string> Compiled SELECT fragments (already quoted) */
    private array $columns   = ['*'];
    /** @var array<int, array{0: string, 1: string}> [boolean, compiled clause] */
    private array $wheres   = [];
    /** @var array<int|string, mixed> */
    private array $bindings = [];
    /** @var array<int, mixed> Bindings for selectRaw() expressions (precede WHERE bindings) */
    private array $selectBindings = [];
    private ?string $orderBy = null;
    private ?int $limitVal   = null;
    private ?int $offsetVal  = null;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->grammar    = $connection->getGrammar();
    }

    // ── Fluent API ──────────────────────────────────────────────────────────────

    /**
     * Set the target table and return a fresh builder instance.
     * Each call to table() returns a clean builder to prevent state leakage.
     *
     * @throws \InvalidArgumentException If the table name is not a valid identifier.
     */
    public function table(string $table): static
    {
        $builder = new static($this->connection);
        $builder->table = $this->grammar->wrapTable($table);
        return $builder;
    }

    /**
     * Set columns for SELECT. Accepts "col", "table.col", "table.*" and "col AS alias".
     *
     * @throws \InvalidArgumentException If a column is not a valid identifier.
     */
    public function select(string ...$columns): static
    {
        $this->columns = $columns
            ? array_map(fn (string $c) => $this->grammar->wrap($c), $columns)
            : ['*'];
        return $this;
    }

    /**
     * Add a raw SELECT expression. The expression is NOT escaped — never pass user input.
     *
     * @param array<int, mixed> $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $this->columns[]      = $expression;
        $this->selectBindings = array_merge($this->selectBindings, array_values($bindings));
        return $this;
    }

    /**
     * Add a WHERE clause (AND).
     *
     * Comparing against null with "=" / "!=" / "<>" compiles to IS [NOT] NULL.
     *
     * @throws \InvalidArgumentException On an invalid column or operator.
     */
    public function where(string $column, string $operator, mixed $value): static
    {
        return $this->addWhere('AND', $column, $operator, $value);
    }

    /**
     * Add an OR WHERE clause.
     *
     * @throws \InvalidArgumentException On an invalid column or operator.
     */
    public function orWhere(string $column, string $operator, mixed $value): static
    {
        return $this->addWhere('OR', $column, $operator, $value);
    }

    /**
     * Add a parenthesised group of conditions:
     *   ->where('active', '=', 1)->whereNested(fn ($q) => $q->where('a', '=', 1)->orWhere('b', '=', 2))
     *   → WHERE `active` = ? AND (`a` = ? OR `b` = ?)
     *
     * @param \Closure(QueryBuilder): mixed $callback
     */
    public function whereNested(\Closure $callback, string $boolean = 'AND'): static
    {
        $nested = new static($this->connection);
        $callback($nested);

        if ($nested->wheres !== []) {
            $this->wheres[]  = [$this->boolean($boolean), '(' . $nested->compileConditions() . ')'];
            $this->bindings  = array_merge($this->bindings, $nested->bindings);
        }
        return $this;
    }

    /**
     * OR variant of whereNested().
     *
     * @param \Closure(QueryBuilder): mixed $callback
     */
    public function orWhereNested(\Closure $callback): static
    {
        return $this->whereNested($callback, 'OR');
    }

    /**
     * Add a raw WHERE clause. The SQL is NOT escaped — always use ? placeholders for values.
     *
     * @param array<int, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->wheres[]  = [$this->boolean($boolean), '(' . $sql . ')'];
        $this->bindings  = array_merge($this->bindings, array_values($bindings));
        return $this;
    }

    /**
     * OR variant of whereRaw().
     *
     * @param array<int, mixed> $bindings
     */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    /**
     * Add a WHERE IN clause.
     *
     * @param array<int, mixed> $values
     */
    public function whereIn(string $column, array $values): static
    {
        return $this->addWhereIn('AND', $column, $values, false);
    }

    /**
     * Add a WHERE NOT IN clause.
     *
     * @param array<int, mixed> $values
     */
    public function whereNotIn(string $column, array $values): static
    {
        return $this->addWhereIn('AND', $column, $values, true);
    }

    /**
     * Add a WHERE NULL clause.
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = ['AND', $this->grammar->wrap($column) . ' IS NULL'];
        return $this;
    }

    /**
     * Add a WHERE NOT NULL clause.
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['AND', $this->grammar->wrap($column) . ' IS NOT NULL'];
        return $this;
    }

    /**
     * Set ORDER BY clause. Direction is normalised to ASC/DESC.
     *
     * @throws \InvalidArgumentException If the column is not a valid identifier.
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
        return $this->appendOrder($this->grammar->wrap($column) . " {$direction}");
    }

    /**
     * ORDER BY ... DESC shortcut.
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Add a raw ORDER BY expression. NOT escaped — never pass user input.
     */
    public function orderByRaw(string $expression): static
    {
        return $this->appendOrder($expression);
    }

    /**
     * Set LIMIT.
     */
    public function limit(int $limit): static
    {
        $this->limitVal = max(0, $limit);
        return $this;
    }

    /**
     * Set OFFSET.
     */
    public function offset(int $offset): static
    {
        $this->offsetVal = max(0, $offset);
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
        return $this->query($sql, $this->selectQueryBindings())->fetchAll();
    }

    /**
     * Execute SELECT and return the first matching row, or null.
     */
    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $this->requireTable();
        $this->limitVal = 1;
        $sql = $this->compileSelect();
        return $this->query($sql, $this->selectQueryBindings())->fetchOne();
    }

    /**
     * Insert a row. Returns true on success.
     */
    /** @param array<string, mixed> $data */
    public function insert(array $data): bool
    {
        $this->requireTable();
        if ($data === []) {
            $sql = $this->grammar->getDriver() === 'mysql'
                ? "INSERT INTO {$this->table} () VALUES ()"
                : "INSERT INTO {$this->table} DEFAULT VALUES";
            $this->connection->execute($sql);
            return true;
        }

        $cols  = $this->grammar->columnize(array_map('strval', array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $sql   = "INSERT INTO {$this->table} ({$cols}) VALUES ({$marks})";
        $this->connection->execute($sql, array_values($data));
        return true;
    }

    /**
     * Update matching rows. Returns number of affected rows.
     */
    /** @param array<string, mixed> $data */
    public function update(array $data): int
    {
        $this->requireTable();
        if ($data === []) {
            return 0;
        }
        $sets     = implode(', ', array_map(
            fn (int|string $col) => $this->grammar->wrap((string) $col) . ' = ?',
            array_keys($data)
        ));
        $values   = array_values($data);
        $sql      = "UPDATE {$this->table} SET {$sets}";
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
        $sql  = "DELETE FROM {$this->table}";
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
        $sql  = "SELECT COUNT(*) FROM {$this->table}";
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

    /**
     * Paginate the query results.
     *
     * Executes TWO queries:
     *   1. COUNT(*) with the current WHERE clause — to know the total.
     *   2. SELECT with LIMIT + OFFSET for the requested page.
     *
     * Both queries share the same WHERE bindings, ORDER BY, and table.
     * LIMIT/OFFSET values are injected as integers directly (not as bindings)
     * because PDO does not support binding integer placeholders in LIMIT/OFFSET
     * on all drivers.
     *
     * @param  int  $perPage  Rows per page (must be >= 1)
     * @param  int  $page     Page number, 1-based (values < 1 are clamped to 1)
     * @return PaginationResult
     *
     * @throws \InvalidArgumentException  If $perPage < 1
     * @throws \RuntimeException          If no table was set
     */
    public function paginate(int $perPage = 15, int $page = 1): PaginationResult
    {
        $this->requireTable();

        if ($perPage < 1) {
            throw new \InvalidArgumentException('perPage must be at least 1.');
        }

        // Clamp page to a minimum of 1
        $page = max(1, $page);

        // ── 1. Count total matching rows ───────────────────────────────────
        $countSql    = "SELECT COUNT(*) FROM {$this->table}";
        $countSql   .= $this->compileWheres();
        $countResult = $this->query($countSql, $this->bindings)->fetchColumn();
        $total       = (int) ($countResult[0] ?? 0);

        // ── 2. Fetch the page data ─────────────────────────────────────────
        $offset = ($page - 1) * $perPage;

        // Always override LIMIT/OFFSET with the calculated values (safe integer interpolation).
        $dataSql = $this->compileSelectForPagination($perPage, $offset);

        $rows = $this->query($dataSql, $this->selectQueryBindings())->fetchAll();

        return new PaginationResult(
            data:        $rows,
            total:       $total,
            perPage:     $perPage,
            currentPage: $page,
        );
    }

    /**
     * Compile a SELECT for pagination, always overriding LIMIT and OFFSET.
     * Integers are interpolated directly — safe because they are cast to int.
     */
    private function compileSelectForPagination(int $limit, int $offset): string
    {
        $cols = implode(', ', $this->columns);
        $sql  = "SELECT {$cols} FROM {$this->table}";
        $sql .= $this->compileWheres();

        if ($this->orderBy !== null) {
            $sql .= " ORDER BY {$this->orderBy}";
        }

        $sql .= " LIMIT {$limit} OFFSET {$offset}";

        return $sql;
    }

    // ── Raw methods (backward-compatible) ───────────────────────────────────────

    /**
     * Execute a raw SELECT and return a Result.
     */
    /** @param array<int|string, mixed> $bindings */
    public function query(string $sql, array $bindings = []): Result
    {
        $stmt = $this->connection->execute($sql, $bindings);
        return new Result($stmt);
    }

    /**
     * Alias for query() — execute raw SQL and return a Result.
     */
    /** @param array<int|string, mixed> $bindings */
    public function raw(string $sql, array $bindings = []): Result
    {
        return $this->query($sql, $bindings);
    }

    /**
     * Execute a raw INSERT, UPDATE or DELETE.
     * Returns the number of affected rows.
     */
    /** @param array<int|string, mixed> $bindings */
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
        $cols = implode(', ', $this->columns);
        $sql  = "SELECT {$cols} FROM {$this->table}";
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

        return ' WHERE ' . $this->compileConditions();
    }

    private function compileConditions(): string
    {
        $clauses = [];
        foreach ($this->wheres as $i => [$boolean, $clause]) {
            $clauses[] = $i === 0 ? $clause : "{$boolean} {$clause}";
        }

        return implode(' ', $clauses);
    }

    private function addWhere(string $boolean, string $column, string $operator, mixed $value): static
    {
        $wrapped  = $this->grammar->wrap($column);
        $operator = $this->grammar->operator($operator);

        if ($value === null && in_array($operator, ['=', '!=', '<>'], true)) {
            $this->wheres[] = [$boolean, $wrapped . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL')];
            return $this;
        }

        $this->wheres[]   = [$boolean, "{$wrapped} {$operator} ?"];
        $this->bindings[] = $value;
        return $this;
    }

    /** @param array<int, mixed> $values */
    private function addWhereIn(string $boolean, string $column, array $values, bool $not): static
    {
        $wrapped = $this->grammar->wrap($column);

        if (empty($values)) {
            // IN () never matches; NOT IN () always matches.
            $this->wheres[] = [$boolean, $not ? '1 = 1' : '0 = 1'];
            return $this;
        }

        $placeholders   = implode(', ', array_fill(0, count($values), '?'));
        $keyword        = $not ? 'NOT IN' : 'IN';
        $this->wheres[] = [$boolean, "{$wrapped} {$keyword} ({$placeholders})"];
        $this->bindings = array_merge($this->bindings, array_values($values));
        return $this;
    }

    /** @return array<int|string, mixed> */
    private function selectQueryBindings(): array
    {
        return array_merge($this->selectBindings, $this->bindings);
    }

    private function appendOrder(string $fragment): static
    {
        $this->orderBy = $this->orderBy === null ? $fragment : "{$this->orderBy}, {$fragment}";
        return $this;
    }

    private function boolean(string $boolean): string
    {
        $boolean = strtoupper(trim($boolean));
        if ($boolean !== 'AND' && $boolean !== 'OR') {
            throw new \InvalidArgumentException("Invalid boolean \"{$boolean}\". Use AND or OR.");
        }
        return $boolean;
    }

    private function requireTable(): void
    {
        if ($this->table === null) {
            throw new \RuntimeException('No table specified. Call table() before executing a query.');
        }
    }
}