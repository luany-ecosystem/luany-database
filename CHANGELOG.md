# Changelog — luany/database

All notable changes to this package are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.1.0] — 2026-04-11

### Added

- `src/Seeder/Seeder.php` — abstract base class for all seeders. Defines `run(\PDO $pdo): void` contract. Exposes `call(string ...$seederClasses): void` for chaining seeders from within `DatabaseSeeder`. PDO injected via `setPdo()` before `run()` — managed by `SeederRunner`, not intended for manual use.
- `src/Seeder/SeederRunner.php` — discovers and executes seeders from a given directory. Loads all PHP files in the seeders directory before running so `call()` chains always resolve. Accepts an optional output callback `fn(string $class, string $status): void`. Throws `\RuntimeException` if the target class is not found or does not extend `Seeder`.
- `tests/Seeder/SeederRunnerTest.php` — 9 tests covering: single seeder execution, output callback, class-not-found, non-Seeder class, `call()` chaining, broken chain, and `loadAll()` ensuring all files are available before any seeder runs.

**Tests: 178 → 187. Assertions: 284 → 296. All green.**

## [1.0.0] — 2026-03-23

No changes in this phase. Package is stable at v0.3.0.

---

## [0.3.0] — Phase 3

### Added

- `QueryBuilder` — fluent SQL builder. `table()`, `select()`, `where()`, `orWhere()`, `whereIn()`, `whereNull()`, `whereNotNull()`, `orderBy()`, `limit()`, `offset()`, `get()`, `first()`, `find()`, `count()`, `exists()`, `insert()`, `update()`, `delete()`.
- `Model` — Active Record base class. `$table`, `$primaryKey`, `$fillable`, `$hidden`, `$casts`, `$timestamps`. Static methods: `all()`, `find()`, `create()`, `where()`. Instance methods: `save()`, `delete()`, `fill()`, `toArray()`.
- Relations — `hasOne()`, `hasMany()`, `belongsTo()`. Lazy-loaded via magic property access.
- `Paginator` — `paginate(int $perPage)` returns `PaginatorResult` with `items`, `total`, `perPage`, `currentPage`, `lastPage`, `hasMorePages()`, `links()`.
- Soft deletes — `SoftDelete` trait. `delete()` sets `deleted_at` instead of removing the row. `withTrashed()`, `onlyTrashed()`, `restore()`, `forceDelete()`.
- `Connection` — `Connection::make(array $config)` static factory. Wraps PDO with `getPdo()`, `table()` (returns QueryBuilder), `transaction(callable)`, `beginTransaction()`, `commit()`, `rollback()`.
- `Migration` — base class for migrations with `up(PDO $pdo)` and `down(PDO $pdo)`.
- `MigrationRunner` — discovers and runs migration files. `run()`, `rollback()`, `fresh()`, `status()`. Tracks batches in `_migrations` table.
- Type casting in `Model` — `$casts = ['active' => 'bool', 'price' => 'float', 'qty' => 'int']`.

### Fixed

- N+A — initial implementation, no prior bugs.
