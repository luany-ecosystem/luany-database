<?php

namespace Luany\Database\Migration;

/**
 * MigrationRepository
 *
 * Manages the _migrations tracking table.
 * Responsible only for recording which migrations have run
 * and in which batch — no execution logic here.
 */
class MigrationRepository
{
    private const TABLE = '_migrations';

    public function __construct(private \PDO $pdo) {}

    /**
     * Create the _migrations table if it does not exist.
     */
    public function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS `_migrations` (
                    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
                    `migration` TEXT NOT NULL,
                    `batch`     INTEGER NOT NULL,
                    `ran_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } elseif ($driver === 'pgsql') {
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS "_migrations" (
                    "id"        SERIAL PRIMARY KEY,
                    "migration" VARCHAR(255) NOT NULL,
                    "batch"     INTEGER NOT NULL,
                    "ran_at"    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ');
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS `_migrations` (
                    `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `migration` VARCHAR(255) NOT NULL,
                    `batch`     INT UNSIGNED NOT NULL,
                    `ran_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    /**
     * Return names of all migrations that have run.
     *
     * @return string[]
     */
    public function getRan(): array
    {
        $stmt = $this->pdo->query(
            "SELECT migration FROM " . $this->table() . " ORDER BY id ASC"
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Return all migrations in a given batch.
     *
     * @return string[]
     */
    public function getBatch(int $batch): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT migration FROM " . $this->table() . " WHERE batch = ? ORDER BY id DESC"
        );
        $stmt->execute([$batch]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Return the number of the last batch run.
     * Returns 0 if no migrations have run.
     */
    public function getLastBatch(): int
    {
        $row = $this->pdo->query(
            "SELECT COALESCE(MAX(batch), 0) FROM " . $this->table()
        )->fetchColumn();

        return (int) $row;
    }

    /**
     * Record a migration as having run in a given batch.
     */
    public function record(string $migration, int $batch): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO " . $this->table() . " (migration, batch) VALUES (?, ?)"
        );
        $stmt->execute([$migration, $batch]);
    }

    /**
     * Remove a migration record (used during rollback).
     */
    public function delete(string $migration): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM " . $this->table() . " WHERE migration = ?"
        );
        $stmt->execute([$migration]);
    }

    /**
     * Map of migration name → batch number.
     *
     * @return array<string, int>
     */
    public function getBatches(): array
    {
        $stmt = $this->pdo->query("SELECT migration, batch FROM " . $this->table() . " ORDER BY id ASC");
        $map  = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['migration']] = (int) $row['batch'];
        }
        return $map;
    }

    private function table(): string
    {
        $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        return (new \Luany\Database\Grammar($driver))->wrapTable(self::TABLE);
    }
}
