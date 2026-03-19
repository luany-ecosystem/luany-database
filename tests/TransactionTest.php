<?php

namespace Luany\Database\Tests;

use Luany\Database\Connection;

class TransactionTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, balance REAL)');
        $this->pdo->exec("INSERT INTO accounts (name, balance) VALUES ('Antonio', 100.00), ('Ambrosio', 50.00)");
    }

    // ── beginTransaction / commit ────────────────────────────────────────────

    public function test_commit_persists_changes(): void
    {
        $this->connection->beginTransaction();
        $this->connection->execute("UPDATE accounts SET balance = 80.00 WHERE name = 'Antonio'");
        $this->connection->commit();

        $stmt = $this->connection->execute("SELECT balance FROM accounts WHERE name = 'Antonio'");
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(80.0, (float) $row['balance']);
    }

    // ── rollBack ─────────────────────────────────────────────────────────────

    public function test_rollback_reverts_changes(): void
    {
        $this->connection->beginTransaction();
        $this->connection->execute("UPDATE accounts SET balance = 0 WHERE name = 'Antonio'");
        $this->connection->rollBack();

        $stmt = $this->connection->execute("SELECT balance FROM accounts WHERE name = 'Antonio'");
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(100.0, (float) $row['balance']);
    }

    // ── inTransaction ────────────────────────────────────────────────────────

    public function test_in_transaction_returns_correct_state(): void
    {
        $this->assertFalse($this->connection->inTransaction());

        $this->connection->beginTransaction();
        $this->assertTrue($this->connection->inTransaction());

        $this->connection->commit();
        $this->assertFalse($this->connection->inTransaction());
    }

    // ── transaction(callable) ────────────────────────────────────────────────

    public function test_transaction_commits_on_success(): void
    {
        $result = $this->connection->transaction(function (Connection $conn) {
            $conn->execute("UPDATE accounts SET balance = balance - 30 WHERE name = 'Antonio'");
            $conn->execute("UPDATE accounts SET balance = balance + 30 WHERE name = 'Ambrosio'");
            return 'transferred';
        });

        $this->assertSame('transferred', $result);

        $stmt = $this->connection->execute("SELECT balance FROM accounts WHERE name = 'Antonio'");
        $this->assertSame(70.0, (float) $stmt->fetch(\PDO::FETCH_ASSOC)['balance']);

        $stmt = $this->connection->execute("SELECT balance FROM accounts WHERE name = 'Ambrosio'");
        $this->assertSame(80.0, (float) $stmt->fetch(\PDO::FETCH_ASSOC)['balance']);
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        try {
            $this->connection->transaction(function (Connection $conn) {
                $conn->execute("UPDATE accounts SET balance = balance - 30 WHERE name = 'Antonio'");
                throw new \RuntimeException('Transfer failed');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Transfer failed', $e->getMessage());
        }

        // Antonio's balance should be unchanged
        $stmt = $this->connection->execute("SELECT balance FROM accounts WHERE name = 'Antonio'");
        $this->assertSame(100.0, (float) $stmt->fetch(\PDO::FETCH_ASSOC)['balance']);
    }

    public function test_transaction_rethrows_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Oops');

        $this->connection->transaction(function () {
            throw new \RuntimeException('Oops');
        });
    }

    public function test_not_in_transaction_after_callable_failure(): void
    {
        try {
            $this->connection->transaction(function () {
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {}

        $this->assertFalse($this->connection->inTransaction());
    }
}
