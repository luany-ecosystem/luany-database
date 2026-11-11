<?php

namespace Luany\Database\Tests;

use Luany\Database\Concerns\SoftDeletes;
use Luany\Database\Model;
use Luany\Database\ModelQuery;
use Luany\Database\QueryBuilder;
use Luany\Database\Relations\BelongsTo;
use Luany\Database\Relations\BelongsToMany;
use Luany\Database\Relations\HasMany;
use Luany\Database\Relations\MorphMany;

class P4User extends Model
{
    protected string $table   = 'users';
    protected array $fillable = ['first_name', 'last_name', 'email', 'password'];
    protected array $hidden   = ['password'];
    protected array $appends  = ['full_name'];

    public function posts(): HasMany
    {
        return $this->hasMany(P4Post::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(P4Role::class, 'role_user', 'user_id', 'role_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getFirstNameAttribute(?string $value): ?string
    {
        return $value === null ? null : ucfirst($value);
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : strtolower(trim($value));
    }
}

class P4Role extends Model
{
    protected string $table   = 'roles';
    protected array $fillable = ['name'];
}

class P4Post extends Model
{
    use SoftDeletes;

    protected string $table   = 'posts';
    protected array $fillable = ['user_id', 'title', 'published'];

    public function comments(): MorphMany
    {
        return $this->morphMany(P4Comment::class, 'commentable');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(P4User::class, 'user_id');
    }

    public function scopePublished(ModelQuery $q): void
    {
        $q->where('published', '=', 1);
    }
}

class P4Comment extends Model
{
    protected string $table   = 'comments';
    protected array $fillable = ['body', 'approved'];
}

class P4Category extends Model
{
    protected string $table   = 'categories';
    protected array $fillable = ['name', 'parent_id'];

    public function children(): HasMany
    {
        return $this->hasMany(P4Category::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(P4Category::class, 'parent_id');
    }
}

class OrmPhaseFourTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT, last_name TEXT, email TEXT, password TEXT)');
        $this->pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $this->pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, published INTEGER DEFAULT 0, deleted_at TEXT)');
        $this->pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT, approved INTEGER DEFAULT 0, commentable_type TEXT, commentable_id INTEGER)');
        $this->pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, parent_id INTEGER)');
        $this->pdo->exec('CREATE TABLE products (sku TEXT PRIMARY KEY, price INTEGER, stock INTEGER)');

        Model::setConnection($this->connection);
    }

    /** @return array{0: P4User, 1: P4User, 2: P4User} */
    private function seed(): array
    {
        $ana = P4User::create(['first_name' => 'ana', 'last_name' => 'Silva', 'email' => 'ANA@X.AO']);
        $rui = P4User::create(['first_name' => 'rui', 'last_name' => 'Costa', 'email' => 'rui@x.ao']);
        $ze  = P4User::create(['first_name' => 'zé', 'last_name' => 'Lima', 'email' => 'ze@x.ao']);

        $p1 = P4Post::create(['user_id' => $ana->getKey(), 'title' => 'a1', 'published' => 1]);
        P4Post::create(['user_id' => $ana->getKey(), 'title' => 'a2', 'published' => 0]);
        P4Post::create(['user_id' => $ana->getKey(), 'title' => 'a3', 'published' => 1]);
        P4Post::create(['user_id' => $rui->getKey(), 'title' => 'r1', 'published' => 0]);
        P4Post::create(['user_id' => $ze->getKey(), 'title' => 'gone', 'published' => 1])->delete();

        $p1->comments()->create(['body' => 'ok', 'approved' => 1]);
        $p1->comments()->create(['body' => 'spam', 'approved' => 0]);

        $admin = P4Role::create(['name' => 'admin']);
        $ana->roles()->attach($admin->getKey());

        return [$ana, $rui, $ze];
    }

    private function names(array $models): array
    {
        return array_map(fn ($m) => $m->getRawAttribute('first_name'), $models);
    }

    // ── whereHas & friends ───────────────────────────────────────────────────

    public function test_where_has_and_doesnt_have(): void
    {
        $this->seed();

        $this->assertSame(['ana', 'rui'], $this->names(P4User::query()->has('posts')->orderBy('id')->get()));
        $this->assertSame(['zé'], $this->names(P4User::query()->doesntHave('posts')->get()), 'soft-deleted posts do not count');
        $this->assertSame(['ana'], $this->names(P4User::query()->whereHas('posts', fn (ModelQuery $q) => $q->published())->get()));
        $this->assertSame(['rui', 'zé'], $this->names(P4User::query()->whereDoesntHave('posts', fn ($q) => $q->where('published', '=', 1))->orderBy('id')->get()));
        $this->assertSame(['ana'], $this->names(P4User::query()->has('posts', '>=', 3)->get()));
        $this->assertSame(['ana'], $this->names(P4User::query()->whereHas('roles')->get()), 'through a pivot table');
    }

    public function test_where_has_nested_and_or(): void
    {
        $this->seed();

        $withApproved = P4User::query()->whereHas('posts.comments', fn ($q) => $q->where('approved', '=', 1))->get();
        $this->assertSame(['ana'], $this->names($withApproved));

        $either = P4User::query()->where('first_name', '=', 'zé')->orWhereHas('roles')->orderBy('id')->get();
        $this->assertSame(['ana', 'zé'], $this->names($either));

        $this->assertSame(['a1', 'a2', 'a3'], array_map(
            fn ($p) => $p->title,
            P4Post::query()->whereHas('author', fn ($q) => $q->where('email', '=', 'ana@x.ao'))->orderBy('id')->get()
        ));
    }

    public function test_where_has_on_self_referencing_relation(): void
    {
        $root  = P4Category::create(['name' => 'root']);
        $child = P4Category::create(['name' => 'child', 'parent_id' => $root->getKey()]);
        P4Category::create(['name' => 'leaf', 'parent_id' => $child->getKey()]);

        $withChildren = array_map(fn ($c) => $c->name, P4Category::query()->has('children')->orderBy('id')->get());
        $this->assertSame(['root', 'child'], $withChildren);

        $grandparents = array_map(fn ($c) => $c->name, P4Category::query()->whereHas('children.children')->get());
        $this->assertSame(['root'], $grandparents);

        $counts = P4Category::query()->withCount('children')->orderBy('id')->get();
        $this->assertSame([1, 1, 0], array_map(fn ($c) => (int) $c->children_count, $counts));
    }

    public function test_with_count_including_constrained_and_alias(): void
    {
        $this->seed();

        $users = P4User::query()
            ->withCount('posts', ['posts as published_count' => fn ($q) => $q->published()])
            ->orderBy('id')
            ->get();

        $this->assertSame([3, 1, 0], array_map(fn ($u) => (int) $u->posts_count, $users));
        $this->assertSame([2, 0, 0], array_map(fn ($u) => (int) $u->published_count, $users));
        $this->assertSame('Ana', $users[0]->first_name, 'parent columns still selected');
    }

    // ── Constrained eager loading ────────────────────────────────────────────

    public function test_constrained_and_nested_constrained_eager_loading(): void
    {
        $this->seed();

        $users = P4User::with(['posts' => fn (ModelQuery $q) => $q->published()->orderByDesc('title')], 'roles')
            ->orderBy('id')->get();

        $this->assertSame(['a3', 'a1'], array_map(fn ($p) => $p->title, $users[0]->posts));
        $this->assertSame([], $users[1]->posts);
        $this->assertCount(1, $users[0]->roles);

        $posts = P4Post::with(['comments' => fn ($q) => $q->where('approved', '=', 1)])->where('title', '=', 'a1')->get();
        $this->assertSame(['ok'], array_map(fn ($c) => $c->body, $posts[0]->comments));

        $nested = P4User::with(['posts.comments' => fn ($q) => $q->where('approved', '=', 0)])->orderBy('id')->get();
        $this->assertSame(['spam'], array_map(fn ($c) => $c->body, $nested[0]->posts[0]->comments));
    }

    // ── Accessors & mutators ─────────────────────────────────────────────────

    public function test_accessors_mutators_and_appends(): void
    {
        [$ana] = $this->seed();

        $fresh = P4User::find($ana->getKey());
        $this->assertSame('Ana', $fresh->first_name, 'accessor on a column');
        $this->assertSame('ana', $fresh->getRawAttribute('first_name'));
        $this->assertSame('Ana Silva', $fresh->full_name, 'virtual accessor');
        $this->assertSame('ana@x.ao', $fresh->email, 'mutator ran on create');

        $array = $fresh->toArray();
        $this->assertSame('Ana Silva', $array['full_name']);
        $this->assertSame('Ana', $array['first_name']);
        $this->assertArrayNotHasKey('password', $array);
    }

    // ── fresh / firstOrNew / firstOrCreate / updateOrCreate ─────────────────

    public function test_fresh_and_first_or_create_family(): void
    {
        [$ana] = $this->seed();

        $this->pdo->exec("UPDATE users SET last_name = 'Changed' WHERE id = {$ana->getKey()}");
        $this->assertSame('Changed', $ana->fresh()->last_name);
        $this->assertSame('Silva', $ana->last_name, 'fresh() leaves the instance untouched');

        $existing = P4User::firstOrCreate(['email' => 'rui@x.ao'], ['first_name' => 'ignored']);
        $this->assertSame('rui', $existing->getRawAttribute('first_name'));

        $created = P4User::firstOrCreate(['email' => 'new@x.ao'], ['first_name' => 'nova']);
        $this->assertTrue($created->exists());
        $this->assertSame(4, P4User::count());

        $new = P4User::firstOrNew(['email' => 'unsaved@x.ao']);
        $this->assertFalse($new->exists());

        $updated = P4User::updateOrCreate(['email' => 'rui@x.ao'], ['last_name' => 'Novo']);
        $this->assertSame('Novo', P4User::find($updated->getKey())->last_name);
        $this->assertSame(4, P4User::count());
    }

    // ── Batch insert, upsert, chunking ───────────────────────────────────────

    public function test_batch_insert_and_upsert(): void
    {
        $qb = (new QueryBuilder($this->connection))->table('products');

        $qb->insert([
            ['sku' => 'A', 'price' => 10, 'stock' => 1],
            ['stock' => 2, 'sku' => 'B', 'price' => 20], // key order does not matter
        ]);
        $this->assertSame(2, $qb->count());

        $qb->upsert(
            [['sku' => 'A', 'price' => 11, 'stock' => 9], ['sku' => 'C', 'price' => 30, 'stock' => 3]],
            ['sku'],
            ['price']
        );

        $rows = (new QueryBuilder($this->connection))->table('products')->orderBy('sku')->get();
        $this->assertSame([['A', 11, 1], ['B', 20, 2], ['C', 30, 3]], array_map(
            fn ($r) => [$r['sku'], (int) $r['price'], (int) $r['stock']],
            $rows
        ), 'stock not in $update keeps its value');

        $this->expectException(\InvalidArgumentException::class);
        (new QueryBuilder($this->connection))->table('products')->insert([['sku' => 'D'], ['price' => 1]]);
    }

    public function test_chunk_chunk_by_id_and_cursor(): void
    {
        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = ['first_name' => "u{$i}", 'last_name' => 'x', 'email' => "u{$i}@x.ao", 'password' => 'p'];
        }
        (new QueryBuilder($this->connection))->table('users')->insert($rows);

        $pages = [];
        P4User::query()->orderBy('id')->chunk(3, function (array $users, int $page) use (&$pages) {
            $pages[$page] = count($users);
            $this->assertContainsOnlyInstancesOf(P4User::class, $users);
        });
        $this->assertSame([1 => 3, 2 => 3, 3 => 1], $pages);

        // chunkById is stable while deleting the rows being read
        $seen = 0;
        P4User::query()->chunkById(2, function (array $users) use (&$seen) {
            foreach ($users as $user) {
                $seen++;
                $user->delete();
            }
        });
        $this->assertSame(7, $seen);
        $this->assertSame(0, P4User::count());

        (new QueryBuilder($this->connection))->table('users')->insert($rows);
        $stopped = 0;
        P4User::query()->chunk(2, function () use (&$stopped) {
            $stopped++;
            return false;
        });
        $this->assertSame(1, $stopped);

        $names = [];
        foreach (P4User::query()->orderBy('id')->cursor() as $user) {
            $names[] = $user->getRawAttribute('first_name');
        }
        $this->assertSame(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7'], $names);
    }
}
