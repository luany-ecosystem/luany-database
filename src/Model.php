<?php

namespace Luany\Database;

use Luany\Database\Relations\BelongsTo;
use Luany\Database\Relations\HasMany;
use Luany\Database\Relations\HasOne;
use Luany\Database\Relations\Relation;

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
 * Relationships:
 *   public function posts(): HasMany   { return $this->hasMany(Post::class, 'user_id'); }
 *   public function profile(): HasOne  { return $this->hasOne(Profile::class, 'user_id'); }
 *   public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
 *
 *   // Lazy access (property syntax):
 *   $user->posts;    // array of Post instances (cached after first access)
 *   $user->profile;  // Profile instance or null
 *
 *   // Eager loading (prevents N+1):
 *   User::with('posts', 'profile')->all();
 *   User::with('profile')->find(1);
 */
abstract class Model
{
    // ── Schema configuration ─────────────────────────────────────────────────

    protected string $table      = '';
    protected string $primaryKey = 'id';

    /** Columns allowed for mass assignment */
    /** @var array<int, string> */
    protected array $fillable = [];

    /** Columns excluded from toArray() and JSON output */
    /** @var array<int, string> */
    protected array $hidden = [];

    // ── Internal state ───────────────────────────────────────────────────────
    // NOTE: protected (not private) so the SoftDeletes trait can access them.

    /** Raw column values from the database */
    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** Cached relation results, keyed by relation method name */
    /** @var array<string, mixed> */
    protected array $relations  = [];

    /** Whether this instance exists in the database */
    protected bool  $exists     = false;

    /** Relations to eager-load on the next query */
    /** @var array<string, mixed> */
    protected static array $eagerLoad = [];

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

    // ── Eager loading ──────────────────────────────────────────────────────────

    /**
     * Specify relations to eager-load on the next query.
     * Returns an EagerProxy that wraps all() and find() with eager-load injection.
     *
     * Usage:
     *   User::with('posts')->all();
     *   User::with('posts', 'profile')->all();
     *   User::with('profile')->find(1);
     */
    public static function with(string ...$relations): EagerProxy
    {
        return new EagerProxy(static::class, $relations);
    }

    /**
     * Set the eager-load relations for the next query.
     * Called by EagerProxy before delegating to all() or find().
     * Consumed and reset by eagerLoadRelations() after the query executes.
     *
     * @internal Used by EagerProxy — do not call directly.
     *
     * @param string[] $relations
     */
    public static function setEagerLoad(array $relations): void
    {
        static::$eagerLoad = $relations;
    }

    // ── Static query methods ─────────────────────────────────────────────────

    /**
     * Get a fresh QueryBuilder scoped to this model's table.
     * Overridden by the SoftDeletes trait to add WHERE deleted_at IS NULL.
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

        if ($row === null) {
            return null;
        }

        $model = static::hydrateFromRow($row);
        static::eagerLoadRelations([$model]);

        return $model;
    }

    /**
     * Return all records, optionally ordered.
     *
     * ORDER BY is validated against a strict whitelist (column names + ASC/DESC only)
     * to prevent SQL injection.
     *
     * @return array<int, static>
     * @throws \InvalidArgumentException If $orderBy contains disallowed characters.
     */
    public static function all(string $orderBy = ''): array
    {
        $query = static::newQuery();

        if ($orderBy !== '') {
            static::validateOrderBy($orderBy);
            foreach (explode(',', $orderBy) as $term) {
                $parts  = preg_split('/\s+/', trim($term));
                $column = $parts[0];
                $dir    = strtoupper($parts[1] ?? 'ASC');
                $query  = $query->orderBy($column, $dir);
            }
        }

        $models = array_map(
            fn(array $row) => static::hydrateFromRow($row),
            $query->get()
        );

        static::eagerLoadRelations($models);

        return $models;
    }

    /**
     * Return records matching a raw WHERE clause.
     *
     * @param  string  $conditions  e.g. 'email = ? AND active = ?'
     * @param  array<int|string, mixed>  $bindings    e.g. ['a@b.com', 1]
     * @return array<int, static>
     */
    public static function where(string $conditions, array $bindings = []): array
    {
        $instance = new static();
        $sql      = "SELECT * FROM `{$instance->table}` WHERE {$conditions}";

        return array_map(
            fn(array $row) => static::hydrateFromRow($row),
            (new QueryBuilder(static::getConnection()))->query($sql, $bindings)->fetchAll()
        );
    }

    /**
     * Return the first record matching a WHERE clause, or null.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function firstWhere(string $conditions, array $bindings = []): ?static
    {
        $results = static::where($conditions, $bindings);
        return $results[0] ?? null;
    }

    /**
     * Count records, optionally with a WHERE clause.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function count(string $conditions = '', array $bindings = []): int
    {
        if ($conditions === '') {
            return static::newQuery()->count();
        }

        $instance = new static(); // used to read $table property
        $sql      = "SELECT COUNT(*) FROM `{$instance->table}` WHERE {$conditions}";
        $result   = (new QueryBuilder(static::getConnection()))
                        ->query($sql, $bindings)
                        ->fetchColumn();

        return (int) ($result[0] ?? 0);
    }

    /**
     * Create a new record and return the hydrated model instance.
     */
    /** @param array<string, mixed> $data */
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
     * Hard-delete this record from the database.
     * NOTE: Overridden by the SoftDeletes trait to soft-delete instead.
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
     * Whether this instance was loaded from (or persisted to) the database.
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    // ── Relationships ──────────────────────────────────────────────────────────
    //
    // Relationship methods return Relation descriptor objects.
    // These objects carry the full metadata WITHOUT executing a query.
    //
    // Lazy loading  → __get() → getRelation() → Relation::getResults()  [1 query per call, cached]
    // Eager loading → with()  → batchLoad()                             [1 query for all models]

    /**
     * One-to-one (FK on related table).
     *
     * @param class-string<Model> $related     Related model FQCN
     * @param string|null         $foreignKey  FK column on related table (default: {table_singular}_id)
     * @param string|null         $localKey    Local column (default: primary key)
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $localKey   = $localKey ?? $this->primaryKey;
        $foreignKey = $foreignKey ?? $this->guessForeignKey();

        return new HasOne(
            static::getConnection(),
            $related,
            $foreignKey,
            $localKey,
            $this->getAttribute($localKey),
        );
    }

    /**
     * One-to-many (FK on related table).
     *
     * @param class-string<Model> $related
     * @param string|null         $foreignKey  FK column on related table
     * @param string|null         $localKey    Local column
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $localKey   = $localKey ?? $this->primaryKey;
        $foreignKey = $foreignKey ?? $this->guessForeignKey();

        return new HasMany(
            static::getConnection(),
            $related,
            $foreignKey,
            $localKey,
            $this->getAttribute($localKey),
        );
    }

    /**
     * Inverse belongs-to (FK on THIS table).
     *
     * @param class-string<Model> $related
     * @param string|null         $foreignKey  FK column on THIS table (default: {related_singular}_id)
     * @param string|null         $ownerKey    PK on related table
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new $related();
        $ownerKey   = $ownerKey ?? $relatedInstance->primaryKey;
        $foreignKey = $foreignKey ?? $this->guessRelatedForeignKey($relatedInstance);

        return new BelongsTo(
            static::getConnection(),
            $related,
            $foreignKey,
            $ownerKey,
            $this->getAttribute($foreignKey),
        );
    }

    /**
     * Get a cached relation result, or load it via the relation method (lazy load).
     *
     * @param string $relation Method name on this model
     */
    public function getRelation(string $relation): mixed
    {
        if (array_key_exists($relation, $this->relations)) {
            return $this->relations[$relation];
        }

        if (!method_exists($this, $relation)) {
            throw new \BadMethodCallException(
                "Relation method [{$relation}] does not exist on " . static::class . '.'
            );
        }

        $result = $this->{$relation}();

        // If the method returned a Relation descriptor, resolve it now
        if ($result instanceof Relation) {
            $result = $result->getResults();
        }

        $this->relations[$relation] = $result;

        return $result;
    }

    /**
     * Set a relation value directly (used by batchLoad during eager loading).
     */
    public function setRelation(string $relation, mixed $value): void
    {
        $this->relations[$relation] = $value;
    }

    // ── Magic property access ─────────────────────────────────────────────────

    public function __get(string $key): mixed
    {
        // Already-loaded relation result (cached)
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Relation method → lazy-load and cache
        if (method_exists($this, $key)) {
            return $this->getRelation($key);
        }

        // Raw attribute
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
     * Validate ORDER BY string against a strict whitelist.
     * Declared protected so the SoftDeletes trait can call static::validateOrderBy().
     *
     * Allowed: column_name [ASC|DESC] (, column_name [ASC|DESC])*
     *
     * @throws \InvalidArgumentException
     */
    protected static function validateOrderBy(string $orderBy): void
    {
        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*(\s+(ASC|DESC))?(\s*,\s*[a-zA-Z_][a-zA-Z0-9_]*(\s+(ASC|DESC))?)*$/i';

        if (!preg_match($pattern, trim($orderBy))) {
            throw new \InvalidArgumentException(
                "Invalid ORDER BY clause: \"{$orderBy}\". "
                . 'Only column names (alphanumeric/underscore) with optional ASC/DESC are allowed.'
            );
        }
    }

    // ── Public hydration ──────────────────────────────────────────────────────

    /**
     * Create a hydrated model instance from a raw database row.
     *
     * Declared public so Relation classes can call:
     *   ($this->relatedClass)::hydrateFromRow($row)
     *
     * @param  array<string, mixed>  $row
     * @return static
     */
    public static function hydrateFromRow(array $row): static
    {
        $instance             = new static();
        $instance->attributes = $row;
        $instance->exists     = true;
        return $instance;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function performInsert(): bool
    {
        $filtered = $this->filterFillable($this->attributes);

        static::newQuery()->insert($filtered);

        $this->attributes[$this->primaryKey] =
            (int) (new QueryBuilder(static::getConnection()))->lastInsertId();

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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }
        return array_intersect_key($data, array_flip($this->fillable));
    }

    /**
     * Get an attribute value by column name.
     * Public so Relation classes can read FK/PK values from model instances.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    protected function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Mass-assign fillable attributes.
     */
    /** @param array<string, mixed> $attributes */
    public function fill(array $attributes): void
    {
        foreach ($this->filterFillable($attributes) as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    /**
     * Guess FK for hasOne/hasMany: table 'users' → 'user_id'.
     * For irregular plurals, pass $foreignKey explicitly.
     */
    private function guessForeignKey(): string
    {
        return rtrim($this->table, 's') . '_id';
    }

    /**
     * Guess FK for belongsTo: related table 'users' → 'user_id' on this table.
     */
    private function guessRelatedForeignKey(Model $related): string
    {
        return rtrim($related->table, 's') . '_id';
    }

    /**
     * Get the table name (used by Relation classes).
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the primary key name.
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    // ── Eager loading internals ───────────────────────────────────────────────

    /**
     * Eager-load all registered relations on a set of models.
     * Resets static::$eagerLoad after loading.
     *
     * @param static[] $models
     */
    private static function eagerLoadRelations(array $models): void
    {
        $relations         = static::$eagerLoad;
        static::$eagerLoad = [];

        if (empty($relations) || empty($models)) {
            return;
        }

        foreach ($relations as $relation) {
            static::eagerLoadRelation($models, $relation);
        }
    }

    /**
     * Eager-load a single relation on all models.
     *
     * Algorithm (N+1 free):
     * 1. Call relation method on first model → get Relation descriptor (no query).
     * 2. Delegate to Relation::batchLoad() → ONE IN() query → map results to models.
     *
     * @param static[] $models
     */
    private static function eagerLoadRelation(array &$models, string $relation): void
    {
        if (empty($models)) {
            return;
        }

        $sample = $models[0];

        if (!method_exists($sample, $relation)) {
            throw new \BadMethodCallException(
                "Relation method [{$relation}] does not exist on " . static::class . '.'
            );
        }

        // Get the Relation descriptor — no query executed yet
        $descriptor = $sample->{$relation}();

        if ($descriptor instanceof Relation) {
            // Proper batch load: one query for all parent models
            $descriptor->batchLoad($models, $relation);
        } else {
            // Fallback: plain value returned — load per-model (safe but not N+1-free)
            $sample->setRelation($relation, $descriptor);
            for ($i = 1, $count = count($models); $i < $count; $i++) {
                $models[$i]->setRelation($relation, $models[$i]->{$relation}());
            }
        }
    }
}