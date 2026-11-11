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
    /** @var array<int, string> */
    private array $eager = [];

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
     * Relations to eager-load with the results.
     */
    public function with(string ...$relations): static
    {
        $this->eager = array_values(array_unique([...$this->eager, ...$relations]));
        return $this;
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
