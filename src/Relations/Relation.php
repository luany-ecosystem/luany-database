<?php

namespace Luany\Database\Relations;

use Luany\Database\Model;

/**
 * Relation
 *
 * Abstract base for all relationship descriptors.
 *
 * Each Relation object is created by a relationship method (hasOne, hasMany, belongsTo)
 * and carries full metadata about the relationship without executing a query.
 *
 * — Lazy loading: access $model->relation via __get → getRelation() → getResults()
 * — Eager loading: Model::with('relation')->all() → batchLoad() (ONE query for all parents)
 */
abstract class Relation
{
    /**
     * Execute the relationship query for a single (lazy-loaded) parent model.
     */
    abstract public function getResults(): mixed;

    /**
     * Batch-load this relation for a collection of parent models using a single query.
     * Prevents N+1 by collecting all FK values, doing one IN() query, then mapping results.
     *
     * Mutates each model by calling setRelation($relationName, $value).
     *
     * @param Model[] $models
     */
    abstract public function batchLoad(array $models, string $relationName): void;
}
