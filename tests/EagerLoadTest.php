<?php

namespace Luany\Database\Tests;

use Luany\Database\Model;
use Luany\Database\Relations\BelongsTo;
use Luany\Database\Relations\HasMany;
use Luany\Database\Relations\HasOne;

// ── Test model stubs (prefixed with Eager to avoid conflicts) ─────────────────

class EagerUser extends Model
{
    protected string $table    = 'users';
    protected array  $fillable = ['name', 'email'];

    public function profile(): HasOne
    {
        return $this->hasOne(EagerProfile::class, 'user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(EagerPost::class, 'user_id');
    }
}

class EagerProfile extends Model
{
    protected string $table    = 'profiles';
    protected array  $fillable = ['user_id', 'bio'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(EagerUser::class, 'user_id');
    }
}

class EagerPost extends Model
{
    protected string $table    = 'posts';
    protected array  $fillable = ['user_id', 'title'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(EagerUser::class, 'user_id');
    }
}

// ── Test suite ────────────────────────────────────────────────────────────────

class EagerLoadTest extends TestCase
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

        $this->pdo->exec("INSERT INTO users (name, email) VALUES
            ('António', 'antonio@example.com'),
            ('Dadiva',  'dadiva@example.com'),
            ('Ngola',   'ngola@example.com')
        ");
        $this->pdo->exec("INSERT INTO profiles (user_id, bio) VALUES
            (1, 'Lead engineer'),
            (2, 'Designer')
        "); // user 3 has NO profile
        $this->pdo->exec("INSERT INTO posts (user_id, title) VALUES
            (1, 'Alpha'),
            (1, 'Beta'),
            (2, 'Gamma'),
            (3, 'Delta')
        ");

        EagerUser::setConnection($this->connection);
        EagerProfile::setConnection($this->connection);
        EagerPost::setConnection($this->connection);
    }

    // ── with()->all() — hasMany ────────────────────────────────────────────────

    public function test_eager_hasMany_loads_correct_posts_per_user(): void
    {
        $users = EagerUser::with('posts')->all();

        $this->assertCount(3, $users);
        $this->assertCount(2, $users[0]->posts); // António → 2 posts
        $this->assertCount(1, $users[1]->posts); // Dadiva  → 1 post
        $this->assertCount(1, $users[2]->posts); // Ngola   → 1 post
    }

    public function test_eager_hasMany_instances_are_correct_type(): void
    {
        $users = EagerUser::with('posts')->all();
        $this->assertContainsOnlyInstancesOf(EagerPost::class, $users[0]->posts);
    }

    public function test_eager_hasMany_relation_is_pre_cached(): void
    {
        $users = EagerUser::with('posts')->all();

        $ref = new \ReflectionProperty(Model::class, 'relations');
        $ref->setAccessible(true);

        foreach ($users as $user) {
            $this->assertArrayHasKey('posts', $ref->getValue($user));
        }
    }

    // ── with()->all() — hasOne ─────────────────────────────────────────────────

    public function test_eager_hasOne_loads_profile_for_users_that_have_one(): void
    {
        $users = EagerUser::with('profile')->all();

        $this->assertInstanceOf(EagerProfile::class, $users[0]->profile);
        $this->assertSame('Lead engineer', $users[0]->profile->bio);

        $this->assertInstanceOf(EagerProfile::class, $users[1]->profile);
        $this->assertSame('Designer', $users[1]->profile->bio);
    }

    public function test_eager_hasOne_sets_null_for_users_without_profile(): void
    {
        $users = EagerUser::with('profile')->all();
        // user 3 (Ngola) has no profile
        $this->assertNull($users[2]->profile);
    }

    // ── with()->all() — belongsTo ─────────────────────────────────────────────

    public function test_eager_belongsTo_loads_author_for_all_posts(): void
    {
        $posts = EagerPost::with('author')->all();

        $this->assertCount(4, $posts);
        foreach ($posts as $post) {
            $this->assertInstanceOf(EagerUser::class, $post->author);
        }
    }

    public function test_eager_belongsTo_author_names_are_correct(): void
    {
        $posts = EagerPost::with('author')->all('id ASC');

        $this->assertSame('António', $posts[0]->author->name);
        $this->assertSame('António', $posts[1]->author->name);
        $this->assertSame('Dadiva',  $posts[2]->author->name);
        $this->assertSame('Ngola',   $posts[3]->author->name);
    }

    // ── with()->find() ────────────────────────────────────────────────────────

    public function test_eager_with_find_loads_relation(): void
    {
        $user = EagerUser::with('posts')->find(1);

        $this->assertNotNull($user);
        $this->assertCount(2, $user->posts);
    }

    public function test_eager_with_find_returns_null_for_missing_record(): void
    {
        $this->assertNull(EagerUser::with('posts')->find(9999));
    }

    // ── Multiple relations ────────────────────────────────────────────────────

    public function test_eager_multiple_relations_loaded_in_one_with_call(): void
    {
        $users = EagerUser::with('posts', 'profile')->all();

        $ref = new \ReflectionProperty(Model::class, 'relations');
        $ref->setAccessible(true);

        foreach ($users as $user) {
            $relations = $ref->getValue($user);
            $this->assertArrayHasKey('posts',   $relations);
            $this->assertArrayHasKey('profile', $relations);
        }
    }

    // ── Throws for unknown relation ───────────────────────────────────────────

    public function test_eager_throws_for_unknown_relation(): void
    {
        $this->expectException(\BadMethodCallException::class);
        EagerUser::with('nonExistentRelation')->all();
    }

    // ── with() on empty result set ────────────────────────────────────────────

    public function test_eager_with_empty_result_set_does_not_crash(): void
    {
        $this->pdo->exec('DELETE FROM users');
        $empty = EagerUser::with('posts')->all();
        $this->assertSame([], $empty);
    }

    // ── hasMany returns empty array for parent with no children ───────────────

    public function test_eager_hasMany_returns_empty_array_for_childless_parent(): void
    {
        // Insert user with no posts
        $this->pdo->exec("INSERT INTO users (name, email) VALUES ('Ambrósio', 'ambrosio@example.com')");
        $users = EagerUser::with('posts')->all('id ASC');

        $lastUser = end($users);
        $this->assertSame([], $lastUser->posts);
    }
}
