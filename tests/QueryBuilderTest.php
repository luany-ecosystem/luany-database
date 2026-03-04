<?php

namespace Luany\Database\Tests;

use Luany\Database\QueryBuilder;
use Luany\Database\Result;

class QueryBuilderTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUpSchema(): void
    {
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER)');
        $this->pdo->exec("INSERT INTO items (name, qty) VALUES ('apple', 10), ('banana', 5), ('cherry', 20)");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->qb = new QueryBuilder($this->connection);
    }

    public function test_query_returns_result_instance(): void
    {
        $result = $this->qb->query('SELECT * FROM items');
        $this->assertInstanceOf(Result::class, $result);
    }

    public function test_query_fetchAll_returns_all_rows(): void
    {
        $rows = $this->qb->query('SELECT * FROM items')->fetchAll();
        $this->assertCount(3, $rows);
    }

    public function test_query_with_binding_filters_rows(): void
    {
        $rows = $this->qb->query('SELECT * FROM items WHERE name = ?', ['apple'])->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('apple', $rows[0]['name']);
    }

    public function test_query_fetchOne_returns_single_row(): void
    {
        $row = $this->qb->query('SELECT * FROM items WHERE name = ?', ['banana'])->fetchOne();
        $this->assertNotNull($row);
        $this->assertSame('banana', $row['name']);
    }

    public function test_query_fetchOne_returns_null_when_no_match(): void
    {
        $row = $this->qb->query('SELECT * FROM items WHERE name = ?', ['mango'])->fetchOne();
        $this->assertNull($row);
    }

    public function test_statement_insert_returns_affected_rows(): void
    {
        $affected = $this->qb->statement('INSERT INTO items (name, qty) VALUES (?, ?)', ['mango', 3]);
        $this->assertSame(1, $affected);
    }

    public function test_statement_update_returns_affected_rows(): void
    {
        $affected = $this->qb->statement('UPDATE items SET qty = ? WHERE name = ?', [99, 'apple']);
        $this->assertSame(1, $affected);
    }

    public function test_statement_delete_returns_affected_rows(): void
    {
        $affected = $this->qb->statement('DELETE FROM items WHERE name = ?', ['cherry']);
        $this->assertSame(1, $affected);
    }

    public function test_lastInsertId_after_insert(): void
    {
        $this->qb->statement('INSERT INTO items (name, qty) VALUES (?, ?)', ['pear', 7]);
        $this->assertSame('4', $this->qb->lastInsertId());
    }
}