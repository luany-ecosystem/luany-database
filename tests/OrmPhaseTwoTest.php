<?php

namespace Luany\Database\Tests;

use Luany\Database\Model;
use Luany\Database\QueryBuilder;
use Luany\Database\Relations\BelongsTo;
use Luany\Database\Relations\BelongsToMany;
use Luany\Database\Relations\HasMany;

class P2User extends Model
{
    protected string $table   = 'users';
    protected array $fillable = ['name'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(P2Role::class)->withPivot('granted_by');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(P2Post::class, 'user_id');
    }
}

class P2Role extends Model
{
    protected string $table   = 'roles';
    protected array $fillable = ['name'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(P2User::class);
    }
}

class P2Post extends Model
{
    protected string $table   = 'posts';
    protected array $fillable = ['user_id', 'title', 'views'];

    public function comments(): HasMany
    {
        return $this->hasMany(P2Comment::class, 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(P2User::class, 'user_id');
    }
}

class P2Comment extends Model
{
    protected string $table   = 'comments';
    protected array $fillable = ['post_id', 'user_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(P2User::class, 'user_id');
    }
}

class OrmPhaseTwoTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUpSchema(): void
    {
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER, granted_by TEXT)');
        $this->pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, views INTEGER)');
        $this->pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, user_id INTEGER, body TEXT)');

        Model::setConnection($this->connection);
        $this->qb = new QueryBuilder($this->connection);
    }

    private function seedBlog(): array
    {
        $ana = P2User::create(['name' => 'Ana']);
        $rui = P2User::create(['name' => 'Rui']);
        $p1  = P2Post::create(['user_id' => $ana->getKey(), 'title' => 'A1', 'views' => 10]);
        $p2  = P2Post::create(['user_id' => $ana->getKey(), 'title' => 'A2', 'views' => 30]);
        $p3  = P2Post::create(['user_id' => $rui->getKey(), 'title' => 'R1', 'views' => 5]);
        P2Comment::create(['post_id' => $p1->getKey(), 'user_id' => $rui->getKey(), 'body' => 'nice']);
        P2Comment::create(['post_id' => $p1->getKey(), 'user_id' => $ana->getKey(), 'body' => 'thanks']);
        P2Comment::create(['post_id' => $p3->getKey(), 'user_id' => $ana->getKey(), 'body' => 'cool']);
        return [$ana, $rui, $p1, $p2, $p3];
    }

    // ── Joins, grouping, aggregates ──────────────────────────────────────────

    public function test_join_with_aliases(): void
    {
        $this->seedBlog();

        $rows = $this->qb->table('posts AS p')
            ->select('p.title', 'u.name AS author')
            ->join('users AS u', 'u.id', '=', 'p.user_id')
            ->where('u.name', '=', 'Ana')
            ->orderBy('p.title')
            ->get();

        $this->assertSame([['title' => 'A1', 'author' => 'Ana'], ['title' => 'A2', 'author' => 'Ana']], $rows);
    }

    public function test_left_join_keeps_unmatched_rows(): void
    {
        $this->seedBlog();
        P2User::create(['name' => 'Zé']);

        $rows = $this->qb->table('users')
            ->select('users.name', 'posts.title')
            ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
            ->whereNull('posts.id')
            ->get();

        $this->assertSame([['name' => 'Zé', 'title' => null]], $rows);
    }

    public function test_group_by_having_and_count_of_groups(): void
    {
        $this->seedBlog();

        $q = $this->qb->table('posts')
            ->select('user_id')
            ->selectRaw('SUM(views) AS total')
            ->groupBy('user_id')
            ->havingRaw('SUM(views) > ?', [10])
            ->orderBy('user_id');

        $this->assertSame([['user_id' => 1, 'total' => 40]], array_map(
            fn ($r) => ['user_id' => (int) $r['user_id'], 'total' => (int) $r['total']],
            $q->get()
        ));
        $this->assertSame(1, $q->count(), 'count() counts groups, not rows');
    }

    public function test_aggregates(): void
    {
        $this->seedBlog();

        $this->assertSame(45, $this->qb->table('posts')->sum('views'));
        $this->assertSame(15.0, $this->qb->table('posts')->avg('views'));
        $this->assertSame(5, (int) $this->qb->table('posts')->min('views'));
        $this->assertSame(30, (int) $this->qb->table('posts')->max('views'));
        $this->assertSame(40, $this->qb->table('posts')->where('user_id', '=', 1)->sum('views'));
        $this->assertNull($this->qb->table('posts')->where('id', '=', 999)->sum('views'));
        $this->assertSame(40, P2Post::query()->where('user_id', '=', 1)->sum('views'));
    }

    public function test_distinct_and_paginate_with_join(): void
    {
        $this->seedBlog();

        $authors = $this->qb->table('users')->distinct()->select('users.name')
            ->join('posts', 'posts.user_id', '=', 'users.id')->orderBy('users.name');

        $this->assertSame(['Ana', 'Rui'], array_column($authors->get(), 'name'));
        $this->assertSame(2, $authors->count());

        $page = $this->qb->table('posts')->select('posts.title')
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->where('users.name', '=', 'Ana')->orderBy('posts.title')->paginate(1, 2);

        $this->assertSame(2, $page->total);
        $this->assertSame([['title' => 'A2']], $page->data);
    }

    public function test_join_identifiers_and_guards(): void
    {
        try {
            $this->qb->table('posts')->join('users; DROP TABLE users', 'a', '=', 'b');
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException) {
        }

        try {
            $this->qb->table('posts')->groupBy('user_id) OR (1=1');
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\LogicException::class);
        $this->qb->table('posts')->join('users', 'users.id', '=', 'posts.user_id')->delete();
    }

    // ── belongsToMany ────────────────────────────────────────────────────────

    public function test_attach_detach_and_lazy_load_with_pivot(): void
    {
        $ana    = P2User::create(['name' => 'Ana']);
        $admin  = P2Role::create(['name' => 'admin']);
        $editor = P2Role::create(['name' => 'editor']);

        $ana->roles()->attach($admin->getKey(), ['granted_by' => 'root']);
        $ana->roles()->attach([$editor->getKey() => ['granted_by' => 'ana']]);

        $roles = P2User::find($ana->getKey())->roles;
        $this->assertSame(['admin', 'editor'], array_map(fn ($r) => $r->name, $roles));
        $this->assertSame('root', $roles[0]->pivot['granted_by']);
        $this->assertSame($ana->getKey(), (int) $roles[0]->pivot['user_id']);

        $this->assertSame(1, $ana->roles()->detach($admin->getKey()));
        $this->assertSame(['editor'], array_map(fn ($r) => $r->name, P2User::find($ana->getKey())->roles));

        // inverse side, default pivot table and keys
        $this->assertSame(['Ana'], array_map(fn ($u) => $u->name, P2Role::find($editor->getKey())->users));
    }

    public function test_sync(): void
    {
        $ana = P2User::create(['name' => 'Ana']);
        [$r1, $r2, $r3] = [P2Role::create(['name' => 'a']), P2Role::create(['name' => 'b']), P2Role::create(['name' => 'c'])];

        $ana->roles()->attach([$r1->getKey(), $r2->getKey()]);
        $changes = $ana->roles()->sync([$r2->getKey(), $r3->getKey()]);

        $this->assertSame([$r3->getKey()], $changes['attached']);
        $this->assertSame([$r1->getKey()], array_map('intval', $changes['detached']));
        $this->assertSame(['b', 'c'], array_map(fn ($r) => $r->name, P2User::find($ana->getKey())->roles));

        $this->assertSame(['attached' => [], 'detached' => []], $ana->roles()->sync([$r2->getKey(), $r3->getKey()]));
    }

    public function test_eager_load_belongs_to_many_in_one_query(): void
    {
        $ana = P2User::create(['name' => 'Ana']);
        $rui = P2User::create(['name' => 'Rui']);
        $role = P2Role::create(['name' => 'admin']);
        $ana->roles()->attach($role->getKey());

        $users = P2User::with('roles')->orderBy('name')->get();

        $this->assertCount(1, $users[0]->roles);
        $this->assertSame([], $users[1]->roles);
    }

    public function test_attach_on_unsaved_parent_throws(): void
    {
        $this->expectException(\LogicException::class);
        (new P2User())->roles()->attach(1);
    }

    // ── Nested eager loading ─────────────────────────────────────────────────

    public function test_nested_eager_loading(): void
    {
        $this->seedBlog();

        $users = P2User::with('posts.comments.author', 'roles')->orderBy('name')->get();

        $ana = $users[0];
        $this->assertCount(2, $ana->posts);
        $this->assertCount(2, $ana->posts[0]->comments);
        $this->assertSame('Rui', $ana->posts[0]->comments[0]->author->name);
        $this->assertSame([], $ana->roles);
        $this->assertSame([], $ana->posts[1]->comments);
    }

    public function test_nested_eager_loading_through_belongs_to(): void
    {
        $this->seedBlog();

        $comments = P2Comment::with('author', 'author.posts')->orderBy('id')->get();

        $this->assertSame('Rui', $comments[0]->author->name);
        $this->assertCount(1, $comments[0]->author->posts);
    }
}
