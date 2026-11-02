<?php
declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Creates the schema in the MariaDB test database from install.sql and empties it between tests.
 * Only ever touches the database named in config.php, which bootstrap.php insists ends in "_test".
 */
final class TestSchema
{
    private static bool $booted = false;

    /** Tables whose contents survive between tests (settings are restored by TestDatabase::restoreSettings). */
    private const KEEP = ['settings'];

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::guard();

        // Build the schema on a separate raw connection FIRST. The application upgrades its own schema
        // (ensure_*_schema in functions.php) the first time get_db() is called, and it only does so once per
        // process, so get_db() must not be touched until the tables from install.sql exist.
        $pdo = self::rawConnection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $sql = file_get_contents(dirname(__DIR__, 2) . '/install.sql');
        if ($sql === false) {
            throw new RuntimeException('install.sql not found');
        }
        // install.sql names its own database; the test database is already selected by the connection.
        foreach (SqlScript::statements($sql, true) as $statement) {
            $pdo->exec($statement);
        }

        // Deterministic booking window for the tests (Mon-Fri, 09:00-21:00)
        $settings = [
            'booking_start_time' => '09:00', 'booking_end_time' => '21:00',
            'booking_show_monday' => '1', 'booking_show_tuesday' => '1', 'booking_show_wednesday' => '1',
            'booking_show_thursday' => '1', 'booking_show_friday' => '1',
            'booking_show_saturday' => '0', 'booking_show_sunday' => '0',
        ];
        $upsert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($settings as $key => $value) {
            $upsert->execute([$key, $value]);
        }

        // Now let the application run its schema upgrades against the freshly built tables.
        get_db();

        self::$booted = true;
    }

    public static function reset(): void
    {
        $pdo = get_db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (in_array((string) $table, self::KEEP, true)) {
                continue;
            }
            $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function rawConnection(): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function guard(): void
    {
        if (!defined('DB_NAME') || !str_ends_with((string) DB_NAME, '_test')) {
            throw new RuntimeException('Refusing to reset a database whose name does not end in _test.');
        }
    }
}
