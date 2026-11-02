<?php
declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/** Base class for tests that need the database: builds the schema once per run and empties it before each test. */
abstract class DatabaseTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestSchema::boot();
    }

    protected function setUp(): void
    {
        TestSchema::reset();
    }

    protected function tearDown(): void
    {
        TestDatabase::restoreSettings();
    }

    /** Runs a statement on the application's connection (for arranging or checking rows). */
    protected function db(): \PDO
    {
        return get_db();
    }

    /** @return array<string, mixed>|null */
    protected function row(string $sql, array $params = []): ?array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
