<?php

namespace Luany\Database;

use Luany\Database\Concerns\HasCasts;
use Luany\Database\Concerns\HasEvents;
use Luany\Database\Concerns\HasTimestamps;
use Luany\Database\Relations\BelongsTo;
use Luany\Database\Relations\BelongsToMany;
use Luany\Database\Relations\HasManyThrough;
use Luany\Database\Relations\MorphMany;
use Luany\Database\Relations\MorphOne;
use Luany\Database\Relations\MorphTo;
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
    use HasCasts;
    use HasEvents;
    use HasTimestamps;

    /** Column for the creation timestamp (null to disable). */
    public const CREATED_AT = 'created_at';

    /** Column for the update timestamp (null to disable). */
    public const UPDATED_AT = 'updated_at';

    // ── Schema configuration ─────────────────────────────────────────────────

    protected string $table      = '';
    protected string $primaryKey = 'id';

    /** Whether the primary key is an auto-incrementing integer. */
    protected bool $incrementing = true;

    /** Primary key type when $incrementing is true: "int" or "string". */
    protected string $keyType = 'int';

    /**
     * Columns allowed for mass assignment (fill(), create()).
     * When non-empty it is the only thing that matters.
     *
     * @var array<int, string>
     */
    protected array $fillable = [];

    /**
     * Columns blocked from mass assignment when $fillable is empty.
     * The default ['*'] blocks everything: a model must opt in through
     * $fillable, or explicitly set $guarded = [] to allow every column.
     *
     * @var array<int, string>
     */
    protected array $guarded = ['*'];

    /** Columns excluded from toArray() and JSON output */
    /** @var array<int, string> */
    protected array $hidden = [];

    // ── Internal state ───────────────────────────────────────────────────────
    // NOTE: protected (not private) so the SoftDeletes trait can access them.

    /** Raw column values from the database */
    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** Attribute values as last loaded from / persisted to the database */
    /** @var array<string, mixed> */
    protected array $original = [];

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
     * Returns a ModelQuery, so the eager list combines with any query:
     *   User::with('posts')->where('active', '=', 1)->get();
     *
     * Usage:
     *   User::with('posts')->all();
     *   User::with('posts', 'profile')->all();
     *   User::with('profile')->find(1);
     *
     * @return ModelQuery<static>
     */
    public static function with(string ...$relations): ModelQuery
    {
        return static::query()->with(...$relations);
    }

    /**
     * Set the eager-load relations for the next query.
     * Called by EagerProxy before delegating to all() or find().
     * Consumed by find()/all() before the query executes.
     *
     * @internal Used by EagerProxy — do not call directly.
     *
     * @param string[] $relations
     */
    public static function setEagerLoad(array $relations): void
    {
        static::$eagerLoad = $relations;
    }

    /**
     * Take (and clear) the pending eager-load list.
     *
     * Consumed BEFORE the query runs so that an early return or an exception
     * can never leak the list into an unrelated later query.
     *
     * @return array<int|string, mixed>
     */
    private static function pullEagerLoad(): array
    {
        $relations         = static::$eagerLoad;
        static::$eagerLoad = [];
        return $relations;
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
     * Fluent query returning models (respects global scopes such as
     * SoftDeletes, supports local scopes, eager loading and pagination).
     *
     *   $users = User::query()->where('active', '=', 1)->orderBy('name')->get();
     *   $rows  = User::query()->toBase()->get();   // raw arrays
     *
     * @return ModelQuery<static>
     */
    public static function query(): ModelQuery
    {
        return new ModelQuery(static::class, static::newQuery());
    }

    /**
     * Start a query through a local scope: User::active() → scopeActive().
     *
     * @param array<int, mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        if (method_exists(static::class, 'scope' . ucfirst($method))) {
            return static::query()->{$method}(...$arguments);
        }

        throw new \BadMethodCallException('Call to undefined method ' . static::class . "::{$method}().");
    }

    /**
     * Find by primary key or throw ModelNotFoundException.
     *
     * @throws ModelNotFoundException
     */
    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw new ModelNotFoundException(static::class, $id);
    }

    /**
     * Find a record by primary key. Returns null if not found.
     */
    public static function find(int|string $id): ?static
    {
        $with     = static::pullEagerLoad();
        $instance = new static();
        $row = static::newQuery()
            ->where($instance->primaryKey, '=', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        $model = static::hydrateFromRow($row);
        static::eagerLoadRelations([$model], $with);

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
        $with  = static::pullEagerLoad();
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

        static::eagerLoadRelations($models, $with);

        return $models;
    }

    /**
     * Return records matching a raw WHERE clause.
     *
     * WARNING: $conditions is raw SQL. Never interpolate user input into it —
     * always use ? placeholders and pass values in $bindings.
     * For a fully escaped API use static::query()->where(column, operator, value).
     *
     * @param  string  $conditions  e.g. 'email = ? AND active = ?'
     * @param  array<int|string, mixed>  $bindings    e.g. ['a@b.com', 1]
     * @return array<int, static>
     */
    public static function where(string $conditions, array $bindings = []): array
    {
        return array_map(
            fn(array $row) => static::hydrateFromRow($row),
            static::newQuery()->whereRaw($conditions, $bindings)->get()
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

        return static::newQuery()->whereRaw($conditions, $bindings)->count();
    }

    /**
     * Create a new record and return the hydrated model instance.
     */
    /** @param array<string, mixed> $data */
    public static function create(array $data): static
    {
        $model = new static();
        $model->fill($data);

        if ($model->attributes === []) {
            throw new \InvalidArgumentException(
                'No fillable attributes provided for ' . static::class . '. '
                . 'List the columns in $fillable (or set $guarded = [] to allow all).'
            );
        }

        $model->save();

        $key = $model->getKey();
        $fresh = $key !== null ? static::newQuery()->where($model->primaryKey, '=', $key)->first() : null;

        if ($fresh !== null) {
            $model->attributes = $fresh;
            $model->syncOriginal();
        }

        return $model;
    }

    // ── Instance methods ─────────────────────────────────────────────────────

    /**
     * Persist the model — INSERT if new, UPDATE if existing.
     */
    public function save(): bool
    {
        if (!$this->fireModelEvent('saving')) {
            return false;
        }

        if ($this->exists) {
            // Nothing changed: no UPDATE and no updating/updated events.
            if ($this->getDirty() !== []) {
                if (!$this->fireModelEvent('updating')) {
                    return false;
                }
                $this->performUpdate();
                $this->fireModelEvent('updated');
            }
        } else {
            if (!$this->fireModelEvent('creating')) {
                return false;
            }
            $this->performInsert();
            $this->fireModelEvent('created');
        }

        $this->fireModelEvent('saved');
        return true;
    }

    /**
     * Mass-assign (respecting $fillable / $guarded) and save.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);
        return $this->save();
    }

    /**
     * Reload attributes from the database (discarding unsaved changes and
     * cached relations). Returns false when the row no longer exists.
     */
    public function refresh(): bool
    {
        $key = $this->getKey();

        if ($key === null) {
            return false;
        }

        $row = static::newQuery()->where($this->primaryKey, '=', $key)->first();

        if ($row === null) {
            return false;
        }

        $this->attributes = $row;
        $this->relations  = [];
        $this->syncOriginal();
        return true;
    }

    /**
     * Hard-delete this record from the database.
     * NOTE: Overridden by the SoftDeletes trait to soft-delete instead.
     */
    public function delete(): bool
    {
        if (!$this->exists || !$this->fireModelEvent('deleting')) {
            return false;
        }

        static::newQuery()
            ->where($this->primaryKey, '=', $this->getAttribute($this->primaryKey))
            ->delete();

        $this->exists = false;
        $this->fireModelEvent('deleted');
        return true;
    }

    /**
     * Return attributes as array, excluding $hidden columns.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden, true)) {
                $out[$key] = $this->serializeAttribute($key, $value);
            }
        }
        return $out;
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
     * Many-to-many through a pivot table.
     *
     * Defaults follow the usual conventions:
     *   pivot table       singular names in alphabetical order: role_user
     *   foreignPivotKey   {this_singular}_id     (user_id)
     *   relatedPivotKey   {related_singular}_id  (role_id)
     *
     * @param class-string<Model> $related
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
    ): BelongsToMany {
        /** @var Model $relatedInstance */
        $relatedInstance = new $related();

        $self  = static::singularize($this->table);
        $other = static::singularize($relatedInstance->table);

        if ($pivotTable === null) {
            $names = [$self, $other];
            sort($names);
            $pivotTable = implode('_', $names);
        }

        $parentKey ??= $this->primaryKey;

        return new BelongsToMany(
            static::getConnection(),
            $related,
            $pivotTable,
            $foreignPivotKey ?? "{$self}_id",
            $relatedPivotKey ?? "{$other}_id",
            $parentKey,
            $relatedKey ?? $relatedInstance->primaryKey,
            $this->getAttribute($parentKey),
        );
    }

    /**
     * Distant one-to-many through an intermediate model.
     *
     *   countries ← users.country_id ← posts.user_id
     *   $this->hasManyThrough(Post::class, User::class)
     *
     * @param class-string<Model> $related
     * @param class-string<Model> $through
     * @param string|null $firstKey       FK on the through table to this model (country_id)
     * @param string|null $secondKey      FK on the related table to the through model (user_id)
     * @param string|null $localKey       Key on this model (id)
     * @param string|null $secondLocalKey Key on the through model (id)
     */
    protected function hasManyThrough(
        string $related,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,
    ): HasManyThrough {
        /** @var Model $throughInstance */
        $throughInstance = new $through();
        $localKey ??= $this->primaryKey;

        return new HasManyThrough(
            static::getConnection(),
            $related,
            $throughInstance->table,
            $firstKey ?? $this->guessForeignKey(),
            $secondKey ?? static::singularize($throughInstance->table) . '_id',
            $localKey,
            $secondLocalKey ?? $throughInstance->primaryKey,
            $this->getAttribute($localKey),
        );
    }

    /**
     * Polymorphic one-to-many: $related has {$name}_type and {$name}_id.
     *
     * @param class-string<Model> $related
     */
    protected function morphMany(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphMany
    {
        $localKey ??= $this->primaryKey;

        return new MorphMany(
            static::getConnection(),
            $related,
            $type ?? "{$name}_type",
            $id ?? "{$name}_id",
            $this->getMorphClass(),
            $localKey,
            $this->getAttribute($localKey),
        );
    }

    /**
     * Polymorphic one-to-one: $related has {$name}_type and {$name}_id.
     *
     * @param class-string<Model> $related
     */
    protected function morphOne(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphOne
    {
        $localKey ??= $this->primaryKey;

        return new MorphOne(
            static::getConnection(),
            $related,
            $type ?? "{$name}_type",
            $id ?? "{$name}_id",
            $this->getMorphClass(),
            $localKey,
            $this->getAttribute($localKey),
        );
    }

    /**
     * Owning side of a polymorphic relation. $name defaults to the calling
     * method's name (commentable() → commentable_type / commentable_id).
     */
    protected function morphTo(?string $name = null, ?string $type = null, ?string $id = null, ?string $ownerKey = null): MorphTo
    {
        $name ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

        return new MorphTo(
            static::getConnection(),
            $this,
            $type ?? static::snake($name) . '_type',
            $id ?? static::snake($name) . '_id',
            $ownerKey,
        );
    }

    // ── Morph map ─────────────────────────────────────────────────────────────

    /** @var array<string, class-string<Model>> alias → class */
    private static array $morphMap = [];

    /**
     * Store short aliases instead of class names in *_type columns:
     *   Model::morphMap(['post' => Post::class, 'video' => Video::class]);
     *
     * @param array<string, class-string<Model>> $map
     */
    public static function morphMap(array $map, bool $merge = true): void
    {
        self::$morphMap = $merge ? array_merge(self::$morphMap, $map) : $map;
    }

    /**
     * Value stored in *_type columns for this model.
     */
    public function getMorphClass(): string
    {
        $alias = array_search(static::class, self::$morphMap, true);
        return $alias === false ? static::class : (string) $alias;
    }

    /**
     * Model class for a stored *_type value.
     *
     * @return class-string<Model>
     */
    public static function resolveMorphClass(string $type): string
    {
        $class = self::$morphMap[$type] ?? $type;

        if (!is_subclass_of($class, self::class)) {
            throw new \UnexpectedValueException(
                "Unknown morph type [{$type}]. Register it with Model::morphMap(['{$type}' => SomeModel::class])."
            );
        }

        return $class;
    }

    private static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
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
        // Column values always win over methods (a column named "count" or
        // "delete" must not call Model::count()/delete()).
        if (array_key_exists($key, $this->attributes)) {
            return $this->castForRead($key, $this->attributes[$key]);
        }

        // Already-loaded relation result (cached)
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Relation method → lazy-load and cache
        if (static::isRelationMethod($key)) {
            return $this->getRelation($key);
        }

        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key] !== null;
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key] !== null;
        }

        if (static::isRelationMethod($key)) {
            return $this->getRelation($key) !== null;
        }

        return false;
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    /** @var array<string, array<string, bool>> */
    private static array $relationMethodCache = [];

    /**
     * Whether $name is a user-defined relation method on this model:
     * a non-static, public, zero-required-argument method not declared by
     * the base Model or by a framework trait (e.g. SoftDeletes).
     */
    protected static function isRelationMethod(string $name): bool
    {
        $class = static::class;

        if (isset(self::$relationMethodCache[$class][$name])) {
            return self::$relationMethodCache[$class][$name];
        }

        $result = false;

        if (method_exists($class, $name)) {
            $method   = new \ReflectionMethod($class, $name);
            $declarer = $method->getDeclaringClass()->getName();

            $result = !$method->isStatic()
                && $method->isPublic()
                && $method->getNumberOfRequiredParameters() === 0
                && $declarer !== self::class
                && !method_exists(Concerns\SoftDeletes::class, $name);

            if ($result) {
                $type = $method->getReturnType();
                // Methods typed with a non-Relation return type are not relations.
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $result = is_a($type->getName(), Relation::class, true);
                } elseif ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                    $result = in_array($type->getName(), ['array', 'mixed'], true);
                }
            }
        }

        return self::$relationMethodCache[$class][$name] = $result;
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
        $instance->original   = $row;
        $instance->exists     = true;
        return $instance;
    }

    // ── Keys & dirty tracking ─────────────────────────────────────────────────

    /**
     * Value of the primary key, or null for unsaved models.
     */
    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /**
     * Attributes changed since the model was loaded / last saved.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || !$this->sameValue($this->original[$key], $value)) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Whether the model (or a given attribute) has unsaved changes.
     */
    public function isDirty(?string $attribute = null): bool
    {
        $dirty = $this->getDirty();
        return $attribute === null ? $dirty !== [] : array_key_exists($attribute, $dirty);
    }

    /**
     * Original value of an attribute (or all originals).
     */
    public function getOriginal(?string $attribute = null): mixed
    {
        return $attribute === null ? $this->original : ($this->original[$attribute] ?? null);
    }

    protected function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    /**
     * Drivers return numbers as strings or ints; "5" and 5 are the same value.
     */
    private function sameValue(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (string) $a === (string) $b || (float) $a === (float) $b;
        }

        return $a === $b;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * INSERT every attribute set on the model. $fillable only guards mass
     * assignment (fill()/create()); direct property assignment is trusted.
     */
    private function performInsert(): bool
    {
        $this->applyInsertTimestamps();

        static::newQuery()->insert($this->attributes);

        if ($this->incrementing && $this->getKey() === null) {
            $id = static::getConnection()->lastInsertId();
            $this->attributes[$this->primaryKey] = $this->keyType === 'int' ? (int) $id : $id;
        }

        $this->exists = true;
        $this->syncOriginal();
        return true;
    }

    /**
     * UPDATE only the attributes that changed since load / last save.
     */
    private function performUpdate(): bool
    {
        $dirty = $this->applyUpdateTimestamps($this->getDirty());

        if ($dirty === []) {
            return true;
        }

        $key = $this->original[$this->primaryKey] ?? $this->getKey();

        static::newQuery()
            ->where($this->primaryKey, '=', $key)
            ->update($dirty);

        $this->syncOriginal();
        return true;
    }

    /**
     * Whether a column may be mass-assigned.
     */
    public function isFillable(string $key): bool
    {
        if (in_array($key, $this->fillable, true)) {
            return true;
        }

        if ($this->fillable !== []) {
            return false;
        }

        if (in_array('*', $this->guarded, true)) {
            return false;
        }

        return !in_array($key, $this->guarded, true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterFillable(array $data): array
    {
        return array_filter($data, fn ($key) => $this->isFillable((string) $key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Get an attribute value by column name.
     * Public so Relation classes can read FK/PK values from model instances.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->castForRead($key, $this->attributes[$key] ?? null);
    }

    /**
     * Set an attribute, applying its cast (e.g. array → JSON, DateTime → string).
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $this->castForWrite($key, $value);
    }

    /**
     * Mass-assign fillable attributes.
     */
    /** @param array<string, mixed> $attributes */
    public function fill(array $attributes): void
    {
        foreach ($this->filterFillable($attributes) as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }
    }

    /**
     * Assign attributes bypassing $fillable / $guarded. Only for trusted data.
     *
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }
        return $this;
    }

    /**
     * Guess FK for hasOne/hasMany: table 'users' → 'user_id'.
     * For irregular plurals, pass $foreignKey explicitly.
     */
    private function guessForeignKey(): string
    {
        return static::singularize($this->table) . '_id';
    }

    /**
     * Guess FK for belongsTo: related table 'users' → 'user_id' on this table.
     */
    private function guessRelatedForeignKey(Model $related): string
    {
        return static::singularize($related->table) . '_id';
    }

    /**
     * Small English singularizer for foreign-key guessing.
     * Covers common table names; pass keys explicitly for anything exotic.
     */
    public static function singularize(string $word): string
    {
        static $irregular = [
            'people'   => 'person',
            'children' => 'child',
            'men'      => 'man',
            'women'    => 'woman',
            'statuses' => 'status',
            'buses'    => 'bus',
            'news'     => 'news',
            'series'   => 'series',
        ];

        $lower = strtolower($word);

        if (isset($irregular[$lower])) {
            return $irregular[$lower];
        }

        return match (true) {
            str_ends_with($lower, 'ies') && strlen($lower) > 3              => substr($word, 0, -3) . 'y',
            (bool) preg_match('/(ss|sh|ch|x|z)es$/', $lower)                 => substr($word, 0, -2),
            (bool) preg_match('/(ss|us|is)$/', $lower)                        => $word,
            str_ends_with($lower, 's')                                        => substr($word, 0, -1),
            default                                                           => $word,
        };
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
     * Eager-load the given relations on a set of models.
     *
     * @param static[]                 $models
     * @param array<int|string, mixed> $relations
     */
    public static function eagerLoadModels(array $models, array $relations): void
    {
        static::eagerLoadRelations($models, $relations);
    }

    /**
     * @internal Used by ModelQuery::all().
     */
    public static function assertValidOrderBy(string $orderBy): void
    {
        static::validateOrderBy($orderBy);
    }

    /**
     * @param static[]                 $models
     * @param array<int|string, mixed> $relations
     */
    private static function eagerLoadRelations(array $models, array $relations): void
    {
        if (empty($relations) || empty($models)) {
            return;
        }

        // 'posts.comments.author' → load posts, then comments.author on the posts
        $tree = [];
        foreach ($relations as $relation) {
            $parts = explode('.', (string) $relation, 2);
            $tree[$parts[0]] ??= [];
            if (isset($parts[1]) && $parts[1] !== '') {
                $tree[$parts[0]][] = $parts[1];
            }
        }

        foreach ($tree as $relation => $nested) {
            static::eagerLoadRelation($models, $relation);

            if ($nested === []) {
                continue;
            }

            $children = [];
            foreach ($models as $model) {
                $loaded = $model->relations[$relation] ?? null;
                foreach (is_array($loaded) ? $loaded : [$loaded] as $child) {
                    if ($child instanceof Model) {
                        $children[] = $child;
                    }
                }
            }

            // Group by class: a morphTo relation can return several model types.
            $byClass = [];
            foreach ($children as $child) {
                $byClass[get_class($child)][] = $child;
            }
            foreach ($byClass as $class => $group) {
                $class::eagerLoadModels($group, array_values(array_unique($nested)));
            }
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