<?php

namespace Luany\Database\Tests;

use Luany\Database\Connection;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for luany/database tests.
 *
 * Provides an SQLite in-memory PDO connection shared across
 * all tests in a suite, with automatic schema reset between tests.
 *
 * No MySQL, no network, no .env required.
 */
abstract class TestCase extends BaseTestCase
{
    protected \PDO $pdo;
    protected Connection $connection;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->connection = Connection::fromPdo($this->pdo);

        $this->setUpSchema();
    }

    /**
     * Override to create tables needed by the test suite.
     */
    protected function setUpSchema(): void {}
}