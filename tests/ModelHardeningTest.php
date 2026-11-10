<?php

namespace Luany\Database\Tests;

use Luany\Database\Concerns\SoftDeletes;
use Luany\Database\Model;
use Luany\Database\Relations\HasMany;
use PHPUnit\Framework\Attributes\DataProvider;

class HardUnguarded extends Model
{
    protected string $table = 'accounts';
}

class HardOpen extends Model
{
    protected string $table = 'accounts';
    protected array $guarded = [];
}

class HardBlacklist extends Model
{
    protected string $table = 'accounts';
    protected array $guarded = ['is_admin'];
}

class HardAccount extends Model
{
    protected string $table = 'accounts';
    protected array $fillable = ['name'];

    public function notes(): HasMany
    {
        return $this->hasMany(HardNote::class, 'account_id');
    }
}

class HardNote extends Model
{
    use SoftDeletes;

    protected string $table = 'notes';
    protected array $fillable = ['account_id', 'body'];
}

class HardToken extends Model
{
    protected string $table = 'tokens';
    protected string $primaryKey = 'uuid';
    protected bool $incrementing = false;
    protected array $fillable = ['uuid', 'label'];
}

class ModelHardeningTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE accounts (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                name     TEXT,
                password TEXT,
                is_admin INTEGER NOT NULL DEFAULT 0,
                count    INTEGER
            )
        ');
        $this->pdo->exec('
            CREATE TABLE notes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER,
                body       TEXT,
                deleted_at TEXT
            )
        ');
        $this->pdo->exec('CREATE TABLE tokens (uuid TEXT PRIMARY KEY, label TEXT)');

        Model::setConnection($this->connection);
    }

    // ── Mass assignment (issue #3) ───────────────────────────────────────────

    public function test_model_without_fillable_rejects_mass_assignment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HardUnguarded::create(['name' => 'x', 'is_admin' => 1]);
    }

    public function test_fill_on_guarded_model_ignores_everything(): void
    {
        $m = new HardUnguarded();
        $m->fill(['name' => 'x', 'is_admin' => 1]);
        $this->assertNull($m->name);
        $this->assertNull($m->is_admin);
    }

    public function test_explicit_empty_guarded_allows_all(): void
    {
        $m = HardOpen::create(['name' => 'x', 'is_admin' => 1]);
        $this->assertSame(1, (int) $m->is_admin);
    }

    public function test_guarded_blacklist(): void
    {
        $m = HardBlacklist::create(['name' => 'x', 'is_admin' => 1]);
        $this->assertSame('x', $m->name);
        $this->assertSame(0, (int) $m->is_admin);
    }

    public function test_fillable_whitelist_blocks_other_columns(): void
    {
        $m = HardAccount::create(['name' => 'x', 'is_admin' => 1]);
        $this->assertSame(0, (int) $m->is_admin);
    }

    public function test_force_fill_bypasses_guard(): void
    {
        $m = (new HardUnguarded())->forceFill(['name' => 'root', 'is_admin' => 1]);
        $m->save();
        $this->assertSame(1, (int) HardUnguarded::find($m->getKey())->is_admin);
    }

    // ── save() persists direct assignments, only dirty columns (issue #4) ────

    public function test_save_persists_non_fillable_direct_assignment(): void
    {
        $m = HardAccount::create(['name' => 'x']);
        $m->password = 'NEW';
        $m->save();

        $this->assertSame('NEW', HardAccount::find($m->getKey())->password);
    }

    public function test_dirty_tracking(): void
    {
        $m = HardAccount::create(['name' => 'x']);
        $this->assertFalse($m->isDirty());

        $m->name = 'y';
        $this->assertTrue($m->isDirty('name'));
        $this->assertSame(['name' => 'y'], $m->getDirty());
        $this->assertSame('x', $m->getOriginal('name'));

        $m->save();
        $this->assertFalse($m->isDirty());
    }

    public function test_save_only_updates_dirty_columns(): void
    {
        $m = HardAccount::create(['name' => 'x']);
        // Another writer changes a different column…
        $this->pdo->exec("UPDATE accounts SET password = 'other' WHERE id = {$m->getKey()}");

        $m->name = 'y';
        $m->save();

        // …and our save must not clobber it.
        $row = $this->pdo->query("SELECT * FROM accounts WHERE id = {$m->getKey()}")->fetch();
        $this->assertSame('other', $row['password']);
        $this->assertSame('y', $row['name']);
    }

    public function test_save_empty_new_model_uses_default_values(): void
    {
        $m = new HardAccount();
        $this->assertTrue($m->save());
        $this->assertNotNull($m->getKey());
    }

    // ── Magic accessors (issue #5) ───────────────────────────────────────────

    public function test_column_named_like_base_method_returns_column_value(): void
    {
        $m = HardOpen::create(['name' => 'x', 'count' => 7]);
        HardOpen::create(['name' => 'y', 'count' => 1]);

        $this->assertSame(7, (int) HardOpen::find($m->getKey())->count);
    }

    public function test_isset_and_empty_on_relations(): void
    {
        $acc = HardAccount::create(['name' => 'x']);
        HardNote::create(['account_id' => $acc->getKey(), 'body' => 'a']);

        $fresh = HardAccount::find($acc->getKey());
        $this->assertTrue(isset($fresh->notes));
        $this->assertFalse(empty($fresh->notes));
        $this->assertFalse(isset($fresh->missing));
    }

    public function test_isset_on_null_attribute_is_false(): void
    {
        $acc = HardAccount::create(['name' => 'x']);
        $this->assertFalse(isset($acc->password));
        $this->assertTrue(isset($acc->name));
    }

    // ── Eager-load leak (issue #6) ───────────────────────────────────────────

    public function test_eager_load_does_not_leak_after_null_find(): void
    {
        $this->assertNull(HardAccount::with('notes')->find(999));

        // Must not try to load "notes" on an unrelated model.
        $this->assertSame([], HardNote::all());
    }

    // ── SoftDeletes scope everywhere (issue #7) ──────────────────────────────

    public function test_soft_deleted_rows_hidden_from_where_count_and_relations(): void
    {
        $acc  = HardAccount::create(['name' => 'x']);
        $keep = HardNote::create(['account_id' => $acc->getKey(), 'body' => 'keep']);
        $gone = HardNote::create(['account_id' => $acc->getKey(), 'body' => 'gone']);
        $gone->delete();

        $this->assertCount(1, HardNote::where('account_id = ?', [$acc->getKey()]));
        $this->assertSame(1, HardNote::count('account_id = ?', [$acc->getKey()]));
        $this->assertSame('keep', HardNote::firstWhere('account_id = ?', [$acc->getKey()])->body);
        $this->assertCount(1, HardAccount::find($acc->getKey())->notes);
        $this->assertCount(1, HardAccount::with('notes')->find($acc->getKey())->notes);
        $this->assertCount(1, HardNote::query()->where('account_id', '=', $acc->getKey())->get());
        $this->assertSame($keep->getKey(), HardNote::all()[0]->getKey());
    }

    public function test_where_conditions_are_parenthesised_with_scope(): void
    {
        $acc  = HardAccount::create(['name' => 'x']);
        $note = HardNote::create(['account_id' => $acc->getKey(), 'body' => 'a']);
        $note->delete();

        // "body = 'a' OR 1=1" must not escape the deleted_at scope.
        $this->assertCount(0, HardNote::where("body = ? OR 1 = 1", ['a']));
    }

    public function test_restore_on_unsaved_model_returns_false(): void
    {
        $this->assertFalse((new HardNote())->restore());
    }

    // ── Non-incrementing keys (issue #8) ─────────────────────────────────────

    public function test_create_with_string_primary_key(): void
    {
        $t = HardToken::create(['uuid' => 'a1b2-c3', 'label' => 'x']);

        $this->assertSame('a1b2-c3', $t->getKey());
        $this->assertSame('x', HardToken::find('a1b2-c3')->label);
    }

    // ── Foreign-key guessing (issue #12) ─────────────────────────────────────

    #[DataProvider('singularProvider')]
    public function test_singularize(string $plural, string $singular): void
    {
        $this->assertSame($singular, Model::singularize($plural));
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function singularProvider(): array
    {
        return [
            ['users', 'user'],
            ['addresses', 'address'],
            ['categories', 'category'],
            ['statuses', 'status'],
            ['boxes', 'box'],
            ['branches', 'branch'],
            ['people', 'person'],
            ['status', 'status'],
            ['news', 'news'],
        ];
    }
}
