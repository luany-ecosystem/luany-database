<?php

namespace Luany\Database\Relations;

use Luany\Database\Connection;
use Luany\Database\Model;
use Luany\Database\QueryBuilder;

/**
 * BelongsToMany — many-to-many through a pivot table.
 *
 *   // users ←→ role_user ←→ roles
 *   public function roles(): BelongsToMany
 *   {
 *       return $this->belongsToMany(Role::class)->withPivot('granted_at');
 *   }
 *
 *   $user->roles;                         // array<Role>, each with ->pivot
 *   $user->roles()->attach([1, 2]);
 *   $user->roles()->attach(3, ['granted_at' => '2026-11-11']);
 *   $user->roles()->detach(2);
 *   $user->roles()->sync([1, 3]);         // ['attached' => [...], 'detached' => [...]]
 *   User::with('roles')->get();           // one query for all users
 *
 * Pivot values are available as $role->pivot (array: both keys plus the
 * columns listed in withPivot()).
 */
class BelongsToMany extends Relation
{
    /** @var array<int, string> */
    private array $pivotColumns = [];

    /**
     * @param class-string<Model> $relatedClass
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $relatedClass,
        private readonly string $pivotTable,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        private readonly string $parentKey,
        private readonly string $relatedKey,
        private readonly mixed $parentValue,
    ) {}

    /**
     * Extra pivot columns to load into ->pivot.
     */
    public function withPivot(string ...$columns): static
    {
        $this->pivotColumns = array_values(array_unique([...$this->pivotColumns, ...$columns]));
        return $this;
    }

    /**
     * @return array<int, Model>
     */
    public function getResults(): array
    {
        if ($this->parentValue === null) {
            return [];
        }

        return array_map(
            fn (array $row) => $this->hydrate($row),
            $this->baseQuery()->where("{$this->pivotTable}.{$this->foreignPivotKey}", '=', $this->parentValue)->get()
        );
    }

    /**
     * @param array<int, Model> $models
     */
    public function batchLoad(array $models, string $relationName): void
    {
        $parentValues = array_values(array_unique(array_filter(
            array_map(fn (Model $m) => $m->getAttribute($this->parentKey), $models),
            fn ($v) => $v !== null,
        )));

        $grouped = [];

        if ($parentValues !== []) {
            $rows = $this->baseQuery()
                ->whereIn("{$this->pivotTable}.{$this->foreignPivotKey}", $parentValues)
                ->get();

            foreach ($rows as $row) {
                $grouped[(string) $row[$this->pivotAlias($this->foreignPivotKey)]][] = $this->hydrate($row);
            }
        }

        foreach ($models as $model) {
            $value = $model->getAttribute($this->parentKey);
            $model->setRelation($relationName, $value === null ? [] : ($grouped[(string) $value] ?? []));
        }
    }

    /**
     * Insert pivot rows. $ids: an id, a list of ids, or [id => [extra columns]].
     *
     * @param int|string|array<int|string, mixed> $ids
     * @param array<string, mixed>                $attributes Extra pivot columns for every row
     */
    public function attach(int|string|array $ids, array $attributes = []): void
    {
        $parent = $this->requireParent('attach');

        foreach ($this->normaliseIds($ids) as $id => $extra) {
            $this->pivot()->insert(array_merge($attributes, $extra, [
                $this->foreignPivotKey => $parent,
                $this->relatedPivotKey => $id,
            ]));
        }
    }

    /**
     * Delete pivot rows for the given ids (all of them when null).
     * Returns the number of rows removed.
     *
     * @param int|string|array<int, int|string>|null $ids
     */
    public function detach(int|string|array|null $ids = null): int
    {
        $parent = $this->requireParent('detach');
        $query  = $this->pivot()->where($this->foreignPivotKey, '=', $parent);

        if ($ids !== null) {
            $list = array_keys($this->normaliseIds($ids));
            if ($list === []) {
                return 0;
            }
            $query->whereIn($this->relatedPivotKey, $list);
        }

        return $query->delete();
    }

    /**
     * Make the pivot contain exactly $ids (attaching / detaching as needed).
     * Runs in a transaction.
     *
     * @param array<int|string, mixed> $ids List of ids, or [id => [extra columns]]
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>}
     */
    public function sync(array $ids, bool $detaching = true): array
    {
        $parent = $this->requireParent('sync');
        $wanted = $this->normaliseIds($ids);

        return $this->connection->transaction(function () use ($parent, $wanted, $detaching): array {
            $current = array_map(
                fn (array $row) => $row[$this->relatedPivotKey],
                $this->pivot()->select($this->relatedPivotKey)->where($this->foreignPivotKey, '=', $parent)->get()
            );
            $currentKeys = array_map('strval', $current);

            $detach = $detaching
                ? array_values(array_filter($current, fn ($id) => !array_key_exists((string) $id, $wanted)))
                : [];

            $attach = [];
            foreach ($wanted as $id => $extra) {
                if (!in_array((string) $id, $currentKeys, true)) {
                    $attach[$id] = $extra;
                }
            }

            if ($detach !== []) {
                $this->detach($detach);
            }
            if ($attach !== []) {
                $this->attach($attach);
            }

            return ['attached' => array_keys($attach), 'detached' => $detach];
        });
    }

    public function getPivotTable(): string
    {
        return $this->pivotTable;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function baseQuery(): QueryBuilder
    {
        /** @var Model $related */
        $related      = new $this->relatedClass();
        $relatedTable = $related->getTable();

        $columns = [
            "{$relatedTable}.*",
            "{$this->pivotTable}.{$this->foreignPivotKey} AS " . $this->pivotAlias($this->foreignPivotKey),
            "{$this->pivotTable}.{$this->relatedPivotKey} AS " . $this->pivotAlias($this->relatedPivotKey),
        ];
        foreach ($this->pivotColumns as $column) {
            $columns[] = "{$this->pivotTable}.{$column} AS " . $this->pivotAlias($column);
        }

        return ($this->relatedClass)::query()->toBase()
            ->select(...$columns)
            ->join($this->pivotTable, "{$this->pivotTable}.{$this->relatedPivotKey}", '=', "{$relatedTable}.{$this->relatedKey}");
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Model
    {
        $pivot = [];
        foreach ([$this->foreignPivotKey, $this->relatedPivotKey, ...$this->pivotColumns] as $column) {
            $alias = $this->pivotAlias($column);
            if (array_key_exists($alias, $row)) {
                $pivot[$column] = $row[$alias];
                unset($row[$alias]);
            }
        }

        $model = ($this->relatedClass)::hydrateFromRow($row);
        $model->setRelation('pivot', $pivot);

        return $model;
    }

    private function pivotAlias(string $column): string
    {
        return 'pivot_' . $column;
    }

    private function pivot(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))->table($this->pivotTable);
    }

    private function requireParent(string $operation): mixed
    {
        if ($this->parentValue === null) {
            throw new \LogicException("Cannot {$operation}() on an unsaved parent model.");
        }

        return $this->parentValue;
    }

    /**
     * @param int|string|array<int|string, mixed> $ids
     * @return array<int|string, array<string, mixed>> id => extra pivot columns
     */
    private function normaliseIds(int|string|array $ids): array
    {
        if (!is_array($ids)) {
            return [$ids => []];
        }

        $out = [];
        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $value;           // [id => [extra]]
            } elseif ($value instanceof Model) {
                $out[$value->getKey()] = [];
            } else {
                $out[$value] = [];             // [id, id, …]
            }
        }
        return $out;
    }
}
