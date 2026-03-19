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

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a new Connection from a configuration array.
     *
     * @throws \RuntimeException on connection failure
     */
    public static function make(array $config): static
    {
        $host    = $config['host']     ?? '127.0.0.1';
        $port    = $config['port']     ?? '3306';
        $name    = $config['database'] ?? '';
        $user    = $config['username'] ?? 'root';
        $pass    = $config['password'] ?? '';
        $charset = $config['charset']  ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

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
     * Execute a prepared statement and return the statement.
     */
    public function execute(string $sql, array $bindings = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt;
    }

    /**
     * Get the last inserted row ID.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    // ── Transactions ───────────────────────────────────────────────────────────

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Execute a callback within a transaction.
     * Automatically commits on success, rolls back on exception.
     *
     * @template T
     * @param callable(Connection): T $callback
     * @return T
     * @throws \Throwable Re-throws after rollback
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Check if currently inside a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
