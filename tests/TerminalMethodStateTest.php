<?php

namespace Luany\Database\Tests;

use Luany\Database\Connection;
use Luany\Database\Model;
use Luany\Database\QueryBuilder;

class StateLeakProduct extends Model
{
    protected string $table    = 'products';
    protected array  $fillable = ['name'];
}

/**
 * Terminal methods (first(), find(), count(), paginate(), …) must not change
 * the builder they are called on, so it can be reused afterwards.
 */
class TerminalMethodStateTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec("INSERT INTO products (name) VALUES ('a'), ('b'), ('c')");
        StateLeakProduct::setConnection($this->connection);
    }

    public function test_query_builder_first_does_not_leave_a_limit_behind(): void
    {
        $qb = (new QueryBuilder($this->connection))->table('products')->orderBy('id');

        $this->assertSame('a', $qb->first()['name'] ?? null);
        $this->assertCount(3, $qb->get());
        $this->assertStringNotContainsString('LIMIT', $qb->toSql());
    }

    public function test_query_builder_first_keeps_an_explicit_offset(): void
    {
        $qb = (new QueryBuilder($this->connection))->table('products')->orderBy('id')->limit(2)->offset(1);

        $this->assertSame('b', $qb->first()['name'] ?? null);
        $this->assertCount(2, $qb->get());
    }

    public function test_other_terminal_methods_do_not_change_the_builder(): void
    {
        $qb  = (new QueryBuilder($this->connection))->table('products')->orderBy('id');
        $sql = $qb->toSql();

        $qb->count();
        $qb->exists();
        $qb->max('id');
        $qb->paginate(1, 2);
        $qb->chunk(1, fn () => null);
        $qb->chunkById(1, fn () => null);

        $this->assertSame($sql, $qb->toSql());
        $this->assertCount(3, $qb->get());
    }

    public function test_model_query_first_does_not_leave_a_limit_behind(): void
    {
        $query = StateLeakProduct::query()->orderBy('id');

        $this->assertSame('a', $query->first()?->name);
        $this->assertCount(3, $query->get());
    }

    public function test_model_query_find_does_not_leave_a_where_behind(): void
    {
        $query = StateLeakProduct::query();

        $this->assertSame('b', $query->find(2)?->name);
        $this->assertCount(3, $query->get());
    }
}
