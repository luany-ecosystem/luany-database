<?php

namespace Luany\Database\Tests;

use Luany\Database\Model;

// ── Concrete model for testing ────────────────────────────────────────────────

class ProductModel extends Model
{
    protected string $table    = 'products';
    protected array  $fillable = ['name', 'price'];
    protected array  $hidden   = ['internal_code'];
}

// ── Test suite ────────────────────────────────────────────────────────────────

class ModelTest extends TestCase
{
    protected function setUpSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE products (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          TEXT    NOT NULL,
                price         REAL    NOT NULL DEFAULT 0,
                internal_code TEXT
            )
        ');
        $this->pdo->exec("INSERT INTO products (name, price, internal_code) VALUES
            ('Widget', 9.99, 'WDG-001'),
            ('Gadget', 19.99, 'GDG-002'),
            ('Thingo', 4.99, 'THG-003')
        ");

        ProductModel::setConnection($this->connection);
    }

    // ── find() ────────────────────────────────────────────────────────────────

    public function test_find_returns_model_by_id(): void
    {
        $product = ProductModel::find(1);
        $this->assertInstanceOf(ProductModel::class, $product);
        $this->assertSame('Widget', $product->name);
    }

    public function test_find_returns_null_for_missing_id(): void
    {
        $this->assertNull(ProductModel::find(9999));
    }

    // ── all() ─────────────────────────────────────────────────────────────────

    public function test_all_returns_all_records(): void
    {
        $products = ProductModel::all();
        $this->assertCount(3, $products);
        $this->assertContainsOnlyInstancesOf(ProductModel::class, $products);
    }

    public function test_all_accepts_valid_order_by(): void
    {
        $products = ProductModel::all('name ASC');
        $this->assertCount(3, $products);
        $this->assertSame('Gadget', $products[0]->name);
    }

    public function test_all_accepts_multiple_order_columns(): void
    {
        $products = ProductModel::all('price DESC, name ASC');
        $this->assertCount(3, $products);
        $this->assertSame('Gadget', $products[0]->name);
    }

    public function test_all_rejects_sql_injection_in_order_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductModel::all('name; DROP TABLE products;--');
    }

    public function test_all_rejects_subquery_in_order_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductModel::all('(SELECT 1)');
    }

    public function test_all_rejects_special_characters_in_order_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductModel::all('name OR 1=1');
    }

    // ── where()

    public function test_where_filters_correctly(): void
    {
        $products = ProductModel::where('price < ?', [10.0]);
        $this->assertCount(2, $products);
    }

    public function test_where_returns_empty_array_when_no_match(): void
    {
        $this->assertSame([], ProductModel::where('name = ?', ['Nonexistent']));
    }

    // ── firstWhere() ──────────────────────────────────────────────────────────

    public function test_firstWhere_returns_first_match(): void
    {
        $product = ProductModel::firstWhere('name = ?', ['Gadget']);
        $this->assertNotNull($product);
        $this->assertSame('Gadget', $product->name);
    }

    public function test_firstWhere_returns_null_when_no_match(): void
    {
        $this->assertNull(ProductModel::firstWhere('name = ?', ['Ghost']));
    }

    // ── count() ──────────────────────────────────────────────────────────────

    public function test_count_returns_total(): void
    {
        $this->assertSame(3, ProductModel::count());
    }

    public function test_count_with_conditions(): void
    {
        $this->assertSame(2, ProductModel::count('price < ?', [10.0]));
    }

    // ── create() ─────────────────────────────────────────────────────────────

    public function test_create_inserts_and_returns_model(): void
    {
        $product = ProductModel::create(['name' => 'Doohickey', 'price' => 2.50]);
        $this->assertInstanceOf(ProductModel::class, $product);
        $this->assertSame('Doohickey', $product->name);
        $this->assertTrue($product->exists());
    }

    public function test_create_throws_when_no_fillable_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductModel::create(['internal_code' => 'X-999']); // not in $fillable
    }

    // ── save() — update ───────────────────────────────────────────────────────

    public function test_save_updates_existing_record(): void
    {
        $product        = ProductModel::find(1);
        $product->price = 99.99;
        $product->save();

        $refreshed = ProductModel::find(1);
        $this->assertSame(99.99, (float) $refreshed->price);
    }

    // ── delete() ─────────────────────────────────────────────────────────────

    public function test_delete_removes_record(): void
    {
        $product = ProductModel::find(2);
        $result  = $product->delete();
        $this->assertTrue($result);
        $this->assertNull(ProductModel::find(2));
    }

    public function test_delete_returns_false_on_new_instance(): void
    {
        $product = new ProductModel();
        $this->assertFalse($product->delete());
    }

    // ── $hidden ───────────────────────────────────────────────────────────────

    public function test_toArray_excludes_hidden_columns(): void
    {
        $product = ProductModel::find(1);
        $array   = $product->toArray();
        $this->assertArrayNotHasKey('internal_code', $array);
        $this->assertArrayHasKey('name', $array);
    }

    // ── setConnection not called ──────────────────────────────────────────────

    public function test_throws_when_no_connection_set(): void
    {
        // Create a fresh anonymous model class with no connection
        $model = new class extends Model {
            protected string $table = 'x';
        };

        // Reset connection via reflection
        $ref = new \ReflectionProperty(Model::class, 'connection');
        $ref->setAccessible(true);
        $original = $ref->getValue();
        $ref->setValue(null, null);

        $this->expectException(\LogicException::class);
        $model::find(1);

        // Restore
        $ref->setValue(null, $original);
    }
}