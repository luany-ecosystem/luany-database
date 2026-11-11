<?php

namespace Luany\Database\Relations;

use Luany\Database\Model;

/**
 * MorphMany — polymorphic one-to-many.
 *
 *   comments: id, body, commentable_type, commentable_id
 *
 *   // Post and Video
 *   public function comments(): MorphMany
 *   {
 *       return $this->morphMany(Comment::class, 'commentable');
 *   }
 *
 *   $post->comments;
 *   $post->comments()->create(['body' => 'Nice']);
 */
class MorphMany extends MorphOneOrMany
{
    /**
     * @return array<int, Model>
     */
    public function getResults(): array
    {
        if ($this->localValue === null) {
            return [];
        }

        return array_map(
            fn (array $row) => ($this->relatedClass)::hydrateFromRow($row),
            $this->query()->where($this->idColumn, '=', $this->localValue)->get()
        );
    }

    /**
     * @param array<int, Model> $models
     */
    public function batchLoad(array $models, string $relationName): void
    {
        $grouped = $this->loadGrouped($models);

        foreach ($models as $model) {
            $value = $model->getAttribute($this->localKey);
            $model->setRelation($relationName, $value === null ? [] : ($grouped[(string) $value] ?? []));
        }
    }
}
