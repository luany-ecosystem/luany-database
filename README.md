# luany/database

Database layer for the [Luany](https://github.com/luany-ecosystem) ecosystem.

Provides a PDO connection factory, a thin query builder, an ActiveRecord model base, and a pure migration engine — with **zero dependencies on `luany/framework`**.

---

## Requirements

- PHP 8.1+
- PDO extension
- PDO driver for your database (MySQL, SQLite, etc.)

---

## Installation

```bash
composer require luany/database
```

---

## Components

### Connection

PDO factory. Does not enforce singleton — that responsibility belongs to the application's `DatabaseServiceProvider`.

```php
use Luany\Database\Connection;

$connection = Connection::make([
    'host'     => '127.0.0.1',
    'port'     => '3306',
    'database' => 'myapp',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
]);

// Testing — wrap an existing PDO
$connection = Connection::fromPdo($pdo);
```

---

### QueryBuilder

Thin prepared-statement wrapper. Can be used standalone or is used internally by `Model`.

```php
use Luany\Database\QueryBuilder;

$qb = new QueryBuilder($connection);

// SELECT
$users = $qb->query('SELECT * FROM users WHERE active = ?', [1])->fetchAll();
$user  = $qb->query('SELECT * FROM users WHERE id = ?', [$id])->fetchOne();

// INSERT / UPDATE / DELETE
$affected = $qb->statement('UPDATE users SET name = ? WHERE id = ?', ['António', 1]);
```

---

### Result

Wraps a `PDOStatement` after execution.

```php
$result = $qb->query('SELECT * FROM users');

$result->fetchOne();           // ?array — first row or null
$result->fetchAll();           // array<int, array>
$result->fetchColumn(0);       // list<mixed> — single column
$result->fetchAllAs(User::class); // hydrated class instances
$result->rowCount();           // int
```

---

### Model

Lightweight ActiveRecord base. The model never resolves a connection itself — it receives one via `Model::setConnection()`, called by the application's `DatabaseServiceProvider` on boot.

```php
use Luany\Database\Model;

class User extends Model
{
    protected string $table    = 'users';
    protected array  $fillable = ['name', 'email', 'password'];
    protected array  $hidden   = ['password'];
}

// Wire connection once (done by DatabaseServiceProvider)
User::setConnection($connection);

// Query
$user  = User::find(1);
$users = User::all();
$found = User::where('active = ? AND role = ?', [1, 'admin']);
$first = User::firstWhere('email = ?', ['a@b.com']);
$count = User::count('active = ?', [1]);

// Create
$user = User::create(['name' => 'António', 'email' => 'a@b.com', 'password' => $hash]);

// Update
$user->name = 'Ngola';
$user->save();

// Delete
$user->delete();

// Serialise (respects $hidden)
$user->toArray();
$user->toJson();
```

---

### Migrations

The migration engine is intentionally split into three classes:

| Class | Responsibility |
|-------|---------------|
| `Migration` | Abstract base — implement `up()` and `down()` |
| `MigrationRepository` | Manages the `_migrations` tracking table |
| `MigrationRunner` | Executes pending migrations and handles rollbacks |

`MigrationRunner` is a **pure engine** — it knows nothing about CLI, HTTP, or the framework. The skeleton's `MigrateCommand` calls it directly.

**Creating a migration:**

```php
use Luany\Database\Migration\Migration;

class CreateUsersTable extends Migration
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name`       VARCHAR(100) NOT NULL,
                `email`      VARCHAR(150) NOT NULL UNIQUE,
                `password`   VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS `users`");
    }
}
```

**Running migrations programmatically:**

```php
use Luany\Database\Migration\MigrationRunner;

$runner = new MigrationRunner($pdo, '/path/to/database/migrations');

// Run pending
$runner->run(function (string $name, string $status) {
    echo "[{$status}] {$name}\n";
});

// Rollback last batch
$runner->rollback(function (string $name, string $status) {
    echo "[{$status}] {$name}\n";
});

// List pending without running
$pending = $runner->pending();

// Status of all migrations (ran + pending)
$status = $runner->status();
// [['name' => '...', 'ran' => true, 'batch' => 1], ...]

// Drop all tables (used by migrate:fresh)
$runner->dropAll($pdo);
```

---

## Testing

Tests use **SQLite in-memory** — no MySQL, no network, no `.env` required.

```bash
composer install
./vendor/bin/phpunit --testdox
```
```
OK (51 tests, 68 assertions)
```

---

## Architecture

```
src/
├── Connection.php
├── QueryBuilder.php
├── Result.php
├── Model.php
└── Migration/
    ├── Migration.php
    ├── MigrationRepository.php
    └── MigrationRunner.php
```

**Dependency rule:** zero `luany/framework` dependency. Pure PHP 8.1+ + PDO.

---

## Ecosystem

| Package | Description |
|---------|-------------|
| [luany/core](https://github.com/luany-ecosystem/luany-core) | HTTP primitives, Router, Middleware pipeline |
| [luany/lte](https://github.com/luany-ecosystem/luany-lte) | AST-based template engine |
| [luany/framework](https://github.com/luany-ecosystem/luany-framework) | Application, ServiceProvider, Kernel |
| **luany/database** | Database layer (this package) |
| [luany/luany](https://github.com/luany-ecosystem/luany) | Official application skeleton |

---

## License

MIT