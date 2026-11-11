<?php

namespace Luany\Database\Tests;

use Luany\Database\Casts\CastsAttributes;
use Luany\Database\Concerns\SoftDeletes;
use Luany\Database\Model;
use Luany\Database\ModelNotFoundException;
use Luany\Database\ModelQuery;
use Luany\Database\Relations\HasMany;

final class Cents
{
    public function __construct(public readonly int $value) {}
}

final class CentsCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : new Cents((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value instanceof Cents ? $value->value : $value;
    }
}

class OrmArticle extends Model
{
    use SoftDeletes;

    protected string $table   = 'orm_articles';
    protected array $fillable = ['title', 'active', 'views', 'rating', 'meta', 'published_at', 'birthday', 'price', 'author_id'];
    protected bool $timestamps = true;
    protected array $casts = [
        'active'       => 'bool',
        'views'        => 'int',
        'rating'       => 'decimal:1',
        'meta'         => 'array',
        'published_at' => 'datetime',
        'birthday'     => 'date',
        'price'        => CentsCast::class,
    ];

    public function scopeActive(ModelQuery $query): void
    {
        $query->where('active', '=', 1);
    }

    public function scopePopular(ModelQuery $query, int $min = 100): void
    {
        $query->where('views', '>=', $min);
    }
}

class OrmAuthor extends Model
{
    protected string $table   = 'orm_authors';
    protected array $fillable = ['name'];

    public function articles(): HasMany
    {
        return $this->hasMany(OrmArticle::class, 'author_id');
    }
}

class OrmLog extends Model
{
    protected string $table   = 'orm_logs';
    protected array $fillable = ['message'];
    protected bool $timestamps = true;

    public const CREATED_AT = 'logged_at';
    public const UPDATED_AT = null;
}

class OrmPhaseOneTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('CREATE TABLE orm_authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec('
            CREATE TABLE orm_articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                author_id INTEGER, title TEXT, active INTEGER, views INTEGER, rating TEXT,
                meta TEXT, published_at TEXT, birthday TEXT, price INTEGER,
                created_at TEXT, updated_at TEXT, deleted_at TEXT
            )
        ');
        $this->pdo->exec('CREATE TABLE orm_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT, logged_at TEXT)');

        Model::setConnection($this->connection);
    }

    private function article(array $attrs = []): OrmArticle
    {
        return OrmArticle::create($attrs + ['title' => 't', 'active' => true, 'views' => 10]);
    }

    // ── Casts ────────────────────────────────────────────────────────────────

    public function test_scalar_casts_on_read(): void
    {
        $a = OrmArticle::find($this->article(['views' => '42', 'rating' => 4.25])->getKey());

        $this->assertTrue($a->active);
        $this->assertSame(42, $a->views);
        $this->assertSame('4.3', $a->rating);
    }

    public function test_bool_is_stored_portably_and_reads_false(): void
    {
        $a = $this->article(['active' => false]);
        $row = $this->pdo->query("SELECT active FROM orm_articles WHERE id = {$a->getKey()}")->fetch();

        $this->assertSame(0, (int) $row['active']);
        $this->assertFalse(OrmArticle::find($a->getKey())->active);
    }

    public function test_array_cast_round_trip(): void
    {
        $a = $this->article(['meta' => ['tags' => ['php', 'luany'], 'lang' => 'pt']]);

        $raw = $this->pdo->query("SELECT meta FROM orm_articles WHERE id = {$a->getKey()}")->fetchColumn();
        $this->assertSame('{"tags":["php","luany"],"lang":"pt"}', $raw);
        $this->assertSame(['tags' => ['php', 'luany'], 'lang' => 'pt'], OrmArticle::find($a->getKey())->meta);
    }

    public function test_datetime_and_date_casts(): void
    {
        $a = $this->article([
            'published_at' => new \DateTimeImmutable('2026-03-01 10:20:30'),
            'birthday'     => '1990-05-17',
        ]);

        $fresh = OrmArticle::find($a->getKey());
        $this->assertInstanceOf(\DateTimeImmutable::class, $fresh->published_at);
        $this->assertSame('2026-03-01 10:20:30', $fresh->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('1990-05-17 00:00:00', $fresh->birthday->format('Y-m-d H:i:s'));

        $arr = $fresh->toArray();
        $this->assertSame('2026-03-01 10:20:30', $arr['published_at']);
        $this->assertSame('1990-05-17', $arr['birthday']);
        $this->assertSame(true, $arr['active']);
    }

    public function test_custom_cast_class(): void
    {
        $a = $this->article(['price' => new Cents(1999)]);

        $this->assertSame(1999, (int) $this->pdo->query("SELECT price FROM orm_articles WHERE id = {$a->getKey()}")->fetchColumn());
        $this->assertSame(1999, OrmArticle::find($a->getKey())->price->value);
    }

    public function test_setting_equal_cast_value_is_not_dirty(): void
    {
        $a = OrmArticle::find($this->article(['active' => true, 'views' => 5])->getKey());
        $a->active = true;
        $a->views  = '5';

        $this->assertFalse($a->isDirty());
    }

    // ── Timestamps ───────────────────────────────────────────────────────────

    public function test_timestamps_on_create_and_update(): void
    {
        $a = $this->article();
        $this->assertNotNull($a->getRawAttribute('created_at'));
        $this->assertSame($a->getRawAttribute('created_at'), $a->getRawAttribute('updated_at'));

        $this->pdo->exec("UPDATE orm_articles SET updated_at = '2000-01-01 00:00:00' WHERE id = {$a->getKey()}");
        $a->refresh();
        $created = $a->getRawAttribute('created_at');

        $a->title = 'changed';
        $a->save();

        $this->assertNotSame('2000-01-01 00:00:00', $a->getRawAttribute('updated_at'));
        $this->assertSame($created, $a->getRawAttribute('created_at'));
    }

    public function test_saving_without_changes_does_not_touch_updated_at(): void
    {
        $a = $this->article();
        $this->pdo->exec("UPDATE orm_articles SET updated_at = '2000-01-01 00:00:00' WHERE id = {$a->getKey()}");
        $a->refresh();

        $a->save();

        $this->assertSame('2000-01-01 00:00:00', $a->getRawAttribute('updated_at'));
    }

    public function test_touch_and_custom_columns(): void
    {
        $a = $this->article();
        $this->pdo->exec("UPDATE orm_articles SET updated_at = '2000-01-01 00:00:00' WHERE id = {$a->getKey()}");
        $a->refresh();
        $this->assertTrue($a->touch());
        $this->assertNotSame('2000-01-01 00:00:00', $a->getRawAttribute('updated_at'));

        $log = OrmLog::create(['message' => 'hi']);
        $this->assertNotNull($log->getRawAttribute('logged_at'));
        $this->assertFalse($log->touch());
    }

    public function test_models_without_timestamps_are_unchanged(): void
    {
        $author = OrmAuthor::create(['name' => 'Ana']);
        $this->assertArrayNotHasKey('created_at', $author->toArray());
    }

    // ── ModelQuery & scopes ──────────────────────────────────────────────────

    public function test_query_returns_models_and_supports_scopes(): void
    {
        $this->article(['title' => 'a', 'active' => true, 'views' => 500]);
        $this->article(['title' => 'b', 'active' => false, 'views' => 900]);
        $this->article(['title' => 'c', 'active' => true, 'views' => 50]);

        $popular = OrmArticle::active()->popular()->orderBy('title')->get();
        $this->assertSame(['a'], array_map(fn ($m) => $m->title, $popular));
        $this->assertContainsOnlyInstancesOf(OrmArticle::class, $popular);

        $this->assertSame(['a', 'c'], OrmArticle::query()->active()->orderBy('title')->pluck('title'));
        $this->assertSame(2, OrmArticle::active()->count());
        $this->assertSame(['c'], OrmArticle::active()->popular(10)->where('views', '<', 100)->pluck('title'));
    }

    public function test_query_respects_soft_deletes(): void
    {
        $this->article(['title' => 'keep']);
        $this->article(['title' => 'gone'])->delete();

        $this->assertSame(['keep'], OrmArticle::query()->pluck('title'));
    }

    public function test_first_find_and_or_fail(): void
    {
        $a = $this->article(['title' => 'x']);

        $this->assertSame('x', OrmArticle::query()->where('title', '=', 'x')->first()->title);
        $this->assertSame($a->getKey(), OrmArticle::query()->find($a->getKey())->getKey());
        $this->assertSame($a->getKey(), OrmArticle::findOrFail($a->getKey())->getKey());

        $this->expectException(ModelNotFoundException::class);
        OrmArticle::query()->where('title', '=', 'nope')->firstOrFail();
    }

    public function test_find_or_fail_static_message(): void
    {
        try {
            OrmArticle::findOrFail(999);
            $this->fail('Expected exception');
        } catch (ModelNotFoundException $e) {
            $this->assertSame('No [OrmArticle] found with key [999].', $e->getMessage());
        }
    }

    public function test_paginate_returns_models_with_eager_loading(): void
    {
        $author = OrmAuthor::create(['name' => 'Rui']);
        for ($i = 1; $i <= 5; $i++) {
            $this->article(['title' => "p{$i}", 'author_id' => $author->getKey()]);
        }

        $page = OrmAuthor::query()->with('articles')->paginate(1, 1);
        $this->assertSame(1, $page->total);
        $this->assertCount(5, $page->data[0]->articles);

        $articles = OrmArticle::query()->orderBy('id')->paginate(2, 2);
        $this->assertSame(['p3', 'p4'], array_map(fn ($m) => $m->title, $articles->data));
        $this->assertSame(3, $articles->lastPage);
    }

    public function test_with_combines_with_any_query_and_keeps_legacy_all_find(): void
    {
        $author = OrmAuthor::create(['name' => 'Ana']);
        $this->article(['author_id' => $author->getKey()]);

        $this->assertCount(1, OrmAuthor::with('articles')->where('name', '=', 'Ana')->get()[0]->articles);
        $this->assertCount(1, OrmAuthor::with('articles')->all('name ASC')[0]->articles);
        $this->assertCount(1, OrmAuthor::with('articles')->find($author->getKey())->articles);
    }

    public function test_unknown_method_and_table_switch_are_rejected(): void
    {
        try {
            OrmArticle::query()->table('orm_authors');
            $this->fail('Expected exception');
        } catch (\BadMethodCallException) {
        }

        $this->expectException(\BadMethodCallException::class);
        OrmArticle::nonexistentScope();
    }

    public function test_to_base_returns_raw_rows(): void
    {
        $this->article(['active' => true]);
        $row = OrmArticle::query()->toBase()->first();

        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['active']);
    }
}
