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

    /**
     * Loaded migration instances keyed by real file path. Static because a
     * file can only be required once per process (require_once).
     *
     * @var array<string, Migration>
     */
    private static array $instances = [];

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
            $migration = $this->resolve($file);

            $this->runInTransaction(function () use ($migration, $name, $batch): void {
                $migration->up($this->pdo);
                $this->repository->record($name, $batch);
            });

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
    public function rollback(?callable $output = null, int $steps = 1): int
    {
        $this->repository->ensureTable();

        $count = 0;

        for ($i = 0; $i < max(1, $steps); $i++) {
            $lastBatch = $this->repository->getLastBatch();

            if ($lastBatch === 0) {
                break;
            }

            foreach ($this->repository->getBatch($lastBatch) as $name) {
                $file = $this->migrationsPath . '/' . $name . '.php';

                if (!file_exists($file)) {
                    throw new \RuntimeException(
                        "Migration file not found for rollback: [{$file}]."
                    );
                }

                $migration = $this->resolve($file);

                $this->runInTransaction(function () use ($migration, $name): void {
                    $migration->down($this->pdo);
                    $this->repository->delete($name);
                });

                $output && $output($name, 'rolled back');
                $count++;
            }
        }

        if ($count === 0) {
            $output && $output('', 'nothing');
        }

        return $count;
    }

    /**
     * Roll back every batch that has run.
     */
    public function reset(?callable $output = null): int
    {
        $this->repository->ensureTable();
        return $this->rollback($output, PHP_INT_MAX);
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

    /**
     * Return the status of all migrations — ran or pending.
     *
     * @return array<int, array{name: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->repository->ensureTable();

        $ran    = $this->repository->getRan();
        $files  = $this->getFiles();
        $result = [];

        // Build a map of name → batch from the repository
        $batches = $this->repository->getBatches();

        foreach ($files as $file) {
            $name      = $this->nameFromFile($file);
            $hasRun    = in_array($name, $ran, true);
            $result[]  = [
                'name'  => $name,
                'ran'   => $hasRun,
                'batch' => $hasRun ? ($batches[$name] ?? null) : null,
            ];
        }

        return $result;
    }

    /**
     * Drop all user tables from the database.
     * Used by migrate:fresh — wipes the schema before re-running all migrations.
     */
    public function dropAll(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $grammar = new \Luany\Database\Grammar($driver);

        $tables = match ($driver) {
            // sqlite_sequence & co. are internal and cannot be dropped.
            'sqlite' => $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
                            ->fetchAll(\PDO::FETCH_COLUMN),
            'pgsql'  => $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema()")
                            ->fetchAll(\PDO::FETCH_COLUMN),
            default  => $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN),
        };

        if (empty($tables)) {
            return;
        }

        if ($driver === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        } elseif ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($tables as $table) {
                $suffix = $driver === 'pgsql' ? ' CASCADE' : '';
                $pdo->exec('DROP TABLE IF EXISTS ' . $grammar->wrapTable((string) $table) . $suffix);
            }
        } finally {
            if ($driver === 'mysql') {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($driver === 'sqlite') {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
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
     * Strips every leading all-digit segment (any timestamp format), then
     * PascalCases the rest.
     */
    private function classFromName(string $name): string
    {
        $parts = explode('_', $name);
        while ($parts !== [] && ctype_digit($parts[0])) {
            array_shift($parts);
        }
        return str_replace(' ', '', ucwords(implode(' ', $parts)));
    }

    /**
     * Load a migration file and return its instance.
     *
     * Supports both styles:
     *   - anonymous:  return new class extends Migration { ... };   (recommended — no name collisions)
     *   - named:      class CreateUsersTable extends Migration { ... }
     */
    private function resolve(string $file): Migration
    {
        $key = realpath($file) ?: $file;

        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        $className = $this->classFromName($this->nameFromFile($file));

        if (class_exists($className, false)) {
            $declaredIn = (new \ReflectionClass($className))->getFileName();
            if ($declaredIn !== false && realpath($declaredIn) !== $key) {
                throw new \RuntimeException(
                    "Migration class [{$className}] in [{$file}] is already declared in [{$declaredIn}]. "
                    . 'Rename one of the classes or use an anonymous migration (return new class extends Migration { ... };).'
                );
            }
        }

        $returned = require_once $file;

        if ($returned instanceof Migration) {
            return self::$instances[$key] = $returned;
        }

        if (!class_exists($className)) {
            throw new \RuntimeException(
                "Migration class [{$className}] not found in file [{$file}]. "
                . 'Declare it, or return an anonymous class extending Migration.'
            );
        }

        if (!is_subclass_of($className, Migration::class)) {
            throw new \RuntimeException(
                "[{$className}] must extend Luany\\Database\\Migration\\Migration."
            );
        }

        return self::$instances[$key] = new $className();
    }

    /**
     * Run a migration step atomically where the driver supports
     * transactional DDL (SQLite, PostgreSQL). MySQL commits DDL implicitly,
     * so wrapping it would only hide errors.
     */
    private function runInTransaction(callable $step): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if (!in_array($driver, ['sqlite', 'pgsql'], true) || $this->pdo->inTransaction()) {
            $step();
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $step();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            // The migration itself may have committed/rolled back already.
            if ($this->pdo->inTransaction()) { // @phpstan-ignore if.alwaysFalse
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}