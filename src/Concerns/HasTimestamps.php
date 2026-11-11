<?php

namespace Luany\Database\Concerns;

/**
 * Automatic created_at / updated_at management (opt-in).
 *
 *   protected bool $timestamps = true;
 *
 * Column names can be changed per model:
 *
 *   public const CREATED_AT = 'inserted_on';
 *   public const UPDATED_AT = null;        // no "updated" column
 *
 * Timestamps are written by the model (format: $dateFormat), so they work the
 * same on MySQL, PostgreSQL and SQLite.
 *
 * @phpstan-require-extends \Luany\Database\Model
 */
trait HasTimestamps
{
    /** Whether the model manages created_at / updated_at. */
    protected bool $timestamps = false;

    public function usesTimestamps(): bool
    {
        return $this->timestamps;
    }

    public function getCreatedAtColumn(): ?string
    {
        return static::CREATED_AT;
    }

    public function getUpdatedAtColumn(): ?string
    {
        return static::UPDATED_AT;
    }

    /**
     * Current time as stored by this model.
     */
    public function freshTimestamp(): string
    {
        return (new \DateTimeImmutable())->format($this->dateFormat);
    }

    /**
     * Update the "updated at" column (only that column) and save.
     */
    public function touch(): bool
    {
        $column = $this->getUpdatedAtColumn();

        if (!$this->timestamps || $column === null) {
            return false;
        }

        $this->attributes[$column] = $this->freshTimestamp();

        return $this->save();
    }

    protected function applyInsertTimestamps(): void
    {
        if (!$this->timestamps) {
            return;
        }

        $now = $this->freshTimestamp();

        $created = $this->getCreatedAtColumn();
        if ($created !== null && !isset($this->attributes[$created])) {
            $this->attributes[$created] = $now;
        }

        $updated = $this->getUpdatedAtColumn();
        if ($updated !== null && !isset($this->attributes[$updated])) {
            $this->attributes[$updated] = $now;
        }
    }

    /**
     * @param array<string, mixed> $dirty
     * @return array<string, mixed>
     */
    protected function applyUpdateTimestamps(array $dirty): array
    {
        $updated = $this->getUpdatedAtColumn();

        if (!$this->timestamps || $updated === null || $dirty === [] || array_key_exists($updated, $dirty)) {
            return $dirty;
        }

        $this->attributes[$updated] = $this->freshTimestamp();
        $dirty[$updated] = $this->attributes[$updated];

        return $dirty;
    }
}
