<?php

namespace Luany\Database\Tests\Seeder;

use Luany\Database\Seeder\Seeder;
use Luany\Database\Seeder\SeederRunner;
use Luany\Database\Tests\TestCase;

class SeederRunnerTest extends TestCase
{
    private string $seedersPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedersPath = sys_get_temp_dir() . '/luany_seeders_' . uniqid();
        mkdir($this->seedersPath, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->seedersPath . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->seedersPath);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function runner(): SeederRunner
    {
        return new SeederRunner($this->pdo, $this->seedersPath);
    }

    private function writeSeeder(string $class, string $sql = ''): void
    {
        $sql = $sql ?: "INSERT INTO `_seeder_log` (`entry`) VALUES ('{$class}')";

        file_put_contents($this->seedersPath . "/{$class}.php", <<<PHP
        <?php
        use Luany\Database\Seeder\Seeder;
        class {$class} extends Seeder {
            public function run(\\PDO \$pdo): void {
                \$pdo->exec("{$sql}");
            }
        }
        PHP);
    }

    private function ensureLogTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `_seeder_log` (
                `id`    INTEGER PRIMARY KEY AUTOINCREMENT,
                `entry` TEXT NOT NULL
            )
        ");
    }

    // ── run() ─────────────────────────────────────────────────────────────────

    public function test_run_executes_seeder(): void
    {
        $this->ensureLogTable();
        $this->writeSeeder('SimpleSeeder');

        $this->runner()->run('SimpleSeeder');

        $count = $this->pdo->query("SELECT COUNT(*) FROM `_seeder_log`")->fetchColumn();
        $this->assertSame('1', (string) $count);
    }

    public function test_run_calls_output_callback(): void
    {
        $this->ensureLogTable();
        $this->writeSeeder('CallbackSeeder');

        $called = [];
        $this->runner()->run('CallbackSeeder', function (string $class, string $status) use (&$called) {
            $called[] = compact('class', 'status');
        });

        $this->assertCount(1, $called);
        $this->assertSame('CallbackSeeder', $called[0]['class']);
        $this->assertSame('seeded', $called[0]['status']);
    }

    public function test_run_throws_if_class_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runner()->run('NonExistentSeeder');
    }

    public function test_run_throws_if_class_does_not_extend_seeder(): void
    {
        file_put_contents($this->seedersPath . '/BadSeeder.php', <<<'PHP'
        <?php
        class BadSeeder {
            public function run(\PDO $pdo): void {}
        }
        PHP);

        $this->expectException(\RuntimeException::class);
        $this->runner()->run('BadSeeder');
    }

    public function test_run_throws_if_seeders_directory_is_empty_and_class_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runner()->run('DatabaseSeeder');
    }

    // ── call() chaining ───────────────────────────────────────────────────────

    public function test_call_chains_seeders(): void
    {
        $this->ensureLogTable();

        // Child seeder
        $this->writeSeeder('ChildSeeder');

        // Parent seeder calls ChildSeeder via $this->call()
        file_put_contents($this->seedersPath . '/ParentSeeder.php', <<<'PHP'
        <?php
        use Luany\Database\Seeder\Seeder;
        class ParentSeeder extends Seeder {
            public function run(\PDO $pdo): void {
                $this->call(ChildSeeder::class);
                $pdo->exec("INSERT INTO `_seeder_log` (`entry`) VALUES ('ParentSeeder')");
            }
        }
        PHP);

        $this->runner()->run('ParentSeeder');

        $entries = $this->pdo->query("SELECT entry FROM `_seeder_log` ORDER BY id ASC")
                              ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['ChildSeeder', 'ParentSeeder'], $entries);
    }

    public function test_call_throws_if_chained_class_not_found(): void
    {
        $this->ensureLogTable();

        file_put_contents($this->seedersPath . '/BrokenParent.php', <<<'PHP'
        <?php
        use Luany\Database\Seeder\Seeder;
        class BrokenParent extends Seeder {
            public function run(\PDO $pdo): void {
                $this->call('GhostSeeder');
            }
        }
        PHP);

        $this->expectException(\RuntimeException::class);
        $this->runner()->run('BrokenParent');
    }

    // ── loadAll() ─────────────────────────────────────────────────────────────

    public function test_run_loads_all_files_in_directory(): void
    {
        $this->ensureLogTable();

        // Two seeders, parent calls child — both must be loaded before run
        $this->writeSeeder('ChildSeederB');

        file_put_contents($this->seedersPath . '/DatabaseSeeder.php', <<<'PHP'
        <?php
        use Luany\Database\Seeder\Seeder;
        class DatabaseSeeder extends Seeder {
            public function run(\PDO $pdo): void {
                $this->call(ChildSeederB::class);
            }
        }
        PHP);

        // Should not throw — ChildSeederB is loaded because loadAll() runs first
        $this->runner()->run('DatabaseSeeder');

        $count = $this->pdo->query("SELECT COUNT(*) FROM `_seeder_log`")->fetchColumn();
        $this->assertSame('1', (string) $count);
    }
}