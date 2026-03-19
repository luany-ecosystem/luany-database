<?php

namespace Luany\Database\Tests;

use Luany\Database\PaginationResult;
use Luany\Database\QueryBuilder;

class PaginateTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUpSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE articles (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                title    TEXT NOT NULL,
                category TEXT NOT NULL,
                views    INTEGER NOT NULL DEFAULT 0
            )
        ');

        // 12 rows: enough to test multiple pages cleanly
        $rows = [
            ['Technology', 100], ['Technology', 200], ['Technology', 150],
            ['Sport',       50], ['Sport',       75], ['Sport',       60],
            ['Science',    300], ['Science',    250], ['Science',    400],
            ['Art',         10], ['Art',         20], ['Art',         30],
        ];
        foreach ($rows as $i => [$cat, $views]) {
            $n = $i + 1;
            $this->pdo->exec(
                "INSERT INTO articles (title, category, views) VALUES ('Article {$n}', '{$cat}', {$views})"
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->qb = new QueryBuilder($this->connection);
    }

    // ── Return type ───────────────────────────────────────────────────────────

    public function test_paginate_returns_PaginationResult(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 1);
        $this->assertInstanceOf(PaginationResult::class, $result);
    }

    // ── Basic pagination ──────────────────────────────────────────────────────

    public function test_paginate_first_page_returns_correct_count(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 1);
        $this->assertCount(5, $result->data);
    }

    public function test_paginate_second_page_returns_correct_count(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 2);
        $this->assertCount(5, $result->data);
    }

    public function test_paginate_last_page_returns_remaining_rows(): void
    {
        // 12 total, perPage=5 → page 3 has 2 rows
        $result = $this->qb->table('articles')->paginate(5, 3);
        $this->assertCount(2, $result->data);
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    public function test_paginate_total_is_correct(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 1);
        $this->assertSame(12, $result->total);
    }

    public function test_paginate_perPage_is_correct(): void
    {
        $result = $this->qb->table('articles')->paginate(4, 1);
        $this->assertSame(4, $result->perPage);
    }

    public function test_paginate_currentPage_is_correct(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 2);
        $this->assertSame(2, $result->currentPage);
    }

    public function test_paginate_lastPage_is_correct(): void
    {
        // 12 rows, perPage=5 → ceil(12/5) = 3
        $result = $this->qb->table('articles')->paginate(5, 1);
        $this->assertSame(3, $result->lastPage);
    }

    public function test_paginate_lastPage_exact_division(): void
    {
        // 12 rows, perPage=4 → lastPage=3
        $result = $this->qb->table('articles')->paginate(4, 1);
        $this->assertSame(3, $result->lastPage);
    }

    public function test_paginate_from_and_to_first_page(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 1);
        $this->assertSame(1, $result->from);
        $this->assertSame(5, $result->to);
    }

    public function test_paginate_from_and_to_second_page(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 2);
        $this->assertSame(6, $result->from);
        $this->assertSame(10, $result->to);
    }

    public function test_paginate_from_and_to_last_page(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 3);
        $this->assertSame(11, $result->from);
        $this->assertSame(12, $result->to);
    }

    // ── hasMore / hasPrev ─────────────────────────────────────────────────────

    public function test_paginate_hasMore_true_on_non_last_page(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 1);
        $this->assertTrue($result->hasMore());
    }

    public function test_paginate_hasMore_false_on_last_page(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 3);
        $this->assertFalse($result->hasMore());
    }

    public function test_paginate_hasPrev_false_on_first_page(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 1);
        $this->assertFalse($result->hasPrev());
    }

    public function test_paginate_hasPrev_true_on_second_page(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 2);
        $this->assertTrue($result->hasPrev());
    }

    // ── With WHERE clause ─────────────────────────────────────────────────────

    public function test_paginate_with_where_filters_total(): void
    {
        // Only 'Technology' rows (3 total)
        $result = $this->qb->table('articles')
            ->where('category', '=', 'Technology')
            ->paginate(2, 1);

        $this->assertSame(3, $result->total);
        $this->assertSame(2, $result->lastPage);
        $this->assertCount(2, $result->data);
    }

    public function test_paginate_with_where_second_page_has_correct_data(): void
    {
        $result = $this->qb->table('articles')
            ->where('category', '=', 'Technology')
            ->orderBy('id', 'ASC')
            ->paginate(2, 2);

        $this->assertCount(1, $result->data);
    }

    // ── With ORDER BY ─────────────────────────────────────────────────────────

    public function test_paginate_with_order_by_returns_sorted_data(): void
    {
        $result = $this->qb->table('articles')
            ->orderBy('views', 'DESC')
            ->paginate(3, 1);

        $views = array_column($result->data, 'views');
        $this->assertSame([400, 300, 250], array_map('intval', $views));
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function test_paginate_page_clamped_to_1_when_below_1(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 0);
        $this->assertSame(1, $result->currentPage);
    }

    public function test_paginate_page_clamped_to_1_for_negative(): void
    {
        $result = $this->qb->table('articles')->paginate(5, -3);
        $this->assertSame(1, $result->currentPage);
    }

    public function test_paginate_throws_when_perPage_less_than_1(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qb->table('articles')->paginate(0, 1);
    }

    public function test_paginate_throws_when_no_table_set(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->qb->paginate(10, 1);
    }

    // ── Empty result set ──────────────────────────────────────────────────────

    public function test_paginate_empty_result_has_correct_metadata(): void
    {
        $result = $this->qb->table('articles')
            ->where('category', '=', 'NonExistent')
            ->paginate(10, 1);

        $this->assertSame(0, $result->total);
        $this->assertSame(1, $result->lastPage);
        $this->assertSame([], $result->data);
        $this->assertNull($result->from);
        $this->assertNull($result->to);
        $this->assertFalse($result->hasMore());
    }

    // ── perPage larger than total ─────────────────────────────────────────────

    public function test_paginate_per_page_larger_than_total(): void
    {
        $result = $this->qb->table('articles')->paginate(100, 1);

        $this->assertSame(12, $result->total);
        $this->assertSame(1, $result->lastPage);
        $this->assertCount(12, $result->data);
        $this->assertSame(1, $result->from);
        $this->assertSame(12, $result->to);
    }

    // ── toArray() ─────────────────────────────────────────────────────────────

    public function test_pagination_result_to_array_has_all_keys(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 1);
        $array  = $result->toArray();

        $this->assertArrayHasKey('data',         $array);
        $this->assertArrayHasKey('total',        $array);
        $this->assertArrayHasKey('per_page',     $array);
        $this->assertArrayHasKey('current_page', $array);
        $this->assertArrayHasKey('last_page',    $array);
        $this->assertArrayHasKey('from',         $array);
        $this->assertArrayHasKey('to',           $array);
        $this->assertArrayHasKey('has_more',     $array);
        $this->assertArrayHasKey('has_prev',     $array);
    }

    public function test_pagination_result_to_array_values_match_properties(): void
    {
        $result = $this->qb->table('articles')->paginate(5, 2);
        $array  = $result->toArray();

        $this->assertSame($result->data,        $array['data']);
        $this->assertSame($result->total,       $array['total']);
        $this->assertSame($result->perPage,     $array['per_page']);
        $this->assertSame($result->currentPage, $array['current_page']);
        $this->assertSame($result->lastPage,    $array['last_page']);
        $this->assertSame($result->from,        $array['from']);
        $this->assertSame($result->to,          $array['to']);
        $this->assertSame($result->hasMore(),   $array['has_more']);
        $this->assertSame($result->hasPrev(),   $array['has_prev']);
    }
}
