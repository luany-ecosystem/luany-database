# Changelog — luany/database

All notable changes to this package are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased] — 1.5.0

### Added
- **Model events** — `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`, `restoring`, `restored`; static registration (`Post::creating(...)`), `observe()`, `booted()` hook; `-ing` listeners returning `false` cancel the operation. (#14)
- **`hasManyThrough`** — distant one-to-many via an intermediate table, lazy and eager (one JOIN query). (#14)
- **Polymorphic relations** — `morphMany`, `morphOne`, `morphTo` (name inferred from the method), `associate()`, `save()` / `create()` on morph relations, eager loading with one query per type, `Model::morphMap()` aliases. (#14)
- Nested eager loading groups children by class (works through `morphTo`).

---

## [1.4.1] — 2026-11-11

### Fixed
- `datetime` / `date` / `timestamp` casts normalise string input on write (e.g. `2026-11-02T10:30` from `<input type="datetime-local">` is stored as `2026-11-02 10:30:00`), so stored values share one format and sort/compare correctly in SQL. Unparseable values throw `InvalidArgumentException` naming the attribute.

---

## [1.4.0] — 2026-11-11

### Added
- **Joins** — `join()`, `leftJoin()` (column-to-column, identifiers validated), table aliases (`'posts AS p'`). (#14)
- **Grouping** — `groupBy()`, `having()`, `havingRaw()`, `distinct()`; `count()` and `paginate()` honour joins, DISTINCT and GROUP BY. (#14)
- **Aggregates** — `sum()`, `avg()`, `min()`, `max()` on QueryBuilder and model queries. (#14)
- **`belongsToMany`** — many-to-many through a pivot table with conventional defaults, `withPivot()`, `->pivot`, `attach()`, `detach()`, `sync()` (transactional), lazy and eager loading. (#14)
- **Nested eager loading** — `with('posts.comments.author')`, one query per level. (#14)

### Fixed
- Integer bindings are sent as integers on MySQL and SQLite; on SQLite, comparing an aggregate with a text parameter (`SUM(x) > '10'`) was always false.

### Changed
- `update()` / `delete()` throw on joined queries instead of producing invalid SQL.

---

## [1.3.0] — 2026-11-11

### Added
- **`ModelQuery`** — fluent model queries returning hydrated models: every QueryBuilder method plus `with()`, `get()`, `all()`, `first()`, `firstOrFail()`, `find()`, `findOrFail()`, `paginate()` (models in `data`), `pluck()`, `toBase()`. Global scopes (SoftDeletes) apply. (#14)
- **Local scopes** — `scopeXxx(ModelQuery $q, ...$args)` methods, callable statically (`Post::published()`) or chained. (#14)
- **Attribute casting** — `$casts` with `int`, `float`, `decimal:N`, `bool`, `string`, `array`/`json`, `datetime`, `date`, `timestamp` and custom `CastsAttributes` classes; `getRawAttribute()`, `$dateFormat`. (#14)
- **Timestamps** — opt-in `$timestamps = true`, overridable `CREATED_AT` / `UPDATED_AT` (or `null`), `touch()`, `freshTimestamp()`. (#14)
- `Model::findOrFail()` and `ModelNotFoundException`.
- `Model::setAttribute()` is now public and applies casts.

### Changed
- **`Model::query()` returns a `ModelQuery`** (models) instead of a `QueryBuilder` (rows). It was introduced in 1.2.0 one day earlier; use `->toBase()` for raw rows.
- `Model::with()` returns a `ModelQuery`; `->all()` and `->find()` keep working. `EagerProxy` is no longer used.
- Dirty tracking treats numerically equal values as unchanged ("5" vs 5).

---

## [1.2.0] — 2026-11-10

### Security

- **SQL injection through identifiers and operators fixed (#2).** Tables, columns, aliases and `insert`/`update` keys are validated and quoted by a new driver-aware `Grammar`; operators are whitelisted. Invalid input throws `InvalidArgumentException`.
- **Mass assignment now fails closed (#3).** A model with an empty `$fillable` is fully guarded (`$guarded = ['*']`). Opt in with `$fillable`, a `$guarded` blacklist, or `$guarded = []`.
- `SoftDeletes` is applied to `Model::where()`, `firstWhere()`, `count()`, and lazy/eager relations (#7). Raw conditions are parenthesised so `OR` cannot escape the scope.

### Added

- `QueryBuilder`: `selectRaw()`, `whereRaw()`, `orWhereRaw()`, `orderByRaw()`, `whereNested()`, `orWhereNested()`, `whereNotIn()`, `orderByDesc()`.
- `Model`: `query()`, `update()`, `refresh()`, `forceFill()`, `isFillable()`, `getKey()`, `getDirty()`, `isDirty()`, `getOriginal()`, `$guarded`, `$incrementing`, `$keyType`, `singularize()`.
- `Connection`: nested transactions with savepoints, `transactionLevel()`, `getDriverName()`, `getGrammar()`; `make()` supports `driver` = `mysql` | `pgsql` | `sqlite`.
- Migrations: anonymous-class migrations, `rollback($output, $steps)`, `reset()`, PostgreSQL support.
- `LICENSE` (MIT).

### Fixed

- `where(col, '=', null)` compiles to `IS NULL` (#9).
- `save()` persists directly assigned attributes and updates only dirty columns (#4).
- Columns named like base methods (`count`, `delete`, …) are read correctly; `isset()`/`empty()` work on relations (#5).
- Eager-load state can no longer leak into later queries (#6).
- `create()` works with UUID / string primary keys (#8).
- Nested `transaction()` no longer throws; the original exception is never masked by a failed rollback (#10).
- `dropAll()` works on SQLite; migrations run atomically on SQLite/PostgreSQL; duplicate class names give a clear error; any timestamp format is accepted (#11).
- Foreign-key guessing handles `addresses`, `categories`, `statuses`, … (#12).

### Upgrade notes

- Models that relied on an empty `$fillable` accepting every column must now declare `$fillable` (recommended) or `$guarded = []`.
- Identifiers that are not plain `[A-Za-z_][A-Za-z0-9_]*` (optionally `table.column` / `AS alias`) now throw. Move expressions to the `*Raw()` methods.

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
