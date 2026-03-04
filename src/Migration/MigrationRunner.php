<?php

namespace Luany\Database\Migration;

/**
 * MigrationRunner
 *
 * Executes pending migrations and handles rollbacks.
 * Pure engine — knows nothing about CLI, HTTP, or the framework.
 * Reports progress via an optional output callback.
 *
 * The CLI MigrateCommand calls this class directly.
 *
 * Usage:
 *   $runner = new MigrationRunner($pdo, '/path/to/database/migrations');
 *   $runner->run(function (string $name, string $status) {
 *       echo "  [{$status}] {$name}\n";
 *   });
 */
class MigrationRunner
{
    private MigrationRepository $repository;

    public function __construct(
        private \PDO $pdo,
        private string $migrationsPath
    ) {
        $this->repository = new MigrationRepository($pdo);
    }

    /**
     * Run all pending migrations.
     *
     * @param callable|null $output  fn(string $name, string $status): void
     * @return int  Number of migrations run
     */
    public function run(?callable $output = null): int
    {
        $this->repository->ensureTable();

        $ran     = $this->repository->getRan();
        $files   = $this->getFiles();
        $pending = array_filter($files, fn($f) => !in_array($this->nameFromFile($f), $ran, true));

        if (empty($pending)) {
            $output && $output('', 'nothing');
            return 0;
        }

        $batch = $this->repository->getLastBatch() + 1;
        $count = 0;

        foreach ($pending as $file) {
            $name      = $this->nameFromFile($file);
            $className = $this->classFromName($name);

            require_once $file;

            if (!class_exists($className)) {
                throw new \RuntimeException(
                    "Migration class [{$className}] not found in file [{$file}]."
                );
            }

            /** @var Migration $migration */
            $migration = new $className();

            if (!($migration instanceof Migration)) {
                throw new \RuntimeException(
                    "[{$className}] must extend Luany\\Database\\Migration\\Migration."
                );
            }

            $migration->up($this->pdo);
            $this->repository->record($name, $batch);
            $output && $output($name, 'migrated');
            $count++;
        }

        return $count;
    }

    /**
     * Roll back the last batch of migrations.
     *
     * @param callable|null $output  fn(string $name, string $status): void
     * @return int  Number of migrations rolled back
     */
    public function rollback(?callable $output = null): int
    {
        $this->repository->ensureTable();

        $lastBatch = $this->repository->getLastBatch();

        if ($lastBatch === 0) {
            $output && $output('', 'nothing');
            return 0;
        }

        $migrations = $this->repository->getBatch($lastBatch);
        $count      = 0;

        foreach ($migrations as $name) {
            $file      = $this->migrationsPath . '/' . $name . '.php';
            $className = $this->classFromName($name);

            if (!file_exists($file)) {
                throw new \RuntimeException(
                    "Migration file not found for rollback: [{$file}]."
                );
            }

            require_once $file;

            /** @var Migration $migration */
            $migration = new $className();
            $migration->down($this->pdo);
            $this->repository->delete($name);
            $output && $output($name, 'rolled back');
            $count++;
        }

        return $count;
    }

    /**
     * Return pending migration names without running them.
     *
     * @return string[]
     */
    public function pending(): array
    {
        $this->repository->ensureTable();
        $ran   = $this->repository->getRan();
        $files = $this->getFiles();
        return array_values(array_map(
            fn($f) => $this->nameFromFile($f),
            array_filter($files, fn($f) => !in_array($this->nameFromFile($f), $ran, true))
        ));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Return all .php migration files sorted by filename (= chronological order).
     *
     * @return string[]
     */
    private function getFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }
        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Derive the migration name from a full file path.
     * e.g. /path/to/2026_01_01_000000_create_users_table.php
     *   →  2026_01_01_000000_create_users_table
     */
    private function nameFromFile(string $file): string
    {
        return basename($file, '.php');
    }

    /**
     * Convert a migration filename to a class name.
     * e.g. 2026_01_01_000000_create_users_table → CreateUsersTable
     *
     * Strips the timestamp prefix (4 date parts) then PascalCases the rest.
     */
    private function classFromName(string $name): string
    {
        $parts = explode('_', $name);
        // Drop timestamp: YYYY_MM_DD_HHMMSS (4 parts)
        $parts = array_slice($parts, 4);
        return str_replace(' ', '', ucwords(implode(' ', $parts)));
    }
}