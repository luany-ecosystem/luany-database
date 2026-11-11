<?php

namespace Luany\Database\Relations;

use Luany\Database\Model;
use Luany\Database\ModelQuery;
use Luany\Database\QueryBuilder;

/**
 * Base class of relation descriptors.
 *
 * — Lazy loading:  $model->relation           → getResults()
 * — Eager loading: Model::with('relation')    → batchLoad() (ONE query for all parents)
 * — Constraints:   with(['relation' => fn ($q) => …]) → constrain()
 * — Existence:     whereHas('relation') / withCount('relation') → existenceQuery()
 */
abstract class Relation
{
    /** @var (\Closure(ModelQuery<Model>): mixed)|null */
    protected ?\Closure $constraint = null;

    private static int $aliasCounter = 0;

    abstract public function getResults(): mixed;

    /**
     * @param array<int, Model> $models
     */
    abstract public function batchLoad(array $models, string $relationName): void;

    /**
     * Extra conditions for the related query (constrained eager loading).
     *
     * @param (\Closure(ModelQuery<Model>): mixed)|null $constraint
     */
    public function constrain(?\Closure $constraint): static
    {
        $this->constraint = $constraint;
        return $this;
    }

    /**
     * Correlated subquery (no SELECT list yet) matching related rows of a
     * parent row of $parentTable. Used by whereHas() / withCount().
     *
     * @param (\Closure(ModelQuery<Model>): mixed)|null $constraint
     */
    public function existenceQuery(string $parentTable, ?\Closure $constraint = null): QueryBuilder
    {
        throw new \LogicException(static::class . ' does not support whereHas() / withCount().');
    }

    /**
     * Base query for the related model: its global scopes (SoftDeletes) plus
     * the eager-load constraint, if any.
     *
     * @param class-string<Model> $class
     */
    protected function newRelatedQuery(string $class): QueryBuilder
    {
        $query = $class::query();

        if ($this->constraint !== null) {
            ($this->constraint)($query);
        }

        return $query->toBase();
    }

    /**
     * Related query aliased for use inside a correlated subquery, with the
     * user constraint applied after aliasing (so nested whereHas() inside the
     * constraint correlates with the alias).
     *
     * @param class-string<Model>                       $class
     * @param (\Closure(ModelQuery<Model>): mixed)|null $constraint
     * @return array{0: QueryBuilder, 1: string} [query, alias]
     */
    protected function aliasedRelatedQuery(string $class, ?\Closure $constraint): array
    {
        $alias = 'luany_rel_' . (++self::$aliasCounter);
        $query = $class::query();
        $query->toBase()->alias($alias);
        $query->setTableReference($alias);

        if ($constraint !== null) {
            $constraint($query);
        }

        return [$query->toBase(), $alias];
    }
}
