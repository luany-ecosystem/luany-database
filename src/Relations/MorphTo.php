<?php

namespace Luany\Database\Relations;

use Luany\Database\Connection;
use Luany\Database\Model;

/**
 * MorphTo — the owning side of a polymorphic relation.
 *
 *   // Comment (commentable_type, commentable_id)
 *   public function commentable(): MorphTo
 *   {
 *       return $this->morphTo();
 *   }
 *
 *   $comment->commentable;                        // Post or Video
 *   $comment->commentable()->associate($video);   // sets type/id (then save())
 *   Comment::with('commentable')->get();          // one query per type
 *
 * Types are stored as the model's morph class: its alias from
 * Model::morphMap(), or its class name.
 */
class MorphTo extends Relation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Model $child,
        private readonly string $typeColumn,
        private readonly string $idColumn,
        private readonly ?string $ownerKey = null,
    ) {}

    public function getResults(): ?Model
    {
        $type = $this->child->getAttribute($this->typeColumn);
        $id   = $this->child->getAttribute($this->idColumn);

        if ($type === null || $type === '' || $id === null) {
            return null;
        }

        $class = Model::resolveMorphClass((string) $type);
        $key   = $this->ownerKey ?? (new $class())->getPrimaryKey();
        $row   = $class::query()->toBase()->where($key, '=', $id)->first();

        return $row === null ? null : $class::hydrateFromRow($row);
    }

    /**
     * One query per distinct type.
     *
     * @param array<int, Model> $models
     */
    public function batchLoad(array $models, string $relationName): void
    {
        $idsByType = [];
        foreach ($models as $model) {
            $type = $model->getAttribute($this->typeColumn);
            $id   = $model->getAttribute($this->idColumn);
            if ($type !== null && $type !== '' && $id !== null) {
                $idsByType[(string) $type][] = $id;
            }
        }

        $owners = [];
        foreach ($idsByType as $type => $ids) {
            $class = Model::resolveMorphClass($type);
            $key   = $this->ownerKey ?? (new $class())->getPrimaryKey();

            foreach ($class::query()->toBase()->whereIn($key, array_values(array_unique($ids)))->get() as $row) {
                $owners[$type][(string) $row[$key]] = $class::hydrateFromRow($row);
            }
        }

        foreach ($models as $model) {
            $type = (string) $model->getAttribute($this->typeColumn);
            $id   = (string) $model->getAttribute($this->idColumn);
            $model->setRelation($relationName, $owners[$type][$id] ?? null);
        }
    }

    /**
     * Point the child at $owner (type + id). Call save() on the child afterwards.
     */
    public function associate(?Model $owner): Model
    {
        $this->child->setAttribute($this->typeColumn, $owner?->getMorphClass());
        $this->child->setAttribute($this->idColumn, $owner?->getAttribute($this->ownerKey ?? $owner->getPrimaryKey()));

        return $this->child;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
