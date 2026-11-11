<?php

namespace Luany\Database\Relations;

use Luany\Database\Model;

/**
 * MorphOne — polymorphic one-to-one.
 *
 *   public function image(): MorphOne
 *   {
 *       return $this->morphOne(Image::class, 'imageable');
 *   }
 */
class MorphOne extends MorphOneOrMany
{
    public function getResults(): ?Model
    {
        if ($this->localValue === null) {
            return null;
        }

        $row = $this->query()->where($this->idColumn, '=', $this->localValue)->first();

        return $row === null ? null : ($this->relatedClass)::hydrateFromRow($row);
    }

    /**
     * @param array<int, Model> $models
     */
    public function batchLoad(array $models, string $relationName): void
    {
        $grouped = $this->loadGrouped($models);

        foreach ($models as $model) {
            $value = $model->getAttribute($this->localKey);
            $model->setRelation($relationName, $value === null ? null : ($grouped[(string) $value][0] ?? null));
        }
    }
}
