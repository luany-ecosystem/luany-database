<?php

namespace Luany\Database\Tests;

use Luany\Database\QueryBuilder;

class FluentQueryBuilderTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUpSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE users (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                name  TEXT    NOT NULL,
                email TEXT    NOT NULL,
                age   INTEGER NOT NULL,
                city  TEXT
            )
        ');
        $this->pdo->exec("
            INSERT INTO users (name, email, age, city) VALUES
            ('Antonio',   'antonio@example.com',   30, 'Luanda'),
            ('Ambrosio',  'ambrosio@example.com',     25, 'Lisbon'),
            ('Ngola',     'ngola@example.com', 35, 'Luanda'),
            ('Edvania',   'edvania@example.com',   28, NULL),
            ('Dadiva',     'dadiva@example.com',     25, 'Porto')
        ");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->qb = new QueryBuilder($this->connection);
    }

    // ── table() ──────────────────────────────────────────────────────────────

    public function test_table_returns_fresh_builder(): void
    {
        $builder = $this->qb->table('users');
        $this->assertInstanceOf(QueryBuilder::class, $builder);
        $this->assertNotSame($this->qb, $builder);
    }

    public function test_throws_when_no_table_set(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->qb->get();
    }

    // ── get() ────────────────────────────────────────────────────────────────

    public function test_get_returns_all_rows(): void
    {
        $rows = $this->qb->table('users')->get();
        $this->assertCount(5, $rows);
    }

    // ── select() ─────────────────────────────────────────────────────────────

    public function test_select_specific_columns(): void
    {
        $rows = $this->qb->table('users')->select('name', 'email')->get();
        $this->assertCount(5, $rows);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('email', $rows[0]);
        $this->assertArrayNotHasKey('age', $rows[0]);
    }

    // ── where() ──────────────────────────────────────────────────────────────

    public function test_where_filters_rows(): void
    {
        $rows = $this->qb->table('users')->where('age', '>', 28)->get();
        $this->assertCount(2, $rows); // Antonio (30), Ngola (35)
    }

    public function test_where_chaining_uses_and(): void
    {
        $rows = $this->qb->table('users')
            ->where('age', '>=', 25)
            ->where('city', '=', 'Luanda')
            ->get();
        $this->assertCount(2, $rows); // Antonio, Ngola
    }

    public function test_where_with_equality(): void
    {
        $rows = $this->qb->table('users')->where('name', '=', 'Ambrosio')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Ambrosio', $rows[0]['name']);
    }

    // ── orWhere() ────────────────────────────────────────────────────────────

    public function test_or_where(): void
    {
        $rows = $this->qb->table('users')
            ->where('name', '=', 'Antonio')
            ->orWhere('name', '=', 'Dadiva')
            ->get();
        $this->assertCount(2, $rows);
    }

    // ── whereIn() ────────────────────────────────────────────────────────────

    public function test_where_in(): void
    {
        $rows = $this->qb->table('users')
            ->whereIn('name', ['Antonio', 'Ambrosio', 'Dadiva'])
            ->get();
        $this->assertCount(3, $rows);
    }

    public function test_where_in_empty_array_returns_nothing(): void
    {
        $rows = $this->qb->table('users')->whereIn('name', [])->get();
        $this->assertCount(0, $rows);
    }

    // ── whereNull / whereNotNull ─────────────────────────────────────────────

    public function test_where_null(): void
    {
        $rows = $this->qb->table('users')->whereNull('city')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Edvania', $rows[0]['name']);
    }

    public function test_where_not_null(): void
    {
        $rows = $this->qb->table('users')->whereNotNull('city')->get();
        $this->assertCount(4, $rows);
    }

    // ── orderBy() ────────────────────────────────────────────────────────────

    public function test_order_by_asc(): void
    {
        $rows = $this->qb->table('users')->orderBy('name', 'ASC')->get();
        $this->assertSame('Ambrosio', $rows[0]['name']);
        $this->assertSame('Ngola', $rows[4]['name']);
    }

    public function test_order_by_desc(): void
    {
        $rows = $this->qb->table('users')->orderBy('age', 'DESC')->get();
        $this->assertSame('Ngola', $rows[0]['name']); // age 35
    }

    public function test_order_by_multiple_columns(): void
    {
        $rows = $this->qb->table('users')
            ->orderBy('age', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();
        // age 25: Ambrosio, Dadiva → alphabetical
        $this->assertSame('Ambrosio', $rows[0]['name']);
        $this->assertSame('Dadiva', $rows[1]['name']);
    }

    // ── limit() / offset() ───────────────────────────────────────────────────

    public function test_limit(): void
    {
        $rows = $this->qb->table('users')->limit(2)->get();
        $this->assertCount(2, $rows);
    }

    public function test_limit_and_offset(): void
    {
        $rows = $this->qb->table('users')
            ->orderBy('id', 'ASC')
            ->limit(2)
            ->offset(2)
            ->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Ngola', $rows[0]['name']); // id=3
        $this->assertSame('Edvania', $rows[1]['name']);    // id=4
    }

    // ── first() ──────────────────────────────────────────────────────────────

    public function test_first_returns_single_row(): void
    {
        $row = $this->qb->table('users')->where('name', '=', 'Antonio')->first();
        $this->assertNotNull($row);
        $this->assertSame('Antonio', $row['name']);
    }

    public function test_first_returns_null_when_no_match(): void
    {
        $row = $this->qb->table('users')->where('name', '=', 'Nobody')->first();
        $this->assertNull($row);
    }

    // ── count() ──────────────────────────────────────────────────────────────

    public function test_count_all(): void
    {
        $this->assertSame(5, $this->qb->table('users')->count());
    }

    public function test_count_with_where(): void
    {
        $count = $this->qb->table('users')->where('city', '=', 'Luanda')->count();
        $this->assertSame(2, $count);
    }

    // ── exists() ─────────────────────────────────────────────────────────────

    public function test_exists_returns_true(): void
    {
        $this->assertTrue($this->qb->table('users')->where('name', '=', 'Antonio')->exists());
    }

    public function test_exists_returns_false(): void
    {
        $this->assertFalse($this->qb->table('users')->where('name', '=', 'Nobody')->exists());
    }

    // ── insert() ─────────────────────────────────────────────────────────────

    public function test_insert(): void
    {
        $result = $this->qb->table('users')->insert([
            'name'  => 'Frank',
            'email' => 'frank@example.com',
            'age'   => 40,
            'city'  => 'Benguela',
        ]);
        $this->assertTrue($result);
        $this->assertSame(6, $this->qb->table('users')->count());
    }

    // ── update() ─────────────────────────────────────────────────────────────

    public function test_update_returns_affected_count(): void
    {
        $affected = $this->qb->table('users')
            ->where('name', '=', 'Ambrosio')
            ->update(['city' => 'Madrid']);
        $this->assertSame(1, $affected);

        $row = $this->qb->table('users')->where('name', '=', 'Ambrosio')->first();
        $this->assertSame('Madrid', $row['city']);
    }

    public function test_update_multiple_rows(): void
    {
        $affected = $this->qb->table('users')
            ->where('city', '=', 'Luanda')
            ->update(['city' => 'Maputo']);
        $this->assertSame(2, $affected);
    }

    // ── delete() ─────────────────────────────────────────────────────────────

    public function test_delete_returns_affected_count(): void
    {
        $affected = $this->qb->table('users')->where('name', '=', 'Dadiva')->delete();
        $this->assertSame(1, $affected);
        $this->assertSame(4, $this->qb->table('users')->count());
    }

    public function test_delete_with_no_match_returns_zero(): void
    {
        $affected = $this->qb->table('users')->where('name', '=', 'Nobody')->delete();
        $this->assertSame(0, $affected);
    }

    // ── raw() ────────────────────────────────────────────────────────────────

    public function test_raw_executes_arbitrary_sql(): void
    {
        $result = $this->qb->raw('SELECT COUNT(*) as total FROM users');
        $row = $result->fetchOne();
        $this->assertSame(5, (int) $row['total']);
    }

    // ── State isolation ──────────────────────────────────────────────────────

    public function test_table_does_not_leak_state_between_calls(): void
    {
        // First query with where clause
        $this->qb->table('users')->where('name', '=', 'Antonio')->get();

        // Second query should not inherit previous where
        $rows = $this->qb->table('users')->get();
        $this->assertCount(5, $rows);
    }

    // ── Complex chaining ─────────────────────────────────────────────────────

    public function test_full_chain(): void
    {
        $rows = $this->qb->table('users')
            ->select('name', 'age')
            ->where('age', '>=', 25)
            ->where('age', '<=', 30)
            ->whereNotNull('city')
            ->orderBy('age', 'DESC')
            ->limit(2)
            ->get();

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('age', $rows[0]);
        $this->assertArrayNotHasKey('email', $rows[0]);
    }
}
