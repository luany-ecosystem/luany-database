<?php

namespace Luany\Database\Tests;

use Luany\Database\Grammar;
use Luany\Database\QueryBuilder;

/**
 * Regression tests for identifier / operator injection (issue #2).
 */
class QueryBuilderSecurityTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUpSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE users (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                name     TEXT    NOT NULL,
                password TEXT    NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                city     TEXT
            )
        ');
        $this->pdo->exec("
            INSERT INTO users (name, password, is_admin, city) VALUES
            ('alice', 'secret-a', 0, 'Luanda'),
            ('bob',   'secret-b', 0, NULL)
        ");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->qb = new QueryBuilder($this->connection);
    }

    // ── Operators ────────────────────────────────────────────────────────────

    public function test_operator_injection_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users')->where('id', '= 0 OR 1=1 OR id =', 0)->get();
    }

    public function test_or_where_operator_injection_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users')->orWhere('id', '= 0 OR 1=1 --', 0);
    }

    public function test_allowed_operators_are_case_insensitive(): void
    {
        $rows = $this->qb->table('users')->where('name', 'like', 'ali%')->get();
        $this->assertCount(1, $rows);

        $rows = $this->qb->table('users')->where('name', 'not  like', 'ali%')->get();
        $this->assertCount(1, $rows);
    }

    // ── Columns ──────────────────────────────────────────────────────────────

    public function test_column_injection_in_where_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users')->where('id` = 0 OR 1=1 OR `id', '=', 0)->get();
    }

    public function test_column_injection_in_select_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users')
            ->select('name` , (SELECT group_concat(password) FROM users) AS `x')
            ->get();
    }

    public function test_column_injection_in_order_by_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users')->orderBy('(SELECT 1)')->get();
    }

    public function test_column_injection_in_where_in_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users')->whereIn('id) OR (1=1', [1]);
    }

    public function test_column_injection_in_where_null_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users')->whereNull('city IS NULL OR 1=1 OR city');
    }

    public function test_update_key_injection_is_rejected(): void
    {
        try {
            $this->qb->table('users')->where('id', '=', 1)->update(["name` = 'pwn', `is_admin" => 1]);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        $row = $this->qb->table('users')->where('id', '=', 1)->first();
        $this->assertSame(0, (int) $row['is_admin']);
        $this->assertSame('alice', $row['name']);
    }

    public function test_insert_key_injection_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users')->insert(['name`) VALUES (1); --' => 'x']);
    }

    public function test_table_injection_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('users` WHERE 1=1; --');
    }

    // ── Valid identifier forms still work ────────────────────────────────────

    public function test_qualified_and_aliased_columns(): void
    {
        $rows = $this->qb->table('users')
            ->select('users.id', 'name AS username')
            ->orderBy('users.id')
            ->get();

        $this->assertSame(['id' => 1, 'username' => 'alice'], $rows[0]);
    }

    public function test_table_star(): void
    {
        $rows = $this->qb->table('users')->select('users.*')->get();
        $this->assertCount(2, $rows);
    }

    // ── NULL comparisons ─────────────────────────────────────────────────────

    public function test_where_equals_null_compiles_to_is_null(): void
    {
        $rows = $this->qb->table('users')->where('city', '=', null)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('bob', $rows[0]['name']);
    }

    public function test_where_not_equals_null_compiles_to_is_not_null(): void
    {
        $rows = $this->qb->table('users')->where('city', '!=', null)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('alice', $rows[0]['name']);
    }

    // ── Escape hatches and grouping ──────────────────────────────────────────

    public function test_where_raw_with_bindings(): void
    {
        $rows = $this->qb->table('users')->whereRaw('LOWER(name) = ?', ['alice'])->get();
        $this->assertCount(1, $rows);
    }

    public function test_select_raw_bindings_precede_where_bindings(): void
    {
        $row = $this->qb->table('users')
            ->where('name', '=', 'bob')
            ->selectRaw('? AS tag', ['T'])
            ->first();

        $this->assertSame('T', $row['tag']);
    }

    public function test_where_nested_groups_or_conditions(): void
    {
        $rows = $this->qb->table('users')
            ->where('is_admin', '=', 1)
            ->whereNested(fn (QueryBuilder $q) => $q->where('name', '=', 'alice')->orWhere('name', '=', 'bob'))
            ->get();

        // Without parentheses this would be "is_admin = 1 AND name = alice OR name = bob" → 1 row.
        $this->assertCount(0, $rows);
    }

    public function test_where_not_in(): void
    {
        $rows = $this->qb->table('users')->whereNotIn('name', ['alice'])->get();
        $this->assertCount(1, $rows);

        $rows = $this->qb->table('users')->whereNotIn('name', [])->get();
        $this->assertCount(2, $rows);
    }

    // ── Grammar ──────────────────────────────────────────────────────────────

    public function test_grammar_quotes_per_driver(): void
    {
        $this->assertSame('`a`.`b`', (new Grammar('mysql'))->wrap('a.b'));
        $this->assertSame('"a"."b"', (new Grammar('pgsql'))->wrap('a.b'));
        $this->assertSame('`a` AS `b`', (new Grammar('sqlite'))->wrap('a as b'));
    }

    public function test_grammar_rejects_three_part_identifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Grammar())->wrap('a.b.c');
    }
}
