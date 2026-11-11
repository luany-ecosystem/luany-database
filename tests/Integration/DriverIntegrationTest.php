<?php

namespace Luany\Database\Tests\Integration;

use Luany\Database\Connection;
use Luany\Database\Migration\MigrationRunner;
use Luany\Database\Model;
use Luany\Database\QueryBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class IntegrationEvent extends Model
{
    protected string $table = 'lu_events';
    protected array $fillable = ['name', 'active', 'meta', 'starts_at'];
    protected bool $timestamps = true;
    protected array $casts = ['active' => 'bool', 'meta' => 'array', 'starts_at' => 'datetime'];

    public function scopeActive(\Luany\Database\ModelQuery $q): void
    {
        $q->where('active', '=', true);
    }
}

class IntegrationWidget extends Model
{
    protected string $table = 'lu_widgets';
    protected array $fillable = ['name', 'qty'];
}

/**
 * Runs against a real MySQL / PostgreSQL server when LUANY_DB_DRIVER is set
 * (see the "drivers" job in .github/workflows/ci.yml). Skipped otherwise.
 *
 *   LUANY_DB_DRIVER=pgsql LUANY_DB_HOST=127.0.0.1 LUANY_DB_PORT=5432 \
 *   LUANY_DB_NAME=luany LUANY_DB_USER=luany LUANY_DB_PASS=secret vendor/bin/phpunit --group integration
 */
#[Group('integration')]
class DriverIntegrationTest extends TestCase
{
    private Connection $conn;
    private string $driver;

    protected function setUp(): void
    {
        $driver = getenv('LUANY_DB_DRIVER') ?: '';
        if ($driver === '' || $driver === 'sqlite') {
            $this->markTestSkipped('Set LUANY_DB_DRIVER=mysql|pgsql to run driver integration tests.');
        }

        $this->driver = $driver;
        $this->conn   = Connection::make([
            'driver'   => $driver,
            'host'     => getenv('LUANY_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('LUANY_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('LUANY_DB_NAME') ?: 'luany',
            'username' => getenv('LUANY_DB_USER') ?: 'luany',
            'password' => getenv('LUANY_DB_PASS') ?: 'secret',
        ]);

        $pdo = $this->conn->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS lu_widgets');
        $pdo->exec($driver === 'pgsql'
            ? 'CREATE TABLE lu_widgets (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL, qty INTEGER NULL, is_admin INTEGER NOT NULL DEFAULT 0)'
            : 'CREATE TABLE lu_widgets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, qty INT NULL, is_admin INT NOT NULL DEFAULT 0) ENGINE=InnoDB');

        Model::setConnection($this->conn);
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->getPdo()->exec('DROP TABLE IF EXISTS lu_widgets');
        }
    }

    private function qb(): QueryBuilder
    {
        return (new QueryBuilder($this->conn))->table('lu_widgets');
    }

    public function test_driver_specific_quoting_round_trip(): void
    {
        $this->assertSame($this->driver, $this->conn->getDriverName());

        $this->qb()->insert(['name' => 'a', 'qty' => 1]);
        $this->qb()->insert(['name' => 'b', 'qty' => null]);

        $rows = $this->qb()->select('lu_widgets.id', 'name AS label')->orderByDesc('id')->get();
        $this->assertSame('b', $rows[0]['label']);

        $this->assertCount(1, $this->qb()->where('qty', '=', null)->get());
        $this->assertSame(1, $this->qb()->where('name', 'like', 'a%')->update(['qty' => 5]));
        $this->assertSame(2, $this->qb()->whereNested(
            fn (QueryBuilder $q) => $q->where('name', '=', 'a')->orWhere('name', '=', 'b')
        )->count());
    }

    public function test_injection_attempts_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb()->where('id', '= 0 OR 1=1 OR id =', 0)->get();
    }

    public function test_model_create_update_and_guarding(): void
    {
        $w = IntegrationWidget::create(['name' => 'x', 'qty' => 2, 'is_admin' => 1]);

        $this->assertIsInt($w->getKey());
        $this->assertSame(0, (int) $w->is_admin);

        $w->update(['qty' => 3]);
        $this->assertSame(3, (int) IntegrationWidget::find($w->getKey())->qty);
    }

    public function test_nested_transactions_with_savepoints(): void
    {
        $this->conn->transaction(function (Connection $c) {
            (new QueryBuilder($c))->table('lu_widgets')->insert(['name' => 'outer']);
            try {
                $c->transaction(function (Connection $inner) {
                    (new QueryBuilder($inner))->table('lu_widgets')->insert(['name' => 'inner']);
                    throw new \RuntimeException('inner');
                });
            } catch (\RuntimeException) {
            }
        });

        $names = array_column($this->qb()->orderBy('id')->get(), 'name');
        $this->assertSame(['outer'], $names);
    }

    public function test_migrations_and_drop_all(): void
    {
        $dir = sys_get_temp_dir() . '/luany_int_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $ddl = $this->driver === 'pgsql'
            ? 'CREATE TABLE lu_mig (id SERIAL PRIMARY KEY)'
            : 'CREATE TABLE lu_mig (id INT AUTO_INCREMENT PRIMARY KEY)';
        file_put_contents("{$dir}/2026_01_01_000000_create_lu_mig_table.php", <<<PHP
        <?php
        return new class extends \\Luany\\Database\\Migration\\Migration {
            public function up(\\PDO \$pdo): void  { \$pdo->exec('{$ddl}'); }
            public function down(\\PDO \$pdo): void { \$pdo->exec('DROP TABLE lu_mig'); }
        };
        PHP);

        $pdo    = $this->conn->getPdo();
        $runner = new MigrationRunner($pdo, $dir);

        try {
            $this->assertSame(1, $runner->run());
            $this->assertSame(1, $runner->rollback());
            $this->assertSame(1, $runner->run());

            $runner->dropAll($pdo);

            $tables = $this->driver === 'pgsql'
                ? $pdo->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()')->fetchAll(\PDO::FETCH_COLUMN)
                : $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame([], $tables);
        } finally {
            array_map('unlink', glob("{$dir}/*.php") ?: []);
            rmdir($dir);
        }
    }

    public function test_casts_timestamps_and_scopes_on_real_driver(): void
    {
        $pdo = $this->conn->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS lu_events');
        $pdo->exec($this->driver === 'pgsql'
            ? 'CREATE TABLE lu_events (id SERIAL PRIMARY KEY, name VARCHAR(50), active BOOLEAN, meta JSONB, starts_at TIMESTAMP, created_at TIMESTAMP, updated_at TIMESTAMP)'
            : 'CREATE TABLE lu_events (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), active TINYINT(1), meta JSON, starts_at DATETIME, created_at DATETIME, updated_at DATETIME) ENGINE=InnoDB');

        try {
            IntegrationEvent::create(['name' => 'on', 'active' => true, 'meta' => ['seats' => 3], 'starts_at' => new \DateTimeImmutable('2026-12-01 18:30:00')]);
            IntegrationEvent::create(['name' => 'off', 'active' => false]);

            $events = IntegrationEvent::active()->get();
            $this->assertCount(1, $events);

            $e = $events[0];
            $this->assertTrue($e->active);
            $this->assertSame(['seats' => 3], $e->meta);
            $this->assertSame('2026-12-01 18:30:00', $e->starts_at->format('Y-m-d H:i:s'));
            $this->assertNotNull($e->getRawAttribute('created_at'));

            $e->name = 'renamed';
            $this->assertTrue($e->save());
            $this->assertSame('renamed', IntegrationEvent::findOrFail($e->getKey())->name);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS lu_events');
        }
    }
}
