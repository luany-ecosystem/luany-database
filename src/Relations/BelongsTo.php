<?php

namespace Luany\Database\Relations;

use Luany\Database\Connection;
use Luany\Database\Model;
use Luany\Database\QueryBuilder;

/**
 * BelongsTo
 *
 * Represents the inverse side of a one-to-one or one-to-many relationship.
 * The FK lives on THIS (child) model's table and points to the parent.
 *
 * Example:
 *   A Post belongsTo a User.
 *   The FK `user_id` lives on the `posts` table and references `users.id`.
 *
 * Usage in model:
 *   public function user(): BelongsTo
 *   {
 *       return $this->belongsTo(User::class, 'user_id');
 *   }
 *
 * Access:
 *   $post->user                   // lazy: one query
 *   Post::with('user')->all()     // eager: one batched query for all posts
 */
class BelongsTo extends Relation
{
    /**
     * @param Connection $connection    Shared DB connection
     * @param string     $relatedClass  FQCN of the related (parent) model
     * @param string     $foreignKey    FK column on THIS (child) table (e.g. 'user_id')
     * @param string     $ownerKey      PK on the RELATED (parent) table (e.g. 'id')
     * @param mixed      $foreignValue  Actual FK value on this model instance (for lazy load)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $relatedClass,
        private readonly string $foreignKey,
        private readonly string $ownerKey,
        private readonly mixed $foreignValue,
    ) {}

    /**
     * Lazy-load: one query to get the parent record.
     */
    public function getResults(): ?Model
    {
        if ($this->foreignValue === null) {
            return null;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedClass();

        $row = (new QueryBuilder($this->connection))
            ->table($relatedInstance->getTable())
            ->where($this->ownerKey, '=', $this->foreignValue)
            ->first();

        return $row !== null
            ? ($this->relatedClass)::hydrateFromRow($row)
            : null;
    }

    /**
     * Eager-load: ONE query with WHERE IN on the parent table, keyed by owner key.
     *
     * @param Model[] $models
     */
    public function batchLoad(array $models, string $relationName): void
    {
        // Collect all unique, non-null FK values from the child models
        $foreignValues = array_values(array_unique(array_filter(
            array_map(fn(Model $m) => $m->getAttribute($this->foreignKey), $models),
            fn($v) => $v !== null,
        )));

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedClass();

        // Map: ownerKeyValue → related model instance
        $keyed = [];

        if (!empty($foreignValues)) {
            $rows = (new QueryBuilder($this->connection))
                ->table($relatedInstance->getTable())
                ->whereIn($this->ownerKey, $foreignValues)
                ->get();

            foreach ($rows as $row) {
                $ownerVal         = $row[$this->ownerKey];
                $keyed[$ownerVal] = ($this->relatedClass)::hydrateFromRow($row);
            }
        }

        // Assign parent model (or null) to each child model
        foreach ($models as $model) {
            $fkVal = $model->getAttribute($this->foreignKey);
            $model->setRelation($relationName, $keyed[$fkVal] ?? null);
        }
    }
}
