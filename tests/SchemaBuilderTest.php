<?php

namespace Luany\Database\Tests;

use Luany\Database\Grammar;
use Luany\Database\Schema\Blueprint;
use Luany\Database\Schema\Grammars\MySqlSchemaGrammar;
use Luany\Database\Schema\Grammars\PostgresSchemaGrammar;
use Luany\Database\Schema\Schema;

class SchemaBuilderTest extends TestCase
{
    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->schema = Schema::for($this->pdo);
    }

    private function postsBlueprint(): \Closure
    {
        return function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->string('slug')->unique();
            $t->text('body')->nullable();
            $t->decimal('price', 8, 2)->default(0);
            $t->boolean('published')->default(false);
            $t->enum('status', ['draft', 'live'])->default('draft');
            $t->json('meta')->nullable();
            $t->timestamp('seen_at')->nullable()->useCurrent();
            $t->morphs('owner');
            $t->timestamps();
            $t->softDeletes();
        };
    }

    public function test_create_alter_and_introspect_on_sqlite(): void
    {
        $this->schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
        });
        $this->schema->create('posts', $this->postsBlueprint());

        $this->assertTrue($this->schema->hasTable('posts'));
        $this->assertTrue($this->schema->hasColumn('posts', 'owner_type'));
        $this->assertFalse($this->schema->hasColumn('posts', 'nope'));

        $this->pdo->exec("INSERT INTO users (email) VALUES ('a@x.ao')");
        $this->pdo->exec("INSERT INTO posts (user_id, title, slug, owner_type, owner_id) VALUES (1, 't', 's', 'x', 1)");
        $row = $this->pdo->query('SELECT price, published, status FROM posts')->fetch();
        $this->assertSame([0, 0, 'draft'], [(int) $row['price'], (int) $row['published'], $row['status']]);

        // unique index enforced
        try {
            $this->pdo->exec("INSERT INTO posts (user_id, title, slug, owner_type, owner_id) VALUES (1, 't', 's', 'x', 1)");
            $this->fail('Expected unique violation');
        } catch (\PDOException) {
        }

        // enum CHECK enforced on SQLite
        try {
            $this->pdo->exec("INSERT INTO posts (user_id, title, slug, status, owner_type, owner_id) VALUES (1, 't', 's2', 'bogus', 'x', 1)");
            $this->fail('Expected CHECK violation');
        } catch (\PDOException) {
        }

        // foreign key with cascade
        $this->pdo->exec('DELETE FROM users');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());

        $this->schema->table('posts', function (Blueprint $t) {
            $t->string('subtitle')->nullable();
            $t->renameColumn('body', 'content');
            $t->dropColumn('meta');
            $t->index('subtitle');
        });
        $columns = $this->schema->getColumnListing('posts');
        $this->assertContains('subtitle', $columns);
        $this->assertContains('content', $columns);
        $this->assertNotContains('meta', $columns);

        $this->schema->table('posts', fn (Blueprint $t) => $t->dropIndex('subtitle'));

        $this->schema->rename('posts', 'articles');
        $this->assertTrue($this->schema->hasTable('articles'));
        $this->schema->dropIfExists('articles');
        $this->assertFalse($this->schema->hasTable('articles'));
        $this->schema->dropIfExists('articles');
    }

    public function test_mysql_ddl(): void
    {
        $blueprint = new Blueprint('posts');
        ($this->postsBlueprint())($blueprint);
        $sql = (new MySqlSchemaGrammar(new Grammar('mysql'), $this->pdo))->compileCreate($blueprint);

        $create = $sql[0];
        $this->assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $create);
        $this->assertStringContainsString('`user_id` BIGINT UNSIGNED NOT NULL', $create);
        $this->assertStringContainsString('`title` VARCHAR(150) NOT NULL', $create);
        $this->assertStringContainsString('`price` DECIMAL(8, 2) NOT NULL DEFAULT 0', $create);
        $this->assertStringContainsString('`published` TINYINT(1) NOT NULL DEFAULT 0', $create);
        $this->assertStringContainsString("`status` ENUM('draft', 'live') NOT NULL DEFAULT 'draft'", $create);
        $this->assertStringContainsString('`meta` JSON NULL', $create);
        $this->assertStringContainsString('`seen_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP', $create);
        $this->assertStringContainsString('CONSTRAINT `posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE', $create);
        $this->assertStringEndsWith('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $create);
        $this->assertContains('CREATE UNIQUE INDEX `posts_slug_unique` ON `posts` (`slug`)', $sql);
        $this->assertContains('CREATE INDEX `posts_owner_type_owner_id_index` ON `posts` (`owner_type`, `owner_id`)', $sql);
    }

    public function test_postgres_ddl(): void
    {
        $blueprint = new Blueprint('posts');
        ($this->postsBlueprint())($blueprint);
        $create = (new PostgresSchemaGrammar(new Grammar('pgsql'), $this->pdo))->compileCreate($blueprint)[0];

        $this->assertStringContainsString('"id" BIGSERIAL PRIMARY KEY', $create);
        $this->assertStringContainsString('"published" BOOLEAN NOT NULL DEFAULT FALSE', $create);
        $this->assertStringContainsString('"price" NUMERIC(8, 2) NOT NULL DEFAULT 0', $create);
        $this->assertStringContainsString("\"status\" VARCHAR(255) NOT NULL DEFAULT 'draft' CHECK (\"status\" IN ('draft', 'live'))", $create);
        $this->assertStringContainsString('"meta" JSONB NULL', $create);
        $this->assertStringContainsString('"created_at" TIMESTAMP(0) WITHOUT TIME ZONE NULL', $create);
    }

    public function test_alter_ddl_per_driver(): void
    {
        $alter = function (Blueprint $t) {
            $t->dropForeign('user_id');
            $t->dropUnique('slug');
        };

        $mysql = new Blueprint('posts');
        $alter($mysql);
        $this->assertSame([
            'ALTER TABLE `posts` DROP FOREIGN KEY `posts_user_id_foreign`',
            'ALTER TABLE `posts` DROP INDEX `posts_slug_unique`',
        ], (new MySqlSchemaGrammar(new Grammar('mysql'), $this->pdo))->compileAlter($mysql));

        $pg = new Blueprint('posts');
        $alter($pg);
        $this->assertSame([
            'ALTER TABLE "posts" DROP CONSTRAINT "posts_user_id_foreign"',
            'DROP INDEX "posts_slug_unique"',
        ], (new PostgresSchemaGrammar(new Grammar('pgsql'), $this->pdo))->compileAlter($pg));
    }

    public function test_sqlite_limitations_are_explicit(): void
    {
        $this->schema->create('t', fn (Blueprint $t) => $t->id());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SQLite cannot add a foreign key');
        $this->schema->table('t', fn (Blueprint $t) => $t->foreign('x')->on('users'));
    }

    public function test_identifiers_and_options_are_validated(): void
    {
        try {
            $this->schema->create('bad; DROP TABLE x', fn (Blueprint $t) => $t->id());
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException) {
        }

        try {
            $this->schema->create('t2', fn (Blueprint $t) => $t->foreignId('user_id')->constrained()->onDelete('EXPLODE'));
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->schema->create('t3', fn (Blueprint $t) => $t->enum('s', []));
    }

    public function test_defaults_are_quoted_and_expressions_raw(): void
    {
        $sql = $this->schema->toSql('t', function (Blueprint $t) {
            $t->string('name')->default("O'Neil");
            $t->dateTime('at')->default(Schema::raw('CURRENT_TIMESTAMP'));
        })[0];

        $this->assertStringContainsString("DEFAULT 'O''Neil'", $sql);
        $this->assertStringContainsString('"at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', str_replace('`', '"', $sql));
    }

    public function test_migration_helper(): void
    {
        $migration = new class extends \Luany\Database\Migration\Migration {
            public function up(\PDO $pdo): void
            {
                $this->schema($pdo)->create('things', fn (Blueprint $t) => $t->id());
            }

            public function down(\PDO $pdo): void
            {
                $this->schema($pdo)->dropIfExists('things');
            }
        };

        $migration->up($this->pdo);
        $this->assertTrue($this->schema->hasTable('things'));
        $migration->down($this->pdo);
        $this->assertFalse($this->schema->hasTable('things'));
    }
}
