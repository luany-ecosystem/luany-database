<?php

namespace Luany\Database\Relations;

use Luany\Database\Connection;
use Luany\Database\Model;
use Luany\Database\QueryBuilder;

/**
 * HasOne
 *
 * Represents a one-to-one relationship where the FK lives on the RELATED table.
 *
 * Example:
 *   A User hasOne Profile.
 *   The FK `user_id` lives on the `profiles` table.
 *
 * Usage in model:
 *   public function profile(): HasOne
 *   {
 *       return $this->hasOne(Profile::class, 'user_id');
 *   }
 *
 * Access:
 *   $user->profile          // lazy: executes one query
 *   User::with('profile')->all()  // eager: one batched query for all users
 */
class HasOne extends Relation
{
    /**
     * @param Connection $connection  Shared DB connection
     * @param string     $relatedClass  FQCN of the related model (e.g. App\Models\Profile)
     * @param string     $foreignKey    FK column on the RELATED table (e.g. 'user_id')
     * @param string     $localKey      PK column on THIS (parent) table (e.g. 'id')
     * @param mixed      $localValue    Actual local key value of THIS model instance (for lazy load)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $relatedClass,
        private readonly string $foreignKey,
        private readonly string $localKey,
        private readonly mixed $localValue,
    ) {}

    /**
     * Lazy-load: execute one query to get the related record.
     */
    public function getResults(): ?Model
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedClass();

        $row = (new QueryBuilder($this->connection))
            ->table($relatedInstance->getTable())
            ->where($this->foreignKey, '=', $this->localValue)
            ->first();

        return $row !== null
            ? ($this->relatedClass)::hydrateFromRow($row)
            : null;
    }

    /**
     * Eager-load: ONE query via WHERE IN, then map results back to each parent.
     *
     * @param Model[] $models
     */
    public function batchLoad(array $models, string $relationName): void
    {
        // Collect all unique, non-null local key values
        $localValues = array_values(array_unique(array_filter(
            array_map(fn(Model $m) => $m->getAttribute($this->localKey), $models),
            fn($v) => $v !== null,
        )));

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedClass();

        // Map: localKeyValue → first matching related model
        $keyed = [];

        if (!empty($localValues)) {
            $rows = (new QueryBuilder($this->connection))
                ->table($relatedInstance->getTable())
                ->whereIn($this->foreignKey, $localValues)
                ->get();

            foreach ($rows as $row) {
                $fkVal = $row[$this->foreignKey];
                // HasOne: keep only the first match per FK value
                if (!isset($keyed[$fkVal])) {
                    $keyed[$fkVal] = ($this->relatedClass)::hydrateFromRow($row);
                }
            }
        }

        // Assign result (or null) to each parent model
        foreach ($models as $model) {
            $localVal = $model->getAttribute($this->localKey);
            $model->setRelation($relationName, $keyed[$localVal] ?? null);
        }
    }
}
