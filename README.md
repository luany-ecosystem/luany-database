# luany/database

**PDO connection, fluent Query Builder, Active Record ORM, Relations, Pagination, Soft Deletes, and Migration engine for the Luany ecosystem.**

**Version**: v0.3.0 &nbsp;|&nbsp; **PHP**: ≥ 8.1 &nbsp;|&nbsp; **License**: MIT  
**Author**: António Ambrósio Ngola &nbsp;|&nbsp; **Org**: [luany-ecosystem](https://github.com/luany-ecosystem)

---

## Table of Contents

1. [Installation](#1-installation)
2. [Connection](#2-connection)
3. [QueryBuilder](#3-querybuilder)
   - [Fluent API](#31-fluent-api)
   - [Aggregates](#32-aggregates)
   - [Pagination](#33-pagination)
   - [Raw Methods](#34-raw-methods)
4. [Model](#4-model)
   - [Defining a Model](#41-defining-a-model)
   - [CRUD](#42-crud)
   - [Relationships](#43-relationships)
   - [Eager Loading](#44-eager-loading)
   - [Soft Deletes](#45-soft-deletes)
5. [Transactions](#5-transactions)
6. [Migrations](#6-migrations)
7. [Changelog](#7-changelog)

---

## 1. Installation

```bash
composer require luany/database
```

---

## 2. Connection

`Connection` is a PDO wrapper. It does **not** enforce a singleton — that responsibility belongs to the application's `DatabaseServiceProvider`.

```php
use Luany\Database\Connection;

// Create from config array
$connection = Connection::make([
    'host'     => '127.0.0.1',
    'port'     => '3306',
    'database' => 'my_app',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
]);

// Create from an existing PDO (useful in tests)
$connection = Connection::fromPdo($pdo);

// Access the underlying PDO instance
$pdo = $connection->getPdo();
```

### Connection Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `make` | `static make(array $config): static` | Create from config array |
| `fromPdo` | `static fromPdo(\PDO $pdo): static` | Wrap an existing PDO |
| `getPdo` | `getPdo(): \PDO` | Get the underlying PDO |
| `execute` | `execute(string $sql, array $bindings = []): \PDOStatement` | Prepare and execute |
| `lastInsertId` | `lastInsertId(): string` | Last inserted row ID |
| `beginTransaction` | `beginTransaction(): bool` | Begin a transaction |
| `commit` | `commit(): bool` | Commit the transaction |
| `rollBack` | `rollBack(): bool` | Roll back the transaction |
| `transaction` | `transaction(callable $callback): mixed` | Execute callback in a transaction |
| `inTransaction` | `inTransaction(): bool` | Whether inside a transaction |

---

## 3. QueryBuilder

Fluent query builder with full prepared-statement safety. All user-supplied values are bound — never interpolated.

```php
use Luany\Database\QueryBuilder;

$qb = new QueryBuilder($connection);
```

### 3.1 Fluent API

#### SELECT

```php
// All rows
$users = $qb->table('users')->get();

// With column selection
$users = $qb->table('users')
    ->select('id', 'name', 'email')
    ->get();

// First row or null
$user = $qb->table('users')
    ->where('email', '=', 'antonio@example.com')
    ->first();
```

#### WHERE Clauses

```php
// AND WHERE (default)
$qb->table('users')->where('age', '>', 18)->where('active', '=', 1)->get();

// OR WHERE
$qb->table('users')
    ->where('city', '=', 'Luanda')
    ->orWhere('city', '=', 'Lisbon')
    ->get();

// WHERE IN
$qb->table('users')->whereIn('id', [1, 2, 3])->get();

// WHERE NULL / NOT NULL
$qb->table('users')->whereNull('deleted_at')->get();
$qb->table('users')->whereNotNull('email_verified_at')->get();
```

#### ORDER BY, LIMIT, OFFSET

```php
$qb->table('users')
    ->orderBy('name', 'ASC')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->offset(20)
    ->get();
```

#### INSERT

```php
$qb->table('users')->insert([
    'name'  => 'António',
    'email' => 'antonio@example.com',
]);

$lastId = $qb->lastInsertId();
```

#### UPDATE

```php
$affected = $qb->table('users')
    ->where('id', '=', 42)
    ->update(['name' => 'António Ngola']);
// returns int — number of affected rows
```

#### DELETE

```php
$affected = $qb->table('users')
    ->where('id', '=', 42)
    ->delete();
// returns int — number of affected rows
```

### 3.2 Aggregates

```php
$total = $qb->table('users')->count();

$filtered = $qb->table('users')
    ->where('active', '=', 1)
    ->count();

$exists = $qb->table('users')
    ->where('email', '=', 'antonio@example.com')
    ->exists(); // bool
```

### 3.3 Pagination

`paginate()` executes **two queries**: one `COUNT(*)` to get the total, one `SELECT` with `LIMIT`/`OFFSET` for the page data. All `WHERE` and `ORDER BY` clauses are respected by both queries.

```php
$page = $qb->table('users')
    ->where('active', '=', 1)
    ->orderBy('name', 'ASC')
    ->paginate(perPage: 15, page: 2);

// PaginationResult properties
$page->data;        // array of rows for this page
$page->total;       // total matching rows across ALL pages
$page->perPage;     // 15
$page->currentPage; // 2
$page->lastPage;    // ceil(total / perPage)
$page->from;        // first row number on this page (1-based), null if empty
$page->to;          // last row number on this page (1-based), null if empty
$page->hasMore();   // bool — true if there is a next page
$page->hasPrev();   // bool — true if there is a previous page

// Serialize for JSON API responses
$array = $page->toArray();
// keys: data, total, per_page, current_page, last_page, from, to, has_more, has_prev
```

**Edge cases handled:**
- `$page < 1` is clamped to `1`
- `$perPage < 1` throws `\InvalidArgumentException`
- Empty result set: `total=0`, `lastPage=1`, `from=null`, `to=null`

### 3.4 Raw Methods

Available for backward-compatibility and complex queries that the fluent API cannot express.

```php
// Raw SELECT → returns Result
$result = $qb->raw('SELECT * FROM users WHERE age > ? AND city = ?', [18, 'Luanda']);
$rows   = $result->fetchAll();
$row    = $result->fetchOne();    // ?array
$col    = $result->fetchColumn(); // array of values from first column

// Alias: query()
$result = $qb->query('SELECT COUNT(*) FROM users');

// Raw INSERT / UPDATE / DELETE → returns affected row count
$affected = $qb->statement('DELETE FROM sessions WHERE expires_at < ?', [time()]);

// Last inserted ID
$id = $qb->lastInsertId();
```

---

## 4. Model

`Model` is an abstract Active Record base class. Extend it to define your application models.

### 4.1 Defining a Model

```php
namespace App\Models;

use Luany\Database\Model;

class User extends Model
{
    protected string $table      = 'users';
    protected string $primaryKey = 'id';       // default: 'id'
    protected array  $fillable   = ['name', 'email', 'password'];
    protected array  $hidden     = ['password']; // excluded from toArray() / toJson()
}
```

**Register the connection** (done once in your `DatabaseServiceProvider`):

```php
User::setConnection($connection);

// Or pass a lazy closure (resolved on first use):
User::setConnection(fn() => Connection::make($config));
```

### 4.2 CRUD

```php
// Find by primary key — returns ?static
$user = User::find(42);

// All records (ORDER BY is validated against a strict whitelist)
$users = User::all();
$users = User::all('name ASC, created_at DESC');

// Raw WHERE clause
$users = User::where('active = ? AND age > ?', [1, 18]);
$user  = User::firstWhere('email = ?', ['antonio@example.com']); // ?static

// Count
$total    = User::count();
$filtered = User::count('active = ?', [1]);

// Create — inserts and returns hydrated instance
$user = User::create(['name' => 'António', 'email' => 'antonio@example.com']);

// Update — on an instance
$user->name = 'António Ngola';
$user->save();

// Shorthand update
$user->update(['name' => 'António Ngola', 'email' => 'new@example.com']);

// Delete — hard-delete (overridden by SoftDeletes)
$user->delete(); // bool

// Instance state
$user->exists();   // bool — true if loaded from / saved to DB
$user->toArray();  // respects $hidden
$user->toJson();   // JSON string, respects $hidden

// Attribute access (magic)
echo $user->name;
$user->name = 'Ngola';

// Mass-assign fillable attributes
$user->fill(['name' => 'Ngola', 'email' => 'ngola@example.com']);
```

### 4.3 Relationships

Define relationship methods on your model. They return **Relation descriptor objects** — no query is executed when the method is called. The query runs only on property access (lazy) or via `with()` (eager).

#### hasOne — one-to-one (FK on related table)

```php
// In User model:
use Luany\Database\Relations\HasOne;

public function profile(): HasOne
{
    return $this->hasOne(Profile::class, 'user_id');
    //                                    ^ FK on profiles table
}

// Usage:
$user    = User::find(1);
$profile = $user->profile;   // ?Profile — lazy-loaded and cached
```

#### hasMany — one-to-many (FK on related table)

```php
// In User model:
use Luany\Database\Relations\HasMany;

public function posts(): HasMany
{
    return $this->hasMany(Post::class, 'user_id');
}

// Usage:
$user  = User::find(1);
$posts = $user->posts;   // Post[] — lazy-loaded and cached
```

#### belongsTo — inverse (FK on THIS table)

```php
// In Post model:
use Luany\Database\Relations\BelongsTo;

public function author(): BelongsTo
{
    return $this->belongsTo(User::class, 'user_id');
    //                                    ^ FK on THIS (posts) table
}

// Usage:
$post   = Post::find(1);
$author = $post->author;   // ?User — lazy-loaded and cached
```

**Key signatures:**

```php
// hasOne($related, $foreignKey = null, $localKey = null)
// hasMany($related, $foreignKey = null, $localKey = null)
// belongsTo($related, $foreignKey = null, $ownerKey = null)
```

All FK/owner-key arguments are optional. Defaults follow the convention `{table_singular}_id` (e.g. `users` → `user_id`). Pass explicit keys for irregular plurals or non-standard schemas.

**Relation results are cached** after the first access:

```php
$user->posts; // query executed
$user->posts; // returns cached result — no second query
```

**Manual relation management:**

```php
$user->getRelation('posts');           // load and cache
$user->setRelation('posts', $myArray); // set directly (bypasses query)
```

### 4.4 Eager Loading

Prevents N+1 queries. Instead of executing one query per model, `with()` executes **one batched `WHERE IN` query** per relation for the entire collection.

```php
// Load all users with their posts and profile — 3 queries total (not 1 + N + N)
$users = User::with('posts', 'profile')->all();

foreach ($users as $user) {
    echo $user->name;
    echo count($user->posts);  // already loaded — no extra query
    echo $user->profile?->bio; // already loaded — no extra query
}

// Also works with find()
$user = User::with('posts')->find(1);

// Accepts ORDER BY
$users = User::with('posts')->all('name ASC');
```

`with()` returns an `EagerProxy` that delegates to `all()` and `find()`. After execution, the eager-load state is consumed and reset — no cross-request leakage.

### 4.5 Soft Deletes

Add the `SoftDeletes` trait to a model. Requires a `deleted_at DATETIME DEFAULT NULL` column.

```php
namespace App\Models;

use Luany\Database\Model;
use Luany\Database\Concerns\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;

    protected string $table    = 'articles';
    protected array  $fillable = ['title', 'body'];

    // Optional: override the column name (default: 'deleted_at')
    protected string $deletedAtColumn = 'deleted_at';
}
```

**Migration:**

```sql
ALTER TABLE articles ADD COLUMN deleted_at DATETIME DEFAULT NULL;
```

**Behaviour:**

```php
// Standard queries automatically exclude soft-deleted records
$articles = Article::all();         // only WHERE deleted_at IS NULL
$article  = Article::find(1);       // null if soft-deleted
$count    = Article::count();       // excludes soft-deleted

// Soft-delete (sets deleted_at — row stays in DB)
$article->delete();
$article->trashed(); // true

// Restore
$article->restore();
$article->trashed(); // false

// Permanently remove the row
$article->forceDelete();

// Include soft-deleted records
$all     = Article::withTrashed();
$all     = Article::withTrashed('title DESC');

// Only soft-deleted records
$trashed = Article::onlyTrashed();
$trashed = Article::onlyTrashed('deleted_at ASC');
```

---

## 5. Transactions

```php
// Manual
$connection->beginTransaction();

try {
    $connection->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]);
    $connection->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, 2]);
    $connection->commit();
} catch (\Throwable $e) {
    $connection->rollBack();
    throw $e;
}

// Callback (auto-commit on success, auto-rollback on exception)
$result = $connection->transaction(function (Connection $conn) {
    $conn->execute('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [1]);
    $conn->execute('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [2]);
    return 'transferred';
});
// $result === 'transferred'

// Check state
$connection->inTransaction(); // bool
```

---

## 6. Migrations

Migrations live in `database/migrations/` and are ordered by filename timestamp.

### Defining a Migration

```php
use Luany\Database\Migration\Migration;

class CreateUsersTable extends Migration
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name`       VARCHAR(255) NOT NULL,
                `email`      VARCHAR(150) NOT NULL UNIQUE,
                `deleted_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS `users`");
    }
}
```

### MigrationRunner

```php
use Luany\Database\Migration\MigrationRunner;

$runner = new MigrationRunner($connection, '/path/to/database/migrations');

// Run all pending migrations
$runner->run(function (string $name) {
    echo "Migrated: {$name}\n";
});

// Roll back last batch
$runner->rollback(function (string $name) {
    echo "Rolled back: {$name}\n";
});

// Inspect status
$status = $runner->status();
// [['name' => '...', 'ran' => true, 'batch' => 1], ...]

// Drop all tables and re-run (dev use only)
$runner->dropAll($connection->getPdo());
$runner->run();
```

The `_migrations` table is created automatically on first use.

---

## 7. Changelog

### v0.3.0 — Phase 3: Relations, Eager Loading, Pagination, Soft Deletes

**New — `src/Relations/`**
- `Relation` — abstract base with `getResults()` and `batchLoad()` contracts
- `HasOne` — one-to-one relation with true eager batch loading (WHERE IN)
- `HasMany` — one-to-many relation with true eager batch loading (WHERE IN + group by FK)
- `BelongsTo` — inverse relation with true eager batch loading (WHERE IN + key by owner key)

**New — `src/Concerns/SoftDeletes.php`**
- Trait: overrides `newQuery()` to add `WHERE deleted_at IS NULL` scope
- Adds `delete()` (sets `deleted_at`), `forceDelete()`, `restore()`, `trashed()`
- Adds static `withTrashed()` and `onlyTrashed()` (both accept ORDER BY)

**New — `src/EagerProxy.php`**
- Returned by `Model::with()` — delegates `all()` and `find()` with eager-load injection
- No cross-request state leakage (eager-load list consumed and reset after each query)

**New — `src/PaginationResult.php`**
- Immutable value object: `data`, `total`, `perPage`, `currentPage`, `lastPage`, `from`, `to`
- Methods: `hasMore()`, `hasPrev()`, `toArray()`

**Modified — `src/QueryBuilder.php`**
- Added `paginate(int $perPage = 15, int $page = 1): PaginationResult`
- Two queries per call: COUNT(*) + SELECT with LIMIT/OFFSET
- Page clamped to ≥ 1; `perPage < 1` throws `\InvalidArgumentException`

**Modified — `src/Model.php`**
- `$attributes`, `$relations`, `$exists` changed from `private` to `protected` (required by `SoftDeletes` trait)
- `getAttribute()` changed from `protected` to `public` (required by Relation classes)
- `validateOrderBy()` changed from `private` to `protected` (required by `SoftDeletes` trait)
- Added `public static hydrateFromRow(array $row): static` (required by Relation classes)
- Added `public static setEagerLoad(array $relations): void` (used by `EagerProxy`)
- `with()` now returns `EagerProxy` instead of a plain instance
- Relationship methods (`hasOne`, `hasMany`, `belongsTo`) now return typed `Relation` descriptor objects instead of executing queries directly
- `getRelation()` now resolves `Relation` descriptors via `getResults()` (lazy load with cache)
- `eagerLoadRelation()` now delegates to `Relation::batchLoad()` (true N+1-free eager loading)
- Added `fill(array $attributes): void`

**Tests added:** `RelationsTest` (15), `EagerLoadTest` (13), `PaginateTest` (27), `SoftDeletesTest` (21)  
**Total: 170 tests, 274 assertions — all green.**

---

### v0.2.0 — Phase 1: Fluent QueryBuilder & Transactions

**New — `QueryBuilder` fluent API**
- `table()`, `select()`, `where()`, `orWhere()`, `whereIn()`, `whereNull()`, `whereNotNull()`
- `orderBy()`, `limit()`, `offset()`
- `get()`, `first()`, `insert()`, `update()`, `delete()`, `count()`, `exists()`
- `raw()` / `query()` / `statement()` preserved for backward-compatibility
- All queries use prepared statements — no string interpolation of user values

**New — `Connection` transaction methods**
- `beginTransaction()`, `commit()`, `rollBack()`, `inTransaction()`
- `transaction(callable)` — auto-commit / auto-rollback wrapper

**New — `Connection::fromPdo()` and `Connection::make()`**
- Replaces the legacy `configure()` / `getInstance()` singleton pattern

**Modified — `Model`**
- `find()`, `all()`, `create()`, `save()`, `delete()` refactored to use the fluent QueryBuilder internally
- `all()` ORDER BY clause validated against a strict regex whitelist (SQL injection prevention)
- Added `where()`, `firstWhere()`, `count()` static methods
- Added `setConnection()` / `getConnection()` (replaces global singleton)

**Tests:** 94 tests, 136 assertions.

---

### v0.1.3 and earlier

Initial release. Raw-query-only `QueryBuilder`. Basic `Model` with hand-built SQL strings. `MigrationRunner`, `MigrationRepository`, `Migration` base class.