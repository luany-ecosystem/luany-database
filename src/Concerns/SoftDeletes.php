<?php

namespace Luany\Database\Concerns;

use Luany\Database\QueryBuilder;

/**
 * SoftDeletes
 *
 * Adds soft-delete capability to a Model. Instead of removing rows from the
 * database, it sets a `deleted_at` timestamp. Soft-deleted records are
 * automatically excluded from all standard queries.
 *
 * Usage:
 *   class Article extends Model
 *   {
 *       use SoftDeletes;
 *
 *       protected string $table    = 'articles';
 *       protected array  $fillable = ['title', 'body'];
 *   }
 *
 * Schema requirement:
 *   ALTER TABLE articles ADD COLUMN deleted_at DATETIME DEFAULT NULL;
 *
 * Methods added:
 *   $article->delete()        → sets deleted_at, does NOT remove the row
 *   $article->restore()       → clears deleted_at
 *   $article->forceDelete()   → permanently removes the row
 *   $article->trashed()       → true if soft-deleted
 *   Article::withTrashed()    → returns all records, including soft-deleted
 *   Article::onlyTrashed()    → returns only soft-deleted records
 *
 * Overrides:
 *   newQuery() → automatically adds WHERE deleted_at IS NULL to every standard query
 *   delete()   → soft-deletes instead of hard-deletes
 */
trait SoftDeletes
{
    /**
     * The column used to track soft deletions.
     * Override in the model to use a different column name.
     */
    protected string $deletedAtColumn = 'deleted_at';

    // ── newQuery override ──────────────────────────────────────────────────────

    /**
     * Override the base Model::newQuery() to exclude soft-deleted records
     * from all standard queries (find, all, where, count, etc.).
     */
    protected static function newQuery(): QueryBuilder
    {
        $instance = new static();
        return (new QueryBuilder(static::getConnection()))
            ->table($instance->table)
            ->whereNull($instance->deletedAtColumn);
    }

    /**
     * Unscoped query builder — includes soft-deleted records.
     * Used internally by withTrashed(), onlyTrashed(), restore(), forceDelete().
     */
    protected static function newQueryUnscoped(): QueryBuilder
    {
        $instance = new static();
        return (new QueryBuilder(static::getConnection()))
            ->table($instance->table);
    }

    // ── Instance methods ───────────────────────────────────────────────────────

    /**
     * Soft-delete this record: sets deleted_at to the current timestamp.
     * The row is NOT removed from the database.
     *
     * Overrides Model::delete().
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $timestamp = date('Y-m-d H:i:s');

        static::newQueryUnscoped()
            ->where($this->primaryKey, '=', $this->getAttribute($this->primaryKey))
            ->update([$this->deletedAtColumn => $timestamp]);

        $this->setAttribute($this->deletedAtColumn, $timestamp);

        return true;
    }

    /**
     * Permanently delete this record from the database.
     * Cannot be undone — use with care.
     */
    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        static::newQueryUnscoped()
            ->where($this->primaryKey, '=', $this->getAttribute($this->primaryKey))
            ->delete();

        $this->exists = false;

        return true;
    }

    /**
     * Restore a soft-deleted record by clearing deleted_at.
     */
    public function restore(): bool
    {
        static::newQueryUnscoped()
            ->where($this->primaryKey, '=', $this->getAttribute($this->primaryKey))
            ->update([$this->deletedAtColumn => null]);

        $this->setAttribute($this->deletedAtColumn, null);
        $this->exists = true;

        return true;
    }

    /**
     * Check whether this model instance has been soft-deleted.
     */
    public function trashed(): bool
    {
        return $this->getAttribute($this->deletedAtColumn) !== null;
    }

    // ── Static query methods ───────────────────────────────────────────────────

    /**
     * Return all records, including soft-deleted ones.
     *
     * @return static[]
     */
    public static function withTrashed(string $orderBy = ''): array
    {
        $query = static::newQueryUnscoped();

        if ($orderBy !== '') {
            static::validateOrderBy($orderBy);
            foreach (explode(',', $orderBy) as $term) {
                $parts  = preg_split('/\s+/', trim($term));
                $column = $parts[0];
                $dir    = strtoupper($parts[1] ?? 'ASC');
                $query  = $query->orderBy($column, $dir);
            }
        }

        return array_map(
            fn(array $row) => static::hydrateFromRow($row),
            $query->get()
        );
    }

    /**
     * Return only soft-deleted records.
     *
     * @return static[]
     */
    public static function onlyTrashed(string $orderBy = ''): array
    {
        $instance = new static();
        $query    = static::newQueryUnscoped()
            ->whereNotNull($instance->deletedAtColumn);

        if ($orderBy !== '') {
            static::validateOrderBy($orderBy);
            foreach (explode(',', $orderBy) as $term) {
                $parts  = preg_split('/\s+/', trim($term));
                $column = $parts[0];
                $dir    = strtoupper($parts[1] ?? 'ASC');
                $query  = $query->orderBy($column, $dir);
            }
        }

        return array_map(
            fn(array $row) => static::hydrateFromRow($row),
            $query->get()
        );
    }
}
