<?php

namespace Luany\Database;

/**
 * Connection
 *
 * PDO connection factory and wrapper.
 * Does NOT enforce singleton — that responsibility belongs to
 * the application's DatabaseServiceProvider.
 *
 * Usage:
 *   $connection = Connection::make([
 *       'host'     => '127.0.0.1',
 *       'port'     => '3306',
 *       'database' => 'myapp',
 *       'username' => 'root',
 *       'password' => '',
 *       'charset'  => 'utf8mb4',
 *   ]);
 *
 *   $pdo = $connection->getPdo();
 */
class Connection
{
    private \PDO $pdo;

    private ?Grammar $grammar = null;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a new Connection from a configuration array.
     *
     * @throws \RuntimeException on connection failure
     */
    /** @param array<string, mixed> $config */
    public static function make(array $config): static
    {
        $driver  = (string) ($config['driver'] ?? 'mysql');
        $host    = $config['host']     ?? '127.0.0.1';
        $name    = $config['database'] ?? '';
        $user    = $config['username'] ?? ($driver === 'mysql' ? 'root' : null);
        $pass    = $config['password'] ?? ($driver === 'sqlite' ? null : '');

        $dsn = match ($driver) {
            'mysql'  => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host,
                $config['port'] ?? '3306',
                $name,
                $config['charset'] ?? 'utf8mb4'
            ),
            'pgsql'  => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $host,
                $config['port'] ?? '5432',
                $name
            ),
            'sqlite' => 'sqlite:' . ($name === '' ? ':memory:' : $name),
            default  => throw new \InvalidArgumentException(
                "Unsupported database driver [{$driver}]. Supported: mysql, pgsql, sqlite."
            ),
        };

        try {
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_STRINGIFY_FETCHES   => false,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return new static($pdo);
    }

    /**
     * Create a Connection from an existing PDO instance.
     * Useful for testing with an in-memory SQLite PDO.
     */
    public static function fromPdo(\PDO $pdo): static
    {
        return new static($pdo);
    }

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * PDO driver name ("mysql", "sqlite", "pgsql", ...).
     */
    public function getDriverName(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Grammar used to quote identifiers for this connection's driver.
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar ??= new Grammar($this->getDriverName());
    }

    /**
     * Execute a prepared statement and return the statement.
     */
    /** @param array<int|string, mixed> $bindings */
    public function execute(string $sql, array $bindings = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        if (!$this->typedIntegers()) {
            $stmt->execute($bindings);
            return $stmt;
        }

        // Bind integers as integers: SQLite compares an INTEGER with a TEXT
        // parameter as text (SUM(x) > '10' is always false).
        $position = 0;
        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? ++$position : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => \PDO::PARAM_INT,
                is_null($value) => \PDO::PARAM_NULL,
                default         => \PDO::PARAM_STR,
            };
            $stmt->bindValue($parameter, is_bool($value) ? (int) $value : $value, is_bool($value) ? \PDO::PARAM_INT : $type);
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * PostgreSQL infers parameter types from context (and rejects integers
     * for BOOLEAN columns), so typed integer binding is limited to MySQL and
     * SQLite.
     */
    private function typedIntegers(): bool
    {
        return in_array($this->getDriverName(), ['sqlite', 'mysql'], true);
    }

    /**
     * Get the last inserted row ID.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    // ── Transactions ───────────────────────────────────────────────────────────

    /** Current transaction nesting level (0 = none). */
    private int $transactionLevel = 0;

    /**
     * Begin a transaction, or a SAVEPOINT when one is already active.
     */
    public function beginTransaction(): bool
    {
        if ($this->transactionLevel === 0) {
            $ok = $this->pdo->inTransaction() ? true : $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $this->savepoint($this->transactionLevel + 1));
            $ok = true;
        }

        $this->transactionLevel++;
        return $ok;
    }

    /**
     * Commit the transaction, or release the innermost SAVEPOINT.
     */
    public function commit(): bool
    {
        if ($this->transactionLevel <= 1) {
            $this->transactionLevel = 0;
            return $this->pdo->inTransaction() ? $this->pdo->commit() : true;
        }

        $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint($this->transactionLevel));
        $this->transactionLevel--;
        return true;
    }

    /**
     * Roll back the transaction, or to the innermost SAVEPOINT.
     */
    public function rollBack(): bool
    {
        if ($this->transactionLevel <= 1) {
            $this->transactionLevel = 0;
            return $this->pdo->inTransaction() ? $this->pdo->rollBack() : true;
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $this->savepoint($this->transactionLevel));
        $this->transactionLevel--;
        return true;
    }

    /**
     * Current nesting level (0 when no transaction is active).
     */
    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Run $callback inside a transaction. Nested calls use savepoints, so an
     * inner failure only undoes the inner work when the caller catches it.
     *
     * The original exception is always rethrown — a failure while rolling
     * back (e.g. after an implicit DDL commit on MySQL) never masks it.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->rollBack();
            } catch (\Throwable) {
                // Keep the original exception; the transaction is already gone.
                $this->transactionLevel = max(0, $this->transactionLevel - 1);
            }
            throw $e;
        }
    }

    private function savepoint(int $level): string
    {
        return 'luany_sp_' . $level;
    }

    /**
     * Check if currently inside a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}