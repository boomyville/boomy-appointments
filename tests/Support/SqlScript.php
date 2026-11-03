<?php
declare(strict_types=1);

namespace Tests\Support;

/** Splits an SQL script into individual statements (handles quotes, backticks and comments). */
final class SqlScript
{
    /**
     * @return list<string>
     */
    public static function statements(string $sql, bool $dropDatabaseStatements = false): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === '-' && $next === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $current .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($char === ';') {
                self::push($statements, $current, $dropDatabaseStatements);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        self::push($statements, $current, $dropDatabaseStatements);

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private static function push(array &$statements, string $statement, bool $dropDatabaseStatements): void
    {
        $statement = trim($statement);
        if ($statement === '') {
            return;
        }
        if ($dropDatabaseStatements && preg_match('/^(CREATE\s+DATABASE|DROP\s+DATABASE|USE)\b/i', $statement)) {
            return;
        }
        $statements[] = $statement;
    }
}
