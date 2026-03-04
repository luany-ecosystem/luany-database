<?php

namespace Luany\Database;

/**
 * QueryBuilder
 *
 * Thin prepared-statement wrapper used by Model.
 * Can also be used standalone for raw queries.
 *
 * All queries are executed as prepared statements — no raw interpolation.
 *
 * Usage (standalone):
 *   $qb    = new QueryBuilder($connection);
 *   $users = $qb->query('SELECT * FROM users WHERE active = ?', [1])->fetchAll();
 *   $user  = $qb->query('SELECT * FROM users WHERE id = ?', [$id])->fetchOne();
 */
class QueryBuilder
{
    public function __construct(private Connection $connection) {}

    /**
     * Execute a SELECT and return a Result.
     */
    public function query(string $sql, array $bindings = []): Result
    {
        $stmt = $this->connection->execute($sql, $bindings);
        return new Result($stmt);
    }

    /**
     * Execute an INSERT, UPDATE or DELETE.
     * Returns the number of affected rows.
     */
    public function statement(string $sql, array $bindings = []): int
    {
        $stmt = $this->connection->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Get the last inserted row ID.
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Get the underlying Connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}