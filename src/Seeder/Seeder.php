<?php

namespace Luany\Database\Seeder;

/**
 * Seeder
 *
 * Abstract base class for all seeder files.
 * Every seeder must implement run().
 *
 * Usage — database/seeders/UserSeeder.php:
 *
 *   class UserSeeder extends Seeder
 *   {
 *       public function run(\PDO $pdo): void
 *       {
 *           $stmt = $pdo->prepare("INSERT IGNORE INTO `users` (`name`, `email`) VALUES (?, ?)");
 *           $stmt->execute(['António Ngola', 'antonio@example.com']);
 *       }
 *   }
 */
abstract class Seeder
{
    protected \PDO $pdo;

    /**
     * Inject the PDO instance.
     * Called by SeederRunner before run() — not intended for manual use.
     */
    final public function setPdo(\PDO $pdo): static
    {
        $this->pdo = $pdo;
        return $this;
    }

    /**
     * Execute the seeder — insert records into the database.
     */
    abstract public function run(\PDO $pdo): void;

    /**
     * Call one or more seeders from within this seeder.
     * Typically used in DatabaseSeeder to chain seeders.
     *
     * All called seeders receive the same PDO instance.
     */
    protected function call(string ...$seederClasses): void
    {
        foreach ($seederClasses as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException(
                    "Seeder class [{$class}] not found. Ensure the file is in database/seeders/."
                );
            }

            $seeder = new $class();

            if (!($seeder instanceof self)) {
                throw new \RuntimeException(
                    "[{$class}] must extend Luany\\Database\\Seeder\\Seeder."
                );
            }

            $seeder->setPdo($this->pdo);
            $seeder->run($this->pdo);
        }
    }
}