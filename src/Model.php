<?php

namespace Luany\Database;

/**
 * Model
 *
 * Base class for all application models.
 * Implements a lightweight ActiveRecord pattern over PDO.
 *
 * The model NEVER resolves a connection itself.
 * It receives a Connection via Model::setConnection() or
 * via the application's DatabaseServiceProvider.
 *
 * Usage:
 *   class User extends Model
 *   {
 *       protected string $table     = 'users';
 *       protected array  $fillable  = ['name', 'email', 'password'];
 *       protected array  $hidden    = ['password'];
 *   }
 *
 *   User::setConnection($connection);
 *
 *   $user  = User::find(1);
 *   $users = User::all();
 *   $found = User::where('active = ?', [1]);
 *   $user  = User::create(['name' => 'António', 'email' => 'a@b.com']);
 *   $user->name = 'Ngola';
 *   $user->save();
 *   $user->delete();
 */
abstract class Model
{
    // ── Schema configuration ─────────────────────────────────────────────────

    protected string $table      = '';
    protected string $primaryKey = 'id';

    /** Columns allowed for mass assignment */
    protected array $fillable = [];

    /** Columns excluded from toArray() and JSON output */
    protected array $hidden = [];

    // ── Internal state ───────────────────────────────────────────────────────

    private array $attributes = [];
    private bool  $exists     = false;

    // ── Shared connection ────────────────────────────────────────────────────

    private static Connection|\Closure|null $connection = null;

    /**
     * Set the shared database connection for all models.
     * Called by the application's DatabaseServiceProvider during boot().
     */
    public static function setConnection(Connection|\Closure $connection): void
    {
        static::$connection = $connection;
    }

    /**
     * Get the shared connection.
     *
     * @throws \LogicException if connection was never set
     */
    public static function getConnection(): Connection
    {
        if (static::$connection instanceof \Closure) {
            static::$connection = (static::$connection)();
        }

        if (static::$connection === null) {
            throw new \LogicException(
                'No database connection set. Call Model::setConnection() in your DatabaseServiceProvider.'
            );
        }

        return static::$connection;
    }

    // ── Static query methods ─────────────────────────────────────────────────

    /**
     * Get a fresh QueryBuilder instance scoped to this model's table.
     */
    protected static function newQuery(): QueryBuilder
    {
        $instance = new static();
        return (new QueryBuilder(static::getConnection()))->table($instance->table);
    }

    /**
     * Find a record by primary key. Returns null if not found.
     */
    public static function find(int|string $id): ?static
    {
        $instance = new static();
        $row = static::newQuery()
            ->where($instance->primaryKey, '=', $id)
            ->first();

        return $row ? $instance->hydrate($row) : null;
    }

    /**
     * Return all records, optionally ordered.
     *
     * Only allows safe column identifiers in the ORDER BY clause.
     * Each term must match: column_name (ASC|DESC)?
     * separated by commas. Anything else throws \InvalidArgumentException.
     *
     * @return static[]
     *
     * @throws \InvalidArgumentException If $orderBy contains disallowed characters.
     */
    public static function all(string $orderBy = ''): array
    {
        $query = static::newQuery();

        if ($orderBy !== '') {
            static::validateOrderBy($orderBy);
            // Parse validated ORDER BY into fluent calls
            foreach (explode(',', $orderBy) as $term) {
                $parts  = preg_split('/\s+/', trim($term));
                $column = $parts[0];
                $dir    = strtoupper($parts[1] ?? 'ASC');
                $query  = $query->orderBy($column, $dir);
            }
        }

        return array_map(
            fn(array $row) => (new static())->hydrate($row),
            $query->get()
        );
    }

    /**
     * Return records matching a raw WHERE clause.
     *
     * @param  string  $conditions  e.g. 'email = ? AND active = ?'
     * @param  array   $bindings    e.g. ['a@b.com', 1]
     * @return static[]
     */
    public static function where(string $conditions, array $bindings = []): array
    {
        $instance = new static();
        $sql      = "SELECT * FROM `{$instance->table}` WHERE {$conditions}";

        return array_map(
            fn(array $row) => (new static())->hydrate($row),
            (new QueryBuilder(static::getConnection()))->query($sql, $bindings)->fetchAll()
        );
    }

    /**
     * Return the first record matching a WHERE clause, or null.
     */
    public static function firstWhere(string $conditions, array $bindings = []): ?static
    {
        $results = static::where($conditions, $bindings);
        return $results[0] ?? null;
    }

    /**
     * Count records, optionally with a WHERE clause.
     */
    public static function count(string $conditions = '', array $bindings = []): int
    {
        if ($conditions === '') {
            return static::newQuery()->count();
        }

        $instance = new static();
        $sql      = "SELECT COUNT(*) FROM `{$instance->table}` WHERE {$conditions}";
        $result   = (new QueryBuilder(static::getConnection()))
                        ->query($sql, $bindings)
                        ->fetchColumn();

        return (int) ($result[0] ?? 0);
    }

    /**
     * Create a new record and return the hydrated model instance.
     */
    public static function create(array $data): static
    {
        $instance = new static();
        $filtered = $instance->filterFillable($data);

        if (empty($filtered)) {
            throw new \InvalidArgumentException(
                'No fillable attributes provided for ' . static::class . '. '
                . 'Set the $fillable property or pass valid column names.'
            );
        }

        static::newQuery()->insert($filtered);

        $id = (new QueryBuilder(static::getConnection()))->lastInsertId();
        return static::find((int) $id);
    }

    // ── Instance methods ─────────────────────────────────────────────────────

    /**
     * Persist the model — INSERT if new, UPDATE if existing.
     */
    public function save(): bool
    {
        return $this->exists ? $this->performUpdate() : $this->performInsert();
    }

    /**
     * Delete this record from the database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        static::newQuery()
            ->where($this->primaryKey, '=', $this->getAttribute($this->primaryKey))
            ->delete();

        $this->exists = false;
        return true;
    }

    /**
     * Return attributes as array, excluding $hidden columns.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            $this->attributes,
            fn(string $key) => !in_array($key, $this->hidden, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Return JSON representation (respects $hidden).
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $flags);
    }

    /**
     * Whether this instance was loaded from the database.
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    // ── Magic property access ─────────────────────────────────────────────────

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    // ── Order-by validation ─────────────────────────────────────────────────

    /**
     * Validate the ORDER BY clause against a strict whitelist pattern.
     *
     * Allowed format: one or more comma-separated terms where each term is
     *   column_name (ASC|DESC)?
     * Column names must consist of [a-zA-Z0-9_] only.
     *
     * @throws \InvalidArgumentException
     */
    private static function validateOrderBy(string $orderBy): void
    {
        // Pattern: word(ASC|DESC)? (, word(ASC|DESC)?)*
        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*(\s+(ASC|DESC))?(\s*,\s*[a-zA-Z_][a-zA-Z0-9_]*(\s+(ASC|DESC))?)*$/i';

        if (!preg_match($pattern, trim($orderBy))) {
            throw new \InvalidArgumentException(
                "Invalid ORDER BY clause: \"{$orderBy}\". "
                . 'Only column names (alphanumeric/underscore) with optional ASC/DESC are allowed.'
            );
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function performInsert(): bool
    {
        $filtered = $this->filterFillable($this->attributes);

        static::newQuery()->insert($filtered);

        $this->attributes[$this->primaryKey] = (int) (new QueryBuilder(static::getConnection()))->lastInsertId();
        $this->exists = true;
        return true;
    }

    private function performUpdate(): bool
    {
        $filtered = $this->filterFillable($this->attributes);

        static::newQuery()
            ->where($this->primaryKey, '=', $this->getAttribute($this->primaryKey))
            ->update($filtered);

        return true;
    }

    private function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }
        return array_intersect_key($data, array_flip($this->fillable));
    }

    private function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    private function hydrate(array $row): static
    {
        $this->attributes = $row;
        $this->exists     = true;
        return $this;
    }
}