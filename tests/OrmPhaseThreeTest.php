<?php

namespace Luany\Database\Tests;

use Luany\Database\Concerns\SoftDeletes;
use Luany\Database\Model;
use Luany\Database\Relations\HasManyThrough;
use Luany\Database\Relations\MorphMany;
use Luany\Database\Relations\MorphOne;
use Luany\Database\Relations\MorphTo;

class P3Country extends Model
{
    protected string $table   = 'countries';
    protected array $fillable = ['name'];

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(P3Post::class, P3User::class);
    }
}

class P3User extends Model
{
    protected string $table   = 'users';
    protected array $fillable = ['name', 'country_id', 'email'];

    /** @var list<string> */
    public static array $log = [];

    protected static function booted(): void
    {
        static::saving(fn (self $u) => $u->email = strtolower((string) $u->email));
        static::creating(function (self $u) { self::$log[] = 'creating'; });
        static::created(function (self $u) { self::$log[] = 'created'; });
        static::updating(function (self $u) { self::$log[] = 'updating'; });
        static::updated(function (self $u) { self::$log[] = 'updated'; });
        static::saved(function (self $u) { self::$log[] = 'saved'; });
    }
}

class P3Post extends Model
{
    use SoftDeletes;

    protected string $table   = 'posts';
    protected array $fillable = ['user_id', 'title'];

    public function comments(): MorphMany
    {
        return $this->morphMany(P3Comment::class, 'commentable');
    }

    public function image(): MorphOne
    {
        return $this->morphOne(P3Image::class, 'imageable');
    }
}

class P3Video extends Model
{
    protected string $table   = 'videos';
    protected array $fillable = ['title'];

    public function comments(): MorphMany
    {
        return $this->morphMany(P3Comment::class, 'commentable');
    }
}

class P3Comment extends Model
{
    protected string $table   = 'comments';
    protected array $fillable = ['body'];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class P3Image extends Model
{
    protected string $table   = 'images';
    protected array $fillable = ['url'];
}

class P3PostObserver
{
    /** @var list<string> */
    public array $seen = [];

    public function deleting(P3Post $post): ?bool
    {
        $this->seen[] = 'deleting';
        return $post->title === 'locked' ? false : null;
    }

    public function deleted(P3Post $post): void   { $this->seen[] = 'deleted'; }
    public function restoring(P3Post $post): void { $this->seen[] = 'restoring'; }
    public function restored(P3Post $post): void  { $this->seen[] = 'restored'; }
}

class OrmPhaseThreeTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, country_id INTEGER, email TEXT)');
        $this->pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, deleted_at TEXT)');
        $this->pdo->exec('CREATE TABLE videos (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        $this->pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT, commentable_type TEXT, commentable_id INTEGER)');
        $this->pdo->exec('CREATE TABLE images (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT, imageable_type TEXT, imageable_id INTEGER)');

        Model::setConnection($this->connection);
        Model::morphMap([], false);
        P3User::flushEventListeners();
        P3Post::flushEventListeners();
        P3User::$log = [];
    }

    // ── Events ───────────────────────────────────────────────────────────────

    public function test_lifecycle_events_fire_in_order_and_booted_runs_once(): void
    {
        $u = P3User::create(['name' => 'Ana', 'email' => 'ANA@X.AO']);
        $this->assertSame(['creating', 'created', 'saved'], P3User::$log);
        $this->assertSame('ana@x.ao', P3User::find($u->getKey())->email, 'saving listener mutated before INSERT');

        P3User::$log = [];
        $u->name = 'Ana N';
        $u->save();
        $this->assertSame(['updating', 'updated', 'saved'], P3User::$log);

        P3User::$log = [];
        $u->save(); // nothing dirty
        $this->assertSame(['saved'], P3User::$log, 'no updating/updated without changes');
    }

    public function test_creating_listener_returning_false_cancels_insert(): void
    {
        P3Post::creating(fn (P3Post $p) => $p->title !== 'spam');

        $post = new P3Post();
        $post->title = 'spam';
        $this->assertFalse($post->save());
        $this->assertFalse($post->exists());
        $this->assertSame(0, P3Post::count());
    }

    public function test_observer_can_cancel_delete_and_sees_restore(): void
    {
        $observer = new P3PostObserver();
        P3Post::observe($observer);

        $locked = P3Post::create(['title' => 'locked']);
        $this->assertFalse($locked->delete());
        $this->assertNotNull(P3Post::find($locked->getKey()));

        $post = P3Post::create(['title' => 'free']);
        $this->assertTrue($post->delete());
        $this->assertTrue($post->restore());

        $this->assertSame(['deleting', 'deleting', 'deleted', 'restoring', 'restored'], $observer->seen);
    }

    public function test_unknown_event_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        P3Post::on('saveing', fn () => null);
    }

    // ── hasManyThrough ───────────────────────────────────────────────────────

    public function test_has_many_through_lazy_and_eager(): void
    {
        $ao = P3Country::create(['name' => 'Angola']);
        $pt = P3Country::create(['name' => 'Portugal']);
        $ana = P3User::create(['name' => 'Ana', 'country_id' => $ao->getKey()]);
        $rui = P3User::create(['name' => 'Rui', 'country_id' => $ao->getKey()]);
        P3User::create(['name' => 'Zé', 'country_id' => $pt->getKey()]);
        P3Post::create(['user_id' => $ana->getKey(), 'title' => 'a1']);
        P3Post::create(['user_id' => $rui->getKey(), 'title' => 'r1']);
        P3Post::create(['user_id' => $rui->getKey(), 'title' => 'gone'])->delete();

        $titles = array_map(fn ($p) => $p->title, P3Country::find($ao->getKey())->posts);
        sort($titles);
        $this->assertSame(['a1', 'r1'], $titles, 'soft-deleted rows are excluded');

        $countries = P3Country::with('posts')->orderBy('id')->get();
        $this->assertCount(2, $countries[0]->posts);
        $this->assertSame([], $countries[1]->posts);
        $this->assertArrayNotHasKey('luany_through_key', $countries[0]->posts[0]->toArray());
    }

    // ── Polymorphic ──────────────────────────────────────────────────────────

    public function test_morph_many_create_and_morph_to(): void
    {
        $post  = P3Post::create(['title' => 'p']);
        $video = P3Video::create(['title' => 'v']);

        $post->comments()->create(['body' => 'on post']);
        $video->comments()->create(['body' => 'on video']);

        $this->assertSame(['on post'], array_map(fn ($c) => $c->body, P3Post::find($post->getKey())->comments));

        $comments = P3Comment::query()->orderBy('id')->get();
        $this->assertInstanceOf(P3Post::class, $comments[0]->commentable);
        $this->assertInstanceOf(P3Video::class, $comments[1]->commentable);
        $this->assertSame(P3Post::class, $comments[0]->commentable_type);
    }

    public function test_morph_to_eager_loads_one_query_per_type_and_nested(): void
    {
        $post  = P3Post::create(['title' => 'p']);
        $video = P3Video::create(['title' => 'v']);
        $post->comments()->create(['body' => 'c1']);
        $video->comments()->create(['body' => 'c2']);
        $post->comments()->create(['body' => 'c3']);

        $comments = P3Comment::with('commentable.comments')->orderBy('id')->get();

        $this->assertSame('p', $comments[0]->commentable->title);
        $this->assertSame('v', $comments[1]->commentable->title);
        $this->assertCount(2, $comments[0]->commentable->comments, 'nested eager load on mixed classes');
        $this->assertCount(1, $comments[1]->commentable->comments);
    }

    public function test_morph_map_aliases_and_associate(): void
    {
        Model::morphMap(['post' => P3Post::class, 'video' => P3Video::class]);

        $video = P3Video::create(['title' => 'v']);
        $comment = new P3Comment();
        $comment->body = 'x';
        $comment->commentable()->associate($video)->save();

        $row = $this->pdo->query("SELECT commentable_type FROM comments WHERE id = {$comment->getKey()}")->fetchColumn();
        $this->assertSame('video', $row);
        $this->assertInstanceOf(P3Video::class, P3Comment::find($comment->getKey())->commentable);
        $this->assertCount(1, P3Video::find($video->getKey())->comments);
    }

    public function test_morph_one_and_unknown_type(): void
    {
        $post = P3Post::create(['title' => 'p']);
        $post->image()->create(['url' => '/a.png']);

        $this->assertSame('/a.png', P3Post::find($post->getKey())->image->url);
        $this->assertSame('/a.png', P3Post::with('image')->get()[0]->image->url);

        $this->pdo->exec("INSERT INTO comments (body, commentable_type, commentable_id) VALUES ('x', 'App\\\\Nope', 1)");
        $this->expectException(\UnexpectedValueException::class);
        P3Comment::query()->where('body', '=', 'x')->first()->commentable;
    }
}
