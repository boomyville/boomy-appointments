<?php
declare(strict_types=1);

namespace Tests\Support;

/** Fixture helpers. They use the application's own connection (get_db) and settings functions. */
final class TestDatabase
{
    /** @var array<string, ?string> original values of settings changed during a test */
    private static array $originalSettings = [];

    public static function provider(string $name = 'Dr Test', ?string $email = null): int
    {
        static $counter = 0;
        $counter++;
        $stmt = get_db()->prepare("INSERT INTO users (email, password_hash, full_name, role) VALUES (?, ?, ?, 'provider')");
        $stmt->execute([$email ?? ('provider' . $counter . '@example.test'), 'x', $name]);
        return (int) get_db()->lastInsertId();
    }

    public static function appointmentType(int $minutes = 30, string $name = 'Test type', bool $active = true): int
    {
        $stmt = get_db()->prepare('INSERT INTO appointment_types (name, duration_minutes, is_active, is_bookable) VALUES (?, ?, ?, 1)');
        $stmt->execute([$name, $minutes, $active ? 1 : 0]);
        return (int) get_db()->lastInsertId();
    }

    public static function customer(string $name, string $email, string $mobile = '0400000000'): int
    {
        $id = create_customer($name, $email, $mobile);
        if (!$id) {
            throw new \RuntimeException('Could not create test customer');
        }
        return $id;
    }

    /** Sets a setting for the duration of the current test (restored by restoreSettings()). */
    public static function setSetting(string $key, string $value): void
    {
        if (!array_key_exists($key, self::$originalSettings)) {
            $current = get_setting($key, null);
            self::$originalSettings[$key] = $current === null ? null : (string) $current;
        }
        set_setting($key, $value);
    }

    public static function restoreSettings(): void
    {
        foreach (self::$originalSettings as $key => $value) {
            if ($value === null) {
                get_db()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
            } else {
                set_setting($key, $value);
            }
        }
        self::$originalSettings = [];
    }
}
