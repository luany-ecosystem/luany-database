<?php

namespace Luany\Database\Tests;

use Luany\Database\Migration\MigrationRepository;
use Luany\Database\Migration\MigrationRunner;

class MigrationRunnerTest extends TestCase
{
    private string $migrationsPath;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrationsPath = sys_get_temp_dir() . '/luany_db_test_' . uniqid();
        $this->suffix         = substr(md5($this->migrationsPath), 0, 6);
        mkdir($this->migrationsPath, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsPath . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->migrationsPath);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $this->migrationsPath);
    }

    /**
     * Unique filename and class per test run — avoids PHP "Cannot redeclare class".
     * Suffix is derived from the unique migrationsPath of each test.
     *
     * e.g. base filename: 2026_01_01_000001_create_alpha_table.php
     *      unique file:   2026_01_01_000001_create_alpha_table_a1b2c3.php
     *      unique class:  CreateAlphaTableA1b2c3
     */
    private function writeMigration(string $filename, string $base): void
    {
        $uniqueFilename = preg_replace('/\.php$/', "_{$this->suffix}.php", $filename);
        $uniqueClass    = str_replace('_', '', ucwords($base, '_')) . ucfirst($this->suffix);
        $table          = strtolower(str_replace('_', '', $base));

        file_put_contents($this->migrationsPath . '/' . $uniqueFilename, <<<PHP
        <?php
        use Luany\Database\Migration\Migration;
        class {$uniqueClass} extends Migration {
            public function up(\PDO \$pdo): void  { \$pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (id INTEGER PRIMARY KEY)"); }
            public function down(\PDO \$pdo): void { \$pdo->exec("DROP TABLE IF EXISTS `{$table}`"); }
        }
        PHP);
    }

    private function migrationName(string $filename): string
    {
        return basename(preg_replace('/\.php$/', "_{$this->suffix}.php", $filename), '.php');
    }

    // ── run() ─────────────────────────────────────────────────────────────────

    public function test_run_returns_zero_when_no_migrations(): void
    {
        $this->assertSame(0, $this->runner()->run());
    }

    public function test_run_executes_pending_migrations(): void
    {
        $this->writeMigration('2026_01_01_000001_create_alpha_table.php', 'create_alpha_table');
        $this->writeMigration('2026_01_01_000002_create_beta_table.php',  'create_beta_table');

        $this->assertSame(2, $this->runner()->run());
    }

    public function test_run_creates_migrations_table(): void
    {
        $this->runner()->run();
        $result = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_migrations'");
        $this->assertNotFalse($result->fetch());
    }

    public function test_run_records_ran_migrations(): void
    {
        $this->writeMigration('2026_01_01_000001_create_alpha_table.php', 'create_alpha_table');
        $this->runner()->run();

        $repo = new MigrationRepository($this->pdo);
        $this->assertContains($this->migrationName('2026_01_01_000001_create_alpha_table.php'), $repo->getRan());
    }

    public function test_run_skips_already_ran_migrations(): void
    {
        $this->writeMigration('2026_01_01_000001_create_alpha_table.php', 'create_alpha_table');
        $runner = $this->runner();
        $runner->run();

        $this->assertSame(0, $runner->run());
    }

    public function test_run_calls_output_callback(): void
    {
        $this->writeMigration('2026_01_01_000001_create_alpha_table.php', 'create_alpha_table');

        $called = [];
        $this->runner()->run(function (string $name, string $status) use (&$called) {
            $called[] = compact('name', 'status');
        });

        $this->assertCount(1, $called);
        $this->assertSame('migrated', $called[0]['status']);
    }

    // ── rollback() ────────────────────────────────────────────────────────────

    public function test_rollback_returns_zero_when_nothing_to_rollback(): void
    {
        $this->assertSame(0, $this->runner()->rollback());
    }

    public function test_rollback_reverses_last_batch(): void
    {
        $this->writeMigration('2026_01_01_000001_create_alpha_table.php', 'create_alpha_table');
        $this->writeMigration('2026_01_01_000002_create_beta_table.php',  'create_beta_table');
        $runner = $this->runner();
        $runner->run();

        $this->assertSame(2, $runner->rollback());
    }

    public function test_rollback_removes_records_from_repository(): void
    {
        $this->writeMigration('2026_01_01_000001_create_alpha_table.php', 'create_alpha_table');
        $runner = $this->runner();
        $runner->run();
        $runner->rollback();

        $repo = new MigrationRepository($this->pdo);
        $this->assertNotContains($this->migrationName('2026_01_01_000001_create_alpha_table.php'), $repo->getRan());
    }

    // ── pending() ─────────────────────────────────────────────────────────────

    public function test_pending_returns_all_when_nothing_ran(): void
    {
        $this->writeMigration('2026_01_01_000001_create_alpha_table.php', 'create_alpha_table');
        $this->writeMigration('2026_01_01_000002_create_beta_table.php',  'create_beta_table');

        $this->assertCount(2, $this->runner()->pending());
    }

    public function test_pending_returns_empty_after_all_run(): void
    {
        $this->writeMigration('2026_01_01_000001_create_alpha_table.php', 'create_alpha_table');
        $runner = $this->runner();
        $runner->run();

        $this->assertSame([], $runner->pending());
    }

    // ── error handling ────────────────────────────────────────────────────────

    public function test_runner_throws_if_class_not_found_in_file(): void
    {
        file_put_contents(
            $this->migrationsPath . '/2026_01_01_000001_create_wrong_table.php',
            '<?php // empty — no class defined'
        );

        $this->expectException(\RuntimeException::class);
        $this->runner()->run();
    }
}