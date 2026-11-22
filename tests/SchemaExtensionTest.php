<?php

namespace Luany\Database\Tests;

use Luany\Database\Schema\Blueprint;
use Luany\Database\Schema\ColumnDefinition;
use Luany\Database\Schema\Schema;

/**
 * Schema, Blueprint and ColumnDefinition can be extended (luany/pt).
 */
class SchemaExtensionTest extends TestCase
{
    public function test_subclasses_flow_through_create(): void
    {
        $seen = [];
        TestSchema::for($this->pdo)->create('things', function (Blueprint $table) use (&$seen) {
            $seen[] = get_class($table);
            $table->id();
            $seen[] = get_class($table->string('name')->nullable()->unique());
            $seen[] = get_class($table->foreignId('owner_id')->nullable());
        });

        $this->assertSame([TestBlueprint::class, TestColumn::class, \Luany\Database\Schema\ForeignIdColumnDefinition::class], $seen);
        $this->pdo->exec("INSERT INTO things (name) VALUES ('a')");
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM things')->fetchColumn());
    }

    public function test_for_returns_the_subclass(): void
    {
        $this->assertInstanceOf(TestSchema::class, TestSchema::for($this->pdo));
        $this->assertSame(Schema::class, get_class(Schema::for($this->pdo)));
    }
}

class TestSchema extends Schema
{
    protected function newBlueprint(string $table): Blueprint
    {
        return new TestBlueprint($table);
    }
}

class TestBlueprint extends Blueprint
{
    protected function newColumn(string $name, string $type, array $options): ColumnDefinition
    {
        return new TestColumn($name, $type, $options);
    }
}

class TestColumn extends ColumnDefinition
{
}
