<?php

namespace Luany\Database\Relations;

use Luany\Database\Connection;
use Luany\Database\Model;
use Luany\Database\QueryBuilder;

/**
 * Shared logic of MorphMany / MorphOne: rows of $relatedClass whose
 * {$name}_type equals the parent's morph class and {$name}_id its key.
 */
abstract class MorphOneOrMany extends Relation
{
    /**
     * @param class-string<Model> $relatedClass
     */
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $relatedClass,
        protected readonly string $typeColumn,
        protected readonly string $idColumn,
        protected readonly string $morphType,
        protected readonly string $localKey,
        protected readonly mixed $localValue,
    ) {}

    /**
     * Attach $child to the parent (sets type/id) and save it.
     */
    public function save(Model $child): Model
    {
        if ($this->localValue === null) {
            throw new \LogicException('Cannot save a related model on an unsaved parent.');
        }

        $child->setAttribute($this->typeColumn, $this->morphType);
        $child->setAttribute($this->idColumn, $this->localValue);
        $child->save();

        return $child;
    }

    /**
     * Create a related model from attributes (respecting its $fillable).
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Model
    {
        /** @var Model $child */
        $child = new $this->relatedClass();
        $child->fill($attributes);

        return $this->save($child);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function query(): QueryBuilder
    {
        return $this->newRelatedQuery($this->relatedClass)->where($this->typeColumn, '=', $this->morphType);
    }

    public function existenceQuery(string $parentTable, ?\Closure $constraint = null): QueryBuilder
    {
        [$query, $alias] = $this->aliasedRelatedQuery($this->relatedClass, $constraint);

        return $query
            ->where("{$alias}.{$this->typeColumn}", '=', $this->morphType)
            ->whereColumn("{$alias}.{$this->idColumn}", '=', "{$parentTable}.{$this->localKey}");
    }

    /**
     * @param array<int, Model> $models
     * @return array<string, list<Model>> keyed by parent key
     */
    protected function loadGrouped(array $models): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(fn (Model $m) => $m->getAttribute($this->localKey), $models),
            fn ($v) => $v !== null,
        )));

        $grouped = [];
        if ($values !== []) {
            foreach ($this->query()->whereIn($this->idColumn, $values)->get() as $row) {
                $grouped[(string) $row[$this->idColumn]][] = ($this->relatedClass)::hydrateFromRow($row);
            }
        }

        return $grouped;
    }
}
