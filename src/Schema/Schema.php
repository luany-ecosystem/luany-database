<?php

namespace Luany\Database\Schema;

use Luany\Database\Connection;
use Luany\Database\Grammar;
use Luany\Database\Schema\Grammars\MySqlSchemaGrammar;
use Luany\Database\Schema\Grammars\PostgresSchemaGrammar;
use Luany\Database\Schema\Grammars\SchemaGrammar;
use Luany\Database\Schema\Grammars\SQLiteSchemaGrammar;

/**
 * Schema — portable DDL for MySQL, PostgreSQL and SQLite.
 *
 *   use Luany\Database\Schema\Schema;
 *   use Luany\Database\Schema\Blueprint;
 *
 *   return new class extends Migration {
 *       public function up(\PDO $pdo): void
 *       {
 *           Schema::for($pdo)->create('posts', function (Blueprint $table) {
 *               $table->id();
 *               $table->string('title');
 *               $table->timestamps();
 *           });
 *       }
 *
 *       public function down(\PDO $pdo): void
 *       {
 *           Schema::for($pdo)->dropIfExists('posts');
 *       }
 *   };
 */
final class Schema
{
    private SchemaGrammar $grammar;
    private string $driver;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $query        = new Grammar($this->driver);

        $this->grammar = match ($this->driver) {
            'mysql'  => new MySqlSchemaGrammar($query, $pdo),
            'pgsql'  => new PostgresSchemaGrammar($query, $pdo),
            'sqlite' => new SQLiteSchemaGrammar($query, $pdo),
            default  => throw new \InvalidArgumentException("Schema builder does not support the [{$this->driver}] driver."),
        };
    }

    public static function for(\PDO|Connection $connection): self
    {
        return new self($connection instanceof Connection ? $connection->getPdo() : $connection);
    }

    /** Raw SQL for a column default. Never build one from user input. */
    public static function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * @param \Closure(Blueprint): mixed $callback
     */
    public function create(string $table, \Closure $callback): void
    {
        $this->run($this->grammar->compileCreate($this->blueprint($table, $callback)));
    }

    /**
     * @param \Closure(Blueprint): mixed $callback
     */
    public function createIfNotExists(string $table, \Closure $callback): void
    {
        $this->run($this->grammar->compileCreate($this->blueprint($table, $callback), true));
    }

    /**
     * Alter an existing table (add / drop / rename columns, indexes, foreign keys).
     *
     * @param \Closure(Blueprint): mixed $callback
     */
    public function table(string $table, \Closure $callback): void
    {
        $this->run($this->grammar->compileAlter($this->blueprint($table, $callback)));
    }

    public function drop(string $table): void
    {
        $this->run([$this->grammar->compileDrop($table, false)]);
    }

    public function dropIfExists(string $table): void
    {
        $this->run([$this->grammar->compileDrop($table, true)]);
    }

    public function rename(string $from, string $to): void
    {
        $this->run([$this->grammar->compileRename($from, $to)]);
    }

    public function hasTable(string $table): bool
    {
        [$sql, $bindings] = match ($this->driver) {
            'sqlite' => ["SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]],
            'pgsql'  => ['SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?', [$table]],
            default  => ['SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]],
        };

        return $this->scalar($sql, $bindings) > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), array_map('strtolower', $this->getColumnListing($table)), true);
    }

    /**
     * @return list<string>
     */
    public function getColumnListing(string $table): array
    {
        if ($this->driver === 'sqlite') {
            $stmt = $this->pdo->query('PRAGMA table_info(' . (new Grammar('sqlite'))->wrapTable($table) . ')');
            return $stmt === false ? [] : array_map(fn (array $r) => (string) $r['name'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        $schema = $this->driver === 'pgsql' ? 'current_schema()' : 'DATABASE()';
        $stmt   = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = {$schema} AND table_name = ? ORDER BY ordinal_position");
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The DDL a create() would run (for previews and tests).
     *
     * @param \Closure(Blueprint): mixed $callback
     * @return list<string>
     */
    public function toSql(string $table, \Closure $callback, bool $alter = false): array
    {
        $blueprint = $this->blueprint($table, $callback);
        return $alter ? $this->grammar->compileAlter($blueprint) : $this->grammar->compileCreate($blueprint);
    }

    /**
     * @param \Closure(Blueprint): mixed $callback
     */
    private function blueprint(string $table, \Closure $callback): Blueprint
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        return $blueprint;
    }

    /**
     * @param list<string> $statements
     */
    private function run(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->pdo->exec($sql);
        }
    }

    /**
     * @param list<mixed> $bindings
     */
    private function scalar(string $sql, array $bindings): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return (int) $stmt->fetchColumn();
    }
}
