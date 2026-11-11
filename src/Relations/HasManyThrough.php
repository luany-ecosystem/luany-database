<?php

namespace Luany\Database\Relations;

use Luany\Database\Connection;
use Luany\Database\Model;
use Luany\Database\QueryBuilder;

/**
 * HasManyThrough — distant relation through an intermediate table.
 *
 *   countries ← users.country_id ← posts.user_id
 *
 *   // Country
 *   public function posts(): HasManyThrough
 *   {
 *       return $this->hasManyThrough(Post::class, User::class);
 *   }
 *
 * One JOIN query for lazy loading, one for eager loading.
 */
class HasManyThrough extends Relation
{
    private const THROUGH_KEY = 'luany_through_key';

    /**
     * @param class-string<Model> $relatedClass
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $relatedClass,
        private readonly string $throughTable,
        private readonly string $firstKey,        // on through table → parent
        private readonly string $secondKey,       // on related table → through
        private readonly string $localKey,        // on parent
        private readonly string $secondLocalKey,  // on through table
        private readonly mixed $localValue,
    ) {}

    /**
     * @return array<int, Model>
     */
    public function getResults(): array
    {
        if ($this->localValue === null) {
            return [];
        }

        return array_map(
            fn (array $row) => $this->hydrate($row),
            $this->baseQuery()->where("{$this->throughTable}.{$this->firstKey}", '=', $this->localValue)->get()
        );
    }

    /**
     * @param array<int, Model> $models
     */
    public function batchLoad(array $models, string $relationName): void
    {
        $values = array_values(array_unique(array_filter(
            array_map(fn (Model $m) => $m->getAttribute($this->localKey), $models),
            fn ($v) => $v !== null,
        )));

        $grouped = [];
        if ($values !== []) {
            foreach ($this->baseQuery()->whereIn("{$this->throughTable}.{$this->firstKey}", $values)->get() as $row) {
                $grouped[(string) $row[self::THROUGH_KEY]][] = $this->hydrate($row);
            }
        }

        foreach ($models as $model) {
            $value = $model->getAttribute($this->localKey);
            $model->setRelation($relationName, $value === null ? [] : ($grouped[(string) $value] ?? []));
        }
    }

    public function existenceQuery(string $parentTable, ?\Closure $constraint = null): QueryBuilder
    {
        [$query, $alias] = $this->aliasedRelatedQuery($this->relatedClass, $constraint);

        return $query
            ->join($this->throughTable, "{$this->throughTable}.{$this->secondLocalKey}", '=', "{$alias}.{$this->secondKey}")
            ->whereColumn("{$this->throughTable}.{$this->firstKey}", '=', "{$parentTable}.{$this->localKey}");
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    private function baseQuery(): QueryBuilder
    {
        /** @var Model $related */
        $related      = new $this->relatedClass();
        $relatedTable = $related->getTable();

        return $this->newRelatedQuery($this->relatedClass)
            ->select("{$relatedTable}.*", "{$this->throughTable}.{$this->firstKey} AS " . self::THROUGH_KEY)
            ->join(
                $this->throughTable,
                "{$this->throughTable}.{$this->secondLocalKey}",
                '=',
                "{$relatedTable}.{$this->secondKey}"
            );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Model
    {
        unset($row[self::THROUGH_KEY]);
        return ($this->relatedClass)::hydrateFromRow($row);
    }
}
