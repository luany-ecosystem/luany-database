<?php

namespace Luany\Database\Relations;

use Luany\Database\Connection;
use Luany\Database\Model;
use Luany\Database\QueryBuilder;

/**
 * HasMany
 *
 * Represents a one-to-many relationship where the FK lives on the RELATED table.
 *
 * Example:
 *   A User hasMany Posts.
 *   The FK `user_id` lives on the `posts` table.
 *
 * Usage in model:
 *   public function posts(): HasMany
 *   {
 *       return $this->hasMany(Post::class, 'user_id');
 *   }
 *
 * Access:
 *   $user->posts                  // lazy: one query
 *   User::with('posts')->all()    // eager: one batched query for all users
 */
class HasMany extends Relation
{
    /**
     * @param Connection $connection   Shared DB connection
     * @param string     $relatedClass FQCN of the related model (e.g. App\Models\Post)
     * @param string     $foreignKey   FK column on the RELATED table (e.g. 'user_id')
     * @param string     $localKey     PK column on THIS (parent) table (e.g. 'id')
     * @param mixed      $localValue   Actual local key value of THIS model instance (for lazy load)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $relatedClass,
        private readonly string $foreignKey,
        private readonly string $localKey,
        private readonly mixed $localValue,
    ) {}

    /**
     * Lazy-load: one query to get all related records for this parent.
     *
     * @return Model[]
     */
    public function getResults(): array
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedClass();

        $rows = (new QueryBuilder($this->connection))
            ->table($relatedInstance->getTable())
            ->where($this->foreignKey, '=', $this->localValue)
            ->get();

        return array_map(
            fn(array $row) => ($this->relatedClass)::hydrateFromRow($row),
            $rows
        );
    }

    /**
     * Eager-load: ONE query with WHERE IN, then group results by FK value.
     *
     * N+1 eliminated: instead of 1 query per parent, we do 1 query total.
     *
     * @param Model[] $models
     */
    public function batchLoad(array $models, string $relationName): void
    {
        // Collect all unique, non-null local key values from parent models
        $localValues = array_values(array_unique(array_filter(
            array_map(fn(Model $m) => $m->getAttribute($this->localKey), $models),
            fn($v) => $v !== null,
        )));

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedClass();

        // Map: localKeyValue → array of related model instances
        $grouped = [];

        if (!empty($localValues)) {
            $rows = (new QueryBuilder($this->connection))
                ->table($relatedInstance->getTable())
                ->whereIn($this->foreignKey, $localValues)
                ->get();

            foreach ($rows as $row) {
                $fkVal           = $row[$this->foreignKey];
                $grouped[$fkVal][] = ($this->relatedClass)::hydrateFromRow($row);
            }
        }

        // Assign result array (or empty array) to each parent model
        foreach ($models as $model) {
            $localVal = $model->getAttribute($this->localKey);
            $model->setRelation($relationName, $grouped[$localVal] ?? []);
        }
    }
}
