<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\SqlScript;

/** The integration tests build their schema from install.sql, so the splitter has to be right. */
final class SqlScriptTest extends TestCase
{
    public function testSplitsStatementsAndIgnoresComments(): void
    {
        $sql = "-- header; with semicolon\nSELECT 1;\n/* block; comment */\nSELECT 2;  \n\n";
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlScript::statements($sql));
    }

    public function testSemicolonsInsideStringsAndBackticksDoNotSplit(): void
    {
        $sql = "INSERT INTO t VALUES ('a;b', \"c;d\", 'it''s; fine', 'back\\'slash;');\nSELECT `we;ird`;";
        $statements = SqlScript::statements($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString("'a;b'", $statements[0]);
        $this->assertStringContainsString("'it''s; fine'", $statements[0]);
        $this->assertSame('SELECT `we;ird`', $statements[1]);
    }

    public function testCanDropDatabaseStatements(): void
    {
        $sql = "CREATE DATABASE IF NOT EXISTS prod;\nUSE prod;\ncreate database other;\nDROP TABLE x;";

        $this->assertSame(['DROP TABLE x'], SqlScript::statements($sql, true));
        $this->assertCount(4, SqlScript::statements($sql, false));
    }

    public function testInstallSqlSplitsIntoCleanStatements(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/install.sql');
        $this->assertIsString($sql);

        $statements = SqlScript::statements($sql, true);
        $this->assertGreaterThan(20, count($statements));

        foreach ($statements as $statement) {
            $this->assertSame(1, preg_match('/^(CREATE\s+TABLE|DROP\s+TABLE|INSERT|SET|ALTER)\b/i', $statement), 'Unexpected statement start: ' . substr($statement, 0, 60));
            $this->assertSame(0, preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $statement));
        }

        $creates = array_filter($statements, static fn(string $s): bool => stripos($s, 'CREATE TABLE') === 0);
        $this->assertGreaterThanOrEqual(12, count($creates));
    }
}
