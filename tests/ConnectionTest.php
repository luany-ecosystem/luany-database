<?php

namespace Luany\Database\Tests;

use Luany\Database\Connection;

class ConnectionTest extends TestCase
{
    public function test_fromPdo_returns_connection_instance(): void
    {
        $this->assertInstanceOf(Connection::class, $this->connection);
    }

    public function test_getPdo_returns_the_pdo_instance(): void
    {
        $this->assertSame($this->pdo, $this->connection->getPdo());
    }

    public function test_execute_runs_a_prepared_statement(): void
    {
        $this->pdo->exec('CREATE TABLE t (v TEXT)');
        $stmt = $this->connection->execute('INSERT INTO t (v) VALUES (?)', ['hello']);
        $this->assertSame(1, $stmt->rowCount());
    }

    public function test_execute_select_returns_statement_with_rows(): void
    {
        $this->pdo->exec('CREATE TABLE t (v TEXT)');
        $this->pdo->exec("INSERT INTO t VALUES ('a'), ('b')");
        $stmt = $this->connection->execute('SELECT * FROM t');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
    }

    public function test_lastInsertId_returns_string(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $this->connection->execute('INSERT INTO t (v) VALUES (?)', ['x']);
        $this->assertSame('1', $this->connection->lastInsertId());
    }

    public function test_make_throws_on_invalid_dsn(): void
    {
        $this->expectException(\RuntimeException::class);
        Connection::make([
            'host'     => '127.0.0.1',
            'port'     => '9999',
            'database' => 'nonexistent_db_xyz',
            'username' => 'baduser',
            'password' => 'badpass',
        ]);
    }

    public function test_execute_with_no_bindings_works(): void
    {
        $this->pdo->exec('CREATE TABLE t (v TEXT)');
        $stmt = $this->connection->execute('SELECT * FROM t');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
    }

    public function test_fromPdo_preserves_existing_connection(): void
    {
        $pdo  = $this->pdo;
        $conn = Connection::fromPdo($pdo);
        $this->assertSame($pdo, $conn->getPdo());
    }
}