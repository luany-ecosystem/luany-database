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
    /** Unquoted table name as given to table() (without alias). */
    private ?string $tableName = null;
    /** @var array<int, string> Compiled SELECT fragments (already quoted) */
    private array $columns   = ['*'];
    /** @var array<int, array{0: string, 1: string}> [boolean, compiled clause] */
    private array $wheres   = [];
    /** @var array<int|string, mixed> */
    private array $bindings = [];
    /** @var array<int, mixed> Bindings for selectRaw() expressions (precede WHERE bindings) */
    private array $selectBindings = [];
    private ?string $orderBy = null;
    /** @var array<int, string> Compiled JOIN clauses */
    private array $joins = [];
    /** @var array<int, string> Compiled GROUP BY columns */
    private array $groups = [];
    /** @var array<int, array{0: string, 1: string}> [boolean, compiled clause] */
    private array $havings = [];
    /** @var array<int, mixed> */
    private array $havingBindings = [];
    private bool $distinct = false;
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
        $builder->tableName = preg_replace('/\s+as\s+.+$/i', '', trim($table));
        return $builder;
    }

    /**
     * Alias the current table: FROM `users` AS `u`. Unqualified columns keep
     * working; qualify with the alias where needed (used for correlated
     * subqueries, e.g. self-referencing whereHas()).
     */
    public function alias(string $alias): static
    {
        $this->requireTable();
        $this->table = $this->grammar->wrapTable("{$this->tableName} AS {$alias}");
        return $this;
    }

    /**
     * Compare two columns: ->whereColumn('posts.user_id', '=', 'users.id').
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            $this->boolean($boolean),
            $this->grammar->wrap($first) . ' ' . $this->grammar->operator($operator) . ' ' . $this->grammar->wrap($second),
        ];
        return $this;
    }

    /**
     * The SELECT this builder would run (with ? placeholders).
     */
    public function toSql(): string
    {
        $this->requireTable();
        return $this->compileSelect();
    }

    /**
     * Bindings for toSql(), in placeholder order.
     *
     * @return array<int|string, mixed>
     */
    public function getBindings(): array
    {
        return $this->allBindings();
    }

    /**
     * Whether select()/selectRaw() were never called (SELECT *).
     */
    public function hasDefaultColumns(): bool
    {
        return $this->columns === ['*'];
    }

    public function getTableName(): ?string
    {
        return $this->tableName;
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
     * SELECT DISTINCT.
     */
    public function distinct(bool $distinct = true): static
    {
        $this->distinct = $distinct;
        return $this;
    }

    /**
     * INNER JOIN $table ON $first $operator $second (column to column).
     *
     *   ->join('posts AS p', 'p.user_id', '=', 'users.id')
     *
     * @throws \InvalidArgumentException On invalid identifiers / operator.
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            throw new \InvalidArgumentException("Invalid join type \"{$type}\". Use INNER, LEFT, RIGHT or CROSS.");
        }

        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            $type,
            $this->grammar->wrapTable($table),
            $this->grammar->wrap($first),
            $this->grammar->operator($operator),
            $this->grammar->wrap($second)
        );
        return $this;
    }

    /**
     * LEFT JOIN $table ON $first $operator $second.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * GROUP BY one or more columns.
     */
    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->grammar->wrap($column);
        }
        return $this;
    }

    /**
     * HAVING $column $operator ? — $column is a column or a selectRaw() alias
     * (aliases in HAVING work on MySQL and SQLite; on PostgreSQL use havingRaw()).
     */
    public function having(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $this->havings[]        = [$this->boolean($boolean), $this->grammar->wrap($column) . ' ' . $this->grammar->operator($operator) . ' ?'];
        $this->havingBindings[] = $value;
        return $this;
    }

    /**
     * Raw HAVING expression, e.g. havingRaw('COUNT(*) > ?', [5]). NOT escaped.
     *
     * @param array<int, mixed> $bindings
     */
    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->havings[]      = [$this->boolean($boolean), '(' . $sql . ')'];
        $this->havingBindings = array_merge($this->havingBindings, array_values($bindings));
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
     * @param array<int|string, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->wheres[]  = [$this->boolean($boolean), '(' . $sql . ')'];
        // array_merge keeps string keys (named placeholders) and renumbers positional ones.
        $this->bindings  = array_merge($this->bindings, $bindings);
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

        if (array_is_list($data) && is_array($data[0] ?? null)) {
            return $this->insertMany($data);
        }

        $cols  = $this->grammar->columnize(array_map('strval', array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $sql   = "INSERT INTO {$this->table} ({$cols}) VALUES ({$marks})";
        $this->connection->execute($sql, array_values($data));
        return true;
    }

    /**
     * Insert or update rows in one statement.
     *
     *   ->upsert(
     *       [['sku' => 'A1', 'price' => 10], ['sku' => 'B2', 'price' => 20]],
     *       uniqueBy: ['sku'],            // columns of a PRIMARY KEY / UNIQUE index
     *       update:   ['price'],          // columns to overwrite on conflict (default: all but uniqueBy)
     *   );
     *
     * MySQL: ON DUPLICATE KEY UPDATE; PostgreSQL / SQLite: ON CONFLICT … DO UPDATE.
     * Returns the driver's affected-row count.
     *
     * @param array<int, array<string, mixed>>|array<string, mixed> $rows
     * @param array<int, string>                                    $uniqueBy
     * @param array<int, string>|null                               $update
     */
    public function upsert(array $rows, array $uniqueBy, ?array $update = null): int
    {
        $this->requireTable();

        if ($rows === []) {
            return 0;
        }
        if (!array_is_list($rows)) {
            $rows = [$rows];
        }
        if ($uniqueBy === []) {
            throw new \InvalidArgumentException('upsert() needs the unique column(s) in $uniqueBy.');
        }

        [$columns, $sql, $bindings] = $this->compileInsertRows($rows);
        $update ??= array_values(array_diff($columns, $uniqueBy));

        if ($update === []) {
            $sql .= $this->grammar->getDriver() === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE ' . $this->grammar->wrap($uniqueBy[0]) . ' = ' . $this->grammar->wrap($uniqueBy[0])
                : ' ON CONFLICT (' . $this->grammar->columnize($uniqueBy) . ') DO NOTHING';
        } elseif ($this->grammar->getDriver() === 'mysql') {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
                fn (string $c) => $this->grammar->wrap($c) . ' = VALUES(' . $this->grammar->wrap($c) . ')',
                $update
            ));
        } else {
            $sql .= ' ON CONFLICT (' . $this->grammar->columnize($uniqueBy) . ') DO UPDATE SET ' . implode(', ', array_map(
                fn (string $c) => $this->grammar->wrap($c) . ' = excluded.' . $this->grammar->wrap($c),
                $update
            ));
        }

        return $this->connection->execute($sql, $bindings)->rowCount();
    }

    /**
     * Process matching rows in chunks of $size (LIMIT/OFFSET pages, in the
     * builder's order). Return false from the callback to stop.
     * Prefer chunkById() when the callback modifies the rows it reads.
     *
     * @param callable(array<int, array<string, mixed>>, int): (bool|null|void) $callback
     */
    public function chunk(int $size, callable $callback): bool
    {
        $this->requireTable();
        $this->assertPositive($size);

        for ($page = 1; ; $page++) {
            $rows = $this->query($this->compileSelect($size, ($page - 1) * $size), $this->selectQueryBindings())->fetchAll();

            if ($rows === []) {
                return true;
            }
            if ($callback($rows, $page) === false) {
                return false;
            }
            if (count($rows) < $size) {
                return true;
            }
        }
    }

    /**
     * Chunk by an increasing key (WHERE id > last ORDER BY id LIMIT n):
     * stable even when the callback updates or deletes rows.
     *
     * @param callable(array<int, array<string, mixed>>, int): (bool|null|void) $callback
     */
    public function chunkById(int $size, callable $callback, string $column = 'id'): bool
    {
        $this->requireTable();
        $this->assertPositive($size);

        $last = null;
        for ($page = 1; ; $page++) {
            $query = clone $this;
            $query->orderBy = null;
            if ($last !== null) {
                $query->where($column, '>', $last);
            }
            $query->orderBy($column);

            $rows = $query->query($query->compileSelect($size, 0), $query->selectQueryBindings())->fetchAll();

            if ($rows === []) {
                return true;
            }
            if ($callback($rows, $page) === false) {
                return false;
            }

            $key  = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
            $last = $rows[count($rows) - 1][$key] ?? null;

            if ($last === null || count($rows) < $size) {
                return true;
            }
        }
    }

    /**
     * Iterate matching rows one at a time without building the whole array.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(): \Generator
    {
        $this->requireTable();
        $stmt = $this->connection->execute($this->compileSelect(), $this->selectQueryBindings());

        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertMany(array $rows): bool
    {
        [, $sql, $bindings] = $this->compileInsertRows($rows);
        $this->connection->execute($sql, $bindings);
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, string>, 1: string, 2: array<int, mixed>}
     */
    private function compileInsertRows(array $rows): array
    {
        $columns = array_map('strval', array_keys($rows[0]));
        sort($columns);

        $bindings = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Batch insert expects a list of rows (arrays).');
            }
            $keys = array_map('strval', array_keys($row));
            sort($keys);
            if ($keys !== $columns) {
                throw new \InvalidArgumentException("Row {$i} has different columns than the first row; batch rows must share the same columns.");
            }
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        $marks = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql   = "INSERT INTO {$this->table} (" . $this->grammar->columnize($columns) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $marks));

        return [$columns, $sql, $bindings];
    }

    private function assertPositive(int $size): void
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least 1.');
        }
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
        $this->assertNoJoins('update');
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
        $this->assertNoJoins('delete');
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
        return $this->countRows();
    }

    /**
     * SUM(column) over the matching rows (null when there are none).
     */
    public function sum(string $column): int|float|null
    {
        return $this->numericAggregate('SUM', $column);
    }

    /**
     * AVG(column) over the matching rows (null when there are none).
     */
    public function avg(string $column): ?float
    {
        $value = $this->numericAggregate('AVG', $column);
        return $value === null ? null : (float) $value;
    }

    /**
     * MIN(column) over the matching rows.
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * MAX(column) over the matching rows.
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
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
        $total = $this->countRows();

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
        return $this->compileSelect($limit, $offset);
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

    /**
     * @param int|null $limit  Overrides limit() when given
     * @param int|null $offset Overrides offset() when given
     */
    private function compileSelect(?int $limit = null, ?int $offset = null, bool $withOrder = true): string
    {
        $cols = implode(', ', $this->columns);
        $sql  = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $cols;
        $sql .= $this->compileFrom();
        $sql .= $this->compileWheres();
        $sql .= $this->compileGroups();

        if ($withOrder && $this->orderBy !== null) {
            $sql .= " ORDER BY {$this->orderBy}";
        }

        $limit  ??= $this->limitVal;
        $offset ??= $this->offsetVal;

        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }
        if ($offset !== null) {
            $sql .= " OFFSET {$offset}";
        }

        return $sql;
    }

    private function compileFrom(): string
    {
        return " FROM {$this->table}" . ($this->joins === [] ? '' : ' ' . implode(' ', $this->joins));
    }

    private function compileGroups(): string
    {
        $sql = '';

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->havings !== []) {
            $clauses = [];
            foreach ($this->havings as $i => [$boolean, $clause]) {
                $clauses[] = $i === 0 ? $clause : "{$boolean} {$clause}";
            }
            $sql .= ' HAVING ' . implode(' ', $clauses);
        }

        return $sql;
    }

    /**
     * Number of result rows, honouring joins, DISTINCT and GROUP BY.
     */
    private function countRows(): int
    {
        if ($this->groups !== [] || $this->distinct) {
            $inner = $this->compileSelect(null, null, false);
            $sql   = "SELECT COUNT(*) FROM ({$inner}) AS luany_count";
            $value = $this->query($sql, $this->allBindings())->fetchColumn();
        } else {
            $sql   = 'SELECT COUNT(*)' . $this->compileFrom() . $this->compileWheres();
            $value = $this->query($sql, $this->bindings)->fetchColumn();
        }

        return (int) ($value[0] ?? 0);
    }

    private function aggregate(string $function, string $column): mixed
    {
        $this->requireTable();

        if ($this->groups !== []) {
            throw new \LogicException(
                "{$function}() cannot be combined with groupBy(); use selectRaw('{$function}(...) AS x')->groupBy(...)->get()."
            );
        }

        $sql = "SELECT {$function}(" . $this->grammar->wrap($column) . ') AS aggregate'
            . $this->compileFrom() . $this->compileWheres();

        $value = $this->query($sql, $this->bindings)->fetchColumn();

        return $value[0] ?? null;
    }

    private function numericAggregate(string $function, string $column): int|float|null
    {
        $value = $this->aggregate($function, $column);

        if ($value === null) {
            return null;
        }

        return is_numeric($value) && (string) (int) $value === (string) $value ? (int) $value : (float) $value;
    }

    /** @return array<int|string, mixed> */
    private function allBindings(): array
    {
        return array_merge($this->selectBindings, $this->bindings, $this->havingBindings);
    }

    private function assertNoJoins(string $operation): void
    {
        if ($this->joins !== []) {
            throw new \LogicException("{$operation}() with joins is not supported; filter with whereIn() on the target table instead.");
        }
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
        return $this->allBindings();
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