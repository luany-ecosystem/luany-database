<?php

namespace Luany\Database\Tests;

use Luany\Database\Concerns\SoftDeletes;
use Luany\Database\Model;

// ── Soft-deletable model stub ─────────────────────────────────────────────────

class ArticleModel extends Model
{
    use SoftDeletes;

    protected string $table    = 'articles';
    protected array  $fillable = ['title', 'body'];
}

// ── Test suite ────────────────────────────────────────────────────────────────

class SoftDeletesTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE articles (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                title      TEXT NOT NULL,
                body       TEXT,
                deleted_at DATETIME DEFAULT NULL
            )
        ');
        $this->pdo->exec("INSERT INTO articles (title, body) VALUES
            ('First',  'Body one'),
            ('Second', 'Body two'),
            ('Third',  'Body three')
        ");

        ArticleModel::setConnection($this->connection);
    }

    // ── Standard queries exclude soft-deleted ────────────────────────────────

    public function test_all_excludes_soft_deleted_records(): void
    {
        // Soft-delete article 1 directly in DB
        $this->pdo->exec("UPDATE articles SET deleted_at = '2026-01-01 00:00:00' WHERE id = 1");

        $articles = ArticleModel::all();
        $this->assertCount(2, $articles);
    }

    public function test_find_returns_null_for_soft_deleted(): void
    {
        $this->pdo->exec("UPDATE articles SET deleted_at = '2026-01-01 00:00:00' WHERE id = 1");
        $this->assertNull(ArticleModel::find(1));
    }

    public function test_count_excludes_soft_deleted(): void
    {
        $this->pdo->exec("UPDATE articles SET deleted_at = '2026-01-01 00:00:00' WHERE id = 1");
        $this->assertSame(2, ArticleModel::count());
    }

    // ── delete() soft-deletes instead of hard-deletes ────────────────────────

    public function test_delete_sets_deleted_at_timestamp(): void
    {
        $article = ArticleModel::find(1);
        $result  = $article->delete();

        $this->assertTrue($result);

        // Row still exists in the database
        $stmt = $this->pdo->query('SELECT deleted_at FROM articles WHERE id = 1');
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['deleted_at']);
    }

    public function test_delete_makes_record_invisible_to_standard_queries(): void
    {
        $article = ArticleModel::find(2);
        $article->delete();

        $this->assertNull(ArticleModel::find(2));
        $this->assertSame(2, ArticleModel::count()); // 3 total - 1 soft-deleted = 2
    }

    public function test_delete_returns_false_on_new_instance(): void
    {
        $article = new ArticleModel();
        $this->assertFalse($article->delete());
    }

    // ── trashed() ─────────────────────────────────────────────────────────────

    public function test_trashed_returns_false_when_not_deleted(): void
    {
        $article = ArticleModel::find(1);
        $this->assertFalse($article->trashed());
    }

    public function test_trashed_returns_true_after_soft_delete(): void
    {
        $article = ArticleModel::find(1);
        $article->delete();
        $this->assertTrue($article->trashed());
    }

    // ── restore() ────────────────────────────────────────────────────────────

    public function test_restore_clears_deleted_at(): void
    {
        $article = ArticleModel::find(1);
        $article->delete();

        $this->assertTrue($article->trashed());

        $article->restore();

        $this->assertFalse($article->trashed());
    }

    public function test_restore_makes_record_visible_again(): void
    {
        $article = ArticleModel::find(1);
        $article->delete();

        $this->assertNull(ArticleModel::find(1)); // invisible

        $article->restore();

        $this->assertNotNull(ArticleModel::find(1)); // visible again
    }

    // ── forceDelete() ─────────────────────────────────────────────────────────

    public function test_forceDelete_permanently_removes_row(): void
    {
        $article = ArticleModel::find(1);
        $result  = $article->forceDelete();

        $this->assertTrue($result);

        // Row must be gone from DB entirely
        $stmt = $this->pdo->query('SELECT COUNT(*) as cnt FROM articles WHERE id = 1');
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $row['cnt']);
    }

    public function test_forceDelete_returns_false_on_new_instance(): void
    {
        $article = new ArticleModel();
        $this->assertFalse($article->forceDelete());
    }

    // ── withTrashed() ─────────────────────────────────────────────────────────

    public function test_withTrashed_includes_soft_deleted(): void
    {
        $article = ArticleModel::find(1);
        $article->delete();

        $all = ArticleModel::withTrashed();
        $this->assertCount(3, $all); // all 3, including soft-deleted
    }

    public function test_withTrashed_returns_model_instances(): void
    {
        $all = ArticleModel::withTrashed();
        $this->assertContainsOnlyInstancesOf(ArticleModel::class, $all);
    }

    public function test_withTrashed_accepts_order_by(): void
    {
        $all = ArticleModel::withTrashed('title DESC');
        $this->assertSame('Third', $all[0]->title);
    }

    // ── onlyTrashed() ─────────────────────────────────────────────────────────

    public function test_onlyTrashed_returns_only_soft_deleted(): void
    {
        ArticleModel::find(1)->delete();
        ArticleModel::find(3)->delete();

        $trashed = ArticleModel::onlyTrashed();
        $this->assertCount(2, $trashed);
    }

    public function test_onlyTrashed_does_not_include_live_records(): void
    {
        ArticleModel::find(1)->delete();

        $trashed = ArticleModel::onlyTrashed();
        $ids     = array_map(fn($a) => (int) $a->id, $trashed);

        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertNotContains(3, $ids);
    }

    public function test_onlyTrashed_returns_empty_when_none_deleted(): void
    {
        $this->assertSame([], ArticleModel::onlyTrashed());
    }

    // ── Normal model without SoftDeletes is unaffected ────────────────────────

    public function test_model_without_softdeletes_still_hard_deletes(): void
    {
        // ProductModel from ModelTest is a plain Model (no SoftDeletes)
        // We can't access it here, so we use a quick anon class instead
        $this->pdo->exec('CREATE TABLE plain_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec("INSERT INTO plain_items (name) VALUES ('Alpha')");

        $plain = new class extends Model {
            protected string $table    = 'plain_items';
            protected array  $fillable = ['name'];
        };

        $plain::setConnection($this->connection);

        $item = $plain::find(1);
        $item->delete();

        // Row must be physically gone
        $stmt = $this->pdo->query('SELECT COUNT(*) as cnt FROM plain_items WHERE id = 1');
        $this->assertSame(0, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    // ── Invalid orderBy is rejected even on withTrashed/onlyTrashed ──────────

    public function test_withTrashed_rejects_invalid_order_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArticleModel::withTrashed('title; DROP TABLE articles;--');
    }

    public function test_onlyTrashed_rejects_invalid_order_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArticleModel::onlyTrashed('1=1');
    }
}
