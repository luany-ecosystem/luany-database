<?php

namespace Luany\Database\Migration;

/**
 * Migration
 *
 * Base class for all migration files.
 * Every migration must implement up() and down().
 *
 * Usage — database/migrations/2026_01_01_000000_create_users_table.php:
 *
 *   class CreateUsersTable extends Migration
 *   {
 *       public function up(\PDO $pdo): void
 *       {
 *           $pdo->exec("CREATE TABLE `users` (...)");
 *       }
 *
 *       public function down(\PDO $pdo): void
 *       {
 *           $pdo->exec("DROP TABLE IF EXISTS `users`");
 *       }
 *   }
 */
abstract class Migration
{
    /**
     * Run the migration — apply schema changes.
     */
    abstract public function up(\PDO $pdo): void;

    /**
     * Reverse the migration — undo schema changes.
     */
    abstract public function down(\PDO $pdo): void;
}