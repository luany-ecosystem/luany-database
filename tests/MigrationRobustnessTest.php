<?php

namespace Luany\Database\Tests;

use Luany\Database\Migration\MigrationRunner;

/**
 * Regression tests for issue #11 (migration engine robustness).
 */
class MigrationRobustnessTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/luany_db_robust_' . bin2hex(random_bytes(6));
        mkdir($this->path, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->path);
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $this->path);
    }

    private function anonymous(string $file, string $table, string $extraUp = ''): void
    {
        file_put_contents($this->path . '/' . $file, <<<PHP
        <?php
        use Luany\Database\Migration\Migration;
        return new class extends Migration {
            public function up(\PDO \$pdo): void  { \$pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT)"); {$extraUp} }
            public function down(\PDO \$pdo): void { \$pdo->exec("DROP TABLE IF EXISTS {$table}"); }
        };
        PHP);
    }

    private function tables(): array
    {
        return $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function test_anonymous_migrations_with_same_table_name_suffix_do_not_collide(): void
    {
        $this->anonymous('2026_01_01_000001_create_items_table.php', 'items_a');
        $this->anonymous('2026_01_02_000001_create_items_table.php', 'items_b');

        $this->assertSame(2, $this->runner()->run());
        $this->assertContains('items_a', $this->tables());
        $this->assertContains('items_b', $this->tables());

        $this->assertSame(2, $this->runner()->rollback());
        $this->assertNotContains('items_a', $this->tables());
    }

    public function test_failed_migration_is_rolled_back_and_not_recorded(): void
    {
        $this->anonymous('2026_01_01_000001_create_ok_table.php', 'ok_t');
        $this->anonymous(
            '2026_01_01_000002_create_bad_table.php',
            'bad_t',
            'throw new \RuntimeException("boom");'
        );

        try {
            $this->runner()->run();
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertContains('ok_t', $this->tables());
        $this->assertNotContains('bad_t', $this->tables(), 'Partial DDL must be rolled back');
        $this->assertSame(['create_bad_table'], array_map(
            fn ($n) => substr($n, 18),
            $this->runner()->pending()
        ));
    }

    public function test_rollback_steps_and_reset(): void
    {
        $this->anonymous('2026_01_01_000001_create_s1_table.php', 's1');
        $this->runner()->run();
        $this->anonymous('2026_01_01_000002_create_s2_table.php', 's2');
        $this->runner()->run();
        $this->anonymous('2026_01_01_000003_create_s3_table.php', 's3');
        $this->runner()->run();

        $this->assertSame(2, $this->runner()->rollback(null, 2));
        $this->assertContains('s1', $this->tables());
        $this->assertNotContains('s2', $this->tables());

        $this->runner()->run();
        $this->assertSame(3, $this->runner()->reset());
        $this->assertSame(['_migrations'], $this->tables());
    }

    public function test_drop_all_works_on_sqlite_with_autoincrement_tables(): void
    {
        $this->anonymous('2026_01_01_000001_create_d_table.php', 'd_t');
        $this->runner()->run();
        $this->pdo->exec('INSERT INTO d_t DEFAULT VALUES'); // populates sqlite_sequence

        $this->runner()->dropAll($this->pdo);

        $this->assertSame([], $this->tables());
    }

    public function test_class_from_name_handles_any_timestamp_format(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $class  = 'CreateWidgetsTable' . ucfirst($suffix);
        file_put_contents($this->path . "/20260101120000_create_widgets_table{$suffix}.php", <<<PHP
        <?php
        use Luany\Database\Migration\Migration;
        class {$class} extends Migration {
            public function up(\PDO \$pdo): void  { \$pdo->exec("CREATE TABLE widgets (id INTEGER PRIMARY KEY)"); }
            public function down(\PDO \$pdo): void { \$pdo->exec("DROP TABLE widgets"); }
        }
        PHP);

        $this->assertSame(1, $this->runner()->run());
        $this->assertContains('widgets', $this->tables());
    }

    public function test_duplicate_named_class_gives_clear_error(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $class  = 'CreateDupTable' . ucfirst($suffix);
        $body   = fn (string $t) => <<<PHP
        <?php
        use Luany\Database\Migration\Migration;
        class {$class} extends Migration {
            public function up(\PDO \$pdo): void  { \$pdo->exec("CREATE TABLE {$t} (id INTEGER PRIMARY KEY)"); }
            public function down(\PDO \$pdo): void {}
        }
        PHP;
        file_put_contents($this->path . "/2026_01_01_000001_create_dup_table{$suffix}.php", $body('dup1'));
        file_put_contents($this->path . "/2026_01_02_000001_create_dup_table{$suffix}.php", $body('dup2'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already declared');
        $this->runner()->run();
    }
}
