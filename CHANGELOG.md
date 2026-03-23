# Changelog — luany/database

All notable changes to this package are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

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
