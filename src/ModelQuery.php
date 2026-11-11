<?php

namespace Luany\Database;

/**
 * ModelQuery
 *
 * Fluent query over a model's table that returns hydrated models.
 * Every QueryBuilder method (where, whereIn, whereNested, orderBy, limit, …)
 * is available, plus local scopes, eager loading and pagination.
 *
 *   $posts = Post::query()
 *       ->published()                    // scopePublished(ModelQuery $q)
 *       ->where('views', '>', 100)
 *       ->with('author')
 *       ->orderByDesc('published_at')
 *       ->paginate(15, $page);
 *
 * Global scopes such as SoftDeletes are already applied (the query starts
 * from Model::newQuery()).
 *
 * Bulk update()/delete() run directly on the table: they skip casts,
 * timestamps and per-model logic.
 *
 * @template TModel of Model
 * @mixin QueryBuilder
 */
class ModelQuery
{
    /** @var array<int|string, string|\Closure> Eager relations: names, or name => constraint */
    private array $eager = [];

    /** Table name / alias other queries correlate with (see whereHas()). */
    private ?string $tableReference = null;

    /**
     * @param class-string<TModel> $model
     */
    public function __construct(
        private readonly string $model,
        private QueryBuilder $query,
    ) {}

    /**
     * Forward to a local scope (scopeXxx on the model) or to the QueryBuilder.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $scope = 'scope' . ucfirst($method);

        if (method_exists($this->model, $scope)) {
            (new $this->model())->{$scope}($this, ...$arguments);
            return $this;
        }

        if ($method === 'table') {
            throw new \BadMethodCallException('A model query is bound to its model\'s table; use toBase() for another table.');
        }

        if (method_exists($this->query, $method)) {
            $result = $this->query->{$method}(...$arguments);

            if ($result instanceof QueryBuilder) {
                $this->query = $result;
                return $this;
            }

            return $result;
        }

        throw new \BadMethodCallException(
            "Method [{$method}] does not exist on " . static::class . " for model [{$this->model}] "
            . "(no QueryBuilder method or scope{$scope})."
        );
    }

    /**
     * Relations to eager-load with the results. Constrain one by passing an
     * array: with(['posts' => fn (ModelQuery $q) => $q->where('published', '=', 1)], 'roles').
     *
     * @param string|array<int|string, string|\Closure> ...$relations
     */
    public function with(string|array ...$relations): static
    {
        foreach ($relations as $relation) {
            foreach ((array) $relation as $key => $value) {
                if (is_string($key)) {
                    $this->eager[$key] = $value;
                } elseif (!in_array($value, $this->eager, true)) {
                    $this->eager[] = $value;
                }
            }
        }
        return $this;
    }

    // ── Relationship existence ─────────────────────────────────────────────

    /**
     * Keep rows that have related rows matching $callback (count $operator $count).
     * Dot notation nests: whereHas('posts.comments', fn ($q) => …).
     *
     * @param (\Closure(ModelQuery<Model>): mixed)|null $callback
     */
    public function whereHas(string $relation, ?\Closure $callback = null, string $operator = '>=', int $count = 1, string $boolean = 'AND'): static
    {
        if (str_contains($relation, '.')) {
            [$head, $rest] = explode('.', $relation, 2);
            return $this->whereHas($head, fn (ModelQuery $q) => $q->whereHas($rest, $callback, $operator, $count), '>=', 1, $boolean);
        }

        $sub = $this->relation($relation)->existenceQuery($this->tableReference(), $callback);

        if ($operator === '>=' && $count === 1) {
            $sql = 'EXISTS (' . $sub->selectRaw('1')->toSql() . ')';
            $this->query = $this->query->whereRaw($sql, $sub->getBindings(), $boolean);
        } elseif (($operator === '<' && $count === 1) || ($operator === '=' && $count === 0)) {
            $sql = 'NOT EXISTS (' . $sub->selectRaw('1')->toSql() . ')';
            $this->query = $this->query->whereRaw($sql, $sub->getBindings(), $boolean);
        } else {
            $operator = $this->comparison($operator);
            $sql = '(' . $sub->selectRaw('COUNT(*)')->toSql() . ") {$operator} ?";
            $this->query = $this->query->whereRaw($sql, [...array_values($sub->getBindings()), $count], $boolean);
        }

        return $this;
    }

    /**
     * @param (\Closure(ModelQuery<Model>): mixed)|null $callback
     */
    public function orWhereHas(string $relation, ?\Closure $callback = null, string $operator = '>=', int $count = 1): static
    {
        return $this->whereHas($relation, $callback, $operator, $count, 'OR');
    }

    /**
     * Keep rows with no related rows matching $callback.
     *
     * @param (\Closure(ModelQuery<Model>): mixed)|null $callback
     */
    public function whereDoesntHave(string $relation, ?\Closure $callback = null): static
    {
        return $this->whereHas($relation, $callback, '<', 1);
    }

    public function has(string $relation, string $operator = '>=', int $count = 1): static
    {
        return $this->whereHas($relation, null, $operator, $count);
    }

    public function doesntHave(string $relation): static
    {
        return $this->whereDoesntHave($relation);
    }

    /**
     * Add {relation}_count columns: withCount('posts', ['comments as approved_comments' => fn ($q) => …]).
     *
     * @param string|array<int|string, string|\Closure> ...$relations
     */
    public function withCount(string|array ...$relations): static
    {
        if ($this->query->hasDefaultColumns()) {
            $this->query = $this->query->select($this->tableReference() . '.*');
        }

        foreach ($relations as $relation) {
            foreach ((array) $relation as $key => $value) {
                [$spec, $callback] = is_string($key) ? [$key, $value] : [(string) $value, null];

                $name = $spec;
                $as   = null;
                if (preg_match('/^(\S+)\s+as\s+(\S+)$/i', $spec, $m) === 1) {
                    [$name, $as] = [$m[1], $m[2]];
                }

                $alias = $as ?? self::snake($name) . '_count';
                if (!\Luany\Database\Grammar::isValidSegment($alias)) {
                    throw new \InvalidArgumentException("Invalid withCount alias [{$alias}].");
                }

                $sub = $this->relation($name)->existenceQuery(
                    $this->tableReference(),
                    $callback instanceof \Closure ? $callback : null
                )->selectRaw('COUNT(*)');

                $this->query = $this->query->selectRaw('(' . $sub->toSql() . ') AS ' . $alias, array_values($sub->getBindings()));
            }
        }

        return $this;
    }

    /**
     * @internal Correlate subqueries with an alias instead of the table name.
     */
    public function setTableReference(string $reference): static
    {
        $this->tableReference = $reference;
        return $this;
    }

    // ── Large result sets ───────────────────────────────────────────────────

    /**
     * Process models in chunks (eager loading applied per chunk).
     * Return false from the callback to stop.
     *
     * @param callable(array<int, TModel>, int): (bool|null|void) $callback
     */
    public function chunk(int $size, callable $callback): bool
    {
        return $this->query->chunk($size, fn (array $rows, int $page) => $callback($this->hydrateAll($rows), $page));
    }

    /**
     * Chunk by primary key — safe when the callback updates or deletes.
     *
     * @param callable(array<int, TModel>, int): (bool|null|void) $callback
     */
    public function chunkById(int $size, callable $callback, ?string $column = null): bool
    {
        $column ??= (new $this->model())->getPrimaryKey();

        return $this->query->chunkById($size, fn (array $rows, int $page) => $callback($this->hydrateAll($rows), $page), $column);
    }

    /**
     * Iterate models one at a time (no eager loading).
     *
     * @return \Generator<int, TModel>
     */
    public function cursor(): \Generator
    {
        foreach ($this->query->cursor() as $row) {
            yield ($this->model)::hydrateFromRow($row);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, TModel>
     */
    private function hydrateAll(array $rows): array
    {
        $models = array_map(fn (array $row) => ($this->model)::hydrateFromRow($row), $rows);
        ($this->model)::eagerLoadModels($models, $this->eager);
        return $models;
    }

    private function relation(string $name): \Luany\Database\Relations\Relation
    {
        $model = new $this->model();

        if (!method_exists($model, $name)) {
            throw new \BadMethodCallException("Relation [{$name}] does not exist on [{$this->model}].");
        }

        $relation = $model->{$name}();

        if (!$relation instanceof \Luany\Database\Relations\Relation) {
            throw new \BadMethodCallException("[{$this->model}::{$name}()] must return a Relation.");
        }

        return $relation;
    }

    private function tableReference(): string
    {
        return $this->tableReference ?? (new $this->model())->getTable();
    }

    private function comparison(string $operator): string
    {
        $operator = trim($operator);
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            throw new \InvalidArgumentException("Invalid count operator [{$operator}].");
        }
        return $operator;
    }

    private static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    /**
     * @return array<int, TModel>
     */
    public function get(): array
    {
        $models = array_map(
            fn (array $row) => ($this->model)::hydrateFromRow($row),
            $this->query->get()
        );

        ($this->model)::eagerLoadModels($models, $this->eager);

        return $models;
    }

    /**
     * get() with an optional validated "col [ASC|DESC], …" order (same
     * contract as Model::all(); kept for Model::with()->all()).
     *
     * @return array<int, TModel>
     */
    public function all(string $orderBy = ''): array
    {
        if ($orderBy !== '') {
            ($this->model)::assertValidOrderBy($orderBy);
            foreach (explode(',', $orderBy) as $term) {
                $parts = preg_split('/\s+/', trim($term)) ?: [];
                $this->query = $this->query->orderBy($parts[0], strtoupper($parts[1] ?? 'ASC'));
            }
        }

        return $this->get();
    }

    /**
     * @return TModel|null
     */
    public function first(): ?Model
    {
        $this->query = $this->query->limit(1);
        return $this->get()[0] ?? null;
    }

    /**
     * @return TModel
     * @throws ModelNotFoundException
     */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelNotFoundException($this->model);
    }

    /**
     * @return TModel|null
     */
    public function find(int|string $id): ?Model
    {
        $this->query = $this->query->where((new $this->model())->getPrimaryKey(), '=', $id);
        return $this->first();
    }

    /**
     * @return TModel
     * @throws ModelNotFoundException
     */
    public function findOrFail(int|string $id): Model
    {
        return $this->find($id) ?? throw new ModelNotFoundException($this->model, $id);
    }

    /**
     * Paginate; PaginationResult::$data holds models.
     */
    public function paginate(int $perPage = 15, int $page = 1): PaginationResult
    {
        $raw    = $this->query->paginate($perPage, $page);
        $models = array_map(fn (array $row) => ($this->model)::hydrateFromRow($row), $raw->data);

        ($this->model)::eagerLoadModels($models, $this->eager);

        return new PaginationResult($models, $raw->total, $raw->perPage, $raw->currentPage);
    }

    /**
     * Values of one column (cast like the model would), optionally keyed by another.
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $out = [];
        foreach ($this->get() as $model) {
            $value = $model->getAttribute($column);
            if ($key === null) {
                $out[] = $value;
            } else {
                $out[(string) $model->getRawAttribute($key)] = $value;
            }
        }
        return $out;
    }

    /**
     * The underlying QueryBuilder (raw rows, no models).
     */
    public function toBase(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * @return class-string<TModel>
     */
    public function getModel(): string
    {
        return $this->model;
    }
}
