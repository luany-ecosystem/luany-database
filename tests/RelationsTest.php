<?php

namespace Luany\Database\Tests;

use Luany\Database\Model;
use Luany\Database\Relations\BelongsTo;
use Luany\Database\Relations\HasMany;
use Luany\Database\Relations\HasOne;

// ── Test model stubs ──────────────────────────────────────────────────────────

class UserModel extends Model
{
    protected string $table    = 'users';
    protected array  $fillable = ['name', 'email'];

    public function profile(): HasOne
    {
        return $this->hasOne(ProfileModel::class, 'user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(PostModel::class, 'user_id');
    }
}

class ProfileModel extends Model
{
    protected string $table    = 'profiles';
    protected array  $fillable = ['user_id', 'bio'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }
}

class PostModel extends Model
{
    protected string $table    = 'posts';
    protected array  $fillable = ['user_id', 'title'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }
}

// ── Test suite ────────────────────────────────────────────────────────────────

class RelationsTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE users (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                name  TEXT NOT NULL,
                email TEXT NOT NULL
            )
        ');
        $this->pdo->exec('
            CREATE TABLE profiles (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                bio     TEXT
            )
        ');
        $this->pdo->exec('
            CREATE TABLE posts (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title   TEXT NOT NULL
            )
        ');

        // Seed
        $this->pdo->exec("INSERT INTO users (name, email) VALUES
            ('António', 'antonio@example.com'),
            ('Dadiva',  'dadiva@example.com')
        ");
        $this->pdo->exec("INSERT INTO profiles (user_id, bio) VALUES
            (1, 'Lead engineer'),
            (2, 'Designer')
        ");
        $this->pdo->exec("INSERT INTO posts (user_id, title) VALUES
            (1, 'Post A'),
            (1, 'Post B'),
            (2, 'Post C')
        ");

        UserModel::setConnection($this->connection);
        ProfileModel::setConnection($this->connection);
        PostModel::setConnection($this->connection);
    }

    // ── hasOne ────────────────────────────────────────────────────────────────

    public function test_hasOne_returns_HasOne_descriptor(): void
    {
        $user       = UserModel::find(1);
        $descriptor = $user->profile();
        $this->assertInstanceOf(HasOne::class, $descriptor);
    }

    public function test_hasOne_lazy_returns_related_model(): void
    {
        $user    = UserModel::find(1);
        $profile = $user->profile;                   // property access → lazy load
        $this->assertInstanceOf(ProfileModel::class, $profile);
        $this->assertSame('Lead engineer', $profile->bio);
    }

    public function test_hasOne_returns_null_when_no_related(): void
    {
        // Create user with no profile
        $this->pdo->exec("INSERT INTO users (name, email) VALUES ('Ngola', 'ngola@example.com')");
        $user    = UserModel::find(3);
        $profile = $user->profile;
        $this->assertNull($profile);
    }

    public function test_hasOne_result_is_cached_after_first_access(): void
    {
        $user = UserModel::find(1);

        $first  = $user->profile;   // triggers query
        $second = $user->profile;   // hits cache — no second query

        $this->assertSame($first, $second);
    }

    // ── hasMany ───────────────────────────────────────────────────────────────

    public function test_hasMany_returns_HasMany_descriptor(): void
    {
        $user       = UserModel::find(1);
        $descriptor = $user->posts();
        $this->assertInstanceOf(HasMany::class, $descriptor);
    }

    public function test_hasMany_lazy_returns_array_of_related_models(): void
    {
        $user  = UserModel::find(1);
        $posts = $user->posts;                       // property access → lazy load
        $this->assertIsArray($posts);
        $this->assertCount(2, $posts);
        $this->assertContainsOnlyInstancesOf(PostModel::class, $posts);
    }

    public function test_hasMany_titles_are_correct(): void
    {
        $user   = UserModel::find(1);
        $titles = array_column(array_map(fn($p) => $p->toArray(), $user->posts), 'title');
        sort($titles);
        $this->assertSame(['Post A', 'Post B'], $titles);
    }

    public function test_hasMany_returns_empty_array_when_no_related(): void
    {
        $this->pdo->exec("INSERT INTO users (name, email) VALUES ('Ngola', 'ngola@example.com')");
        $user  = UserModel::find(3);
        $posts = $user->posts;
        $this->assertSame([], $posts);
    }

    public function test_hasMany_result_is_cached_after_first_access(): void
    {
        $user   = UserModel::find(1);
        $first  = $user->posts;
        $second = $user->posts;
        $this->assertSame($first, $second);
    }

    // ── belongsTo ────────────────────────────────────────────────────────────

    public function test_belongsTo_returns_BelongsTo_descriptor(): void
    {
        $post       = PostModel::find(1);
        $descriptor = $post->author();
        $this->assertInstanceOf(BelongsTo::class, $descriptor);
    }

    public function test_belongsTo_lazy_returns_parent_model(): void
    {
        $post   = PostModel::find(1);
        $author = $post->author;                     // property access → lazy load
        $this->assertInstanceOf(UserModel::class, $author);
        $this->assertSame('António', $author->name);
    }

    public function test_belongsTo_returns_null_when_fk_is_null(): void
    {
        // Insert post with no user_id reference by using a non-existent user
        $this->pdo->exec("INSERT INTO posts (user_id, title) VALUES (999, 'Orphan')");
        $post = PostModel::find(4);
        // user_id exists but there's no matching user → returns null
        $this->assertNull($post->author);
    }

    public function test_belongsTo_result_is_cached_after_first_access(): void
    {
        $post   = PostModel::find(1);
        $first  = $post->author;
        $second = $post->author;
        $this->assertSame($first, $second);
    }

    // ── getRelation() ─────────────────────────────────────────────────────────

    public function test_getRelation_throws_for_unknown_relation(): void
    {
        $user = UserModel::find(1);
        $this->expectException(\BadMethodCallException::class);
        $user->getRelation('nonExistentRelation');
    }

    // ── setRelation() ─────────────────────────────────────────────────────────

    public function test_setRelation_overrides_value(): void
    {
        $user = UserModel::find(1);
        $user->setRelation('profile', null);
        $this->assertNull($user->profile);
    }
}
