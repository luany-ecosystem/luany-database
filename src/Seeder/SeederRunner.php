<?php

namespace Luany\Database\Seeder;

/**
 * SeederRunner
 *
 * Discovers and executes seeders from the seeders directory.
 * Pure engine — knows nothing about CLI, HTTP, or the framework.
 * Reports progress via an optional output callback.
 *
 * Usage:
 *   $runner = new SeederRunner($pdo, '/path/to/database/seeders');
 *   $runner->run('DatabaseSeeder', function (string $class, string $status) {
 *       echo "  [{$status}] {$class}\n";
 *   });
 */
class SeederRunner
{
    public function __construct(
        private \PDO $pdo,
        private string $seedersPath
    ) {}

    /**
     * Run the given seeder class.
     * Loads all PHP files in the seeders directory before executing
     * so that call() chains inside DatabaseSeeder always resolve.
     *
     * @param string        $seederClass  Fully unqualified class name (e.g. 'DatabaseSeeder')
     * @param callable|null $output       fn(string $class, string $status): void
     */
    public function run(string $seederClass = 'DatabaseSeeder', ?callable $output = null): void
    {
        $this->loadAll();

        if (!class_exists($seederClass)) {
            throw new \RuntimeException(
                "Seeder class [{$seederClass}] not found in [{$this->seedersPath}]."
            );
        }

        $seeder = new $seederClass();

        if (!($seeder instanceof Seeder)) {
            throw new \RuntimeException(
                "[{$seederClass}] must extend Luany\\Database\\Seeder\\Seeder."
            );
        }

        $seeder->setPdo($this->pdo);
        $seeder->run($this->pdo);

        $output && $output($seederClass, 'seeded');
    }

    /**
     * Load all PHP files in the seeders directory.
     * This ensures every class referenced via call() is available
     * before any seeder runs.
     */
    private function loadAll(): void
    {
        if (!is_dir($this->seedersPath)) {
            return;
        }

        foreach (glob($this->seedersPath . '/*.php') ?: [] as $file) {
            require_once $file;
        }
    }
}