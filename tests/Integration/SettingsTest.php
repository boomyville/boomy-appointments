<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use Tests\Support\TestDatabase;

final class SettingsTest extends DatabaseTestCase
{
    public function testMissingSettingReturnsTheDefault(): void
    {
        $this->assertSame('fallback', get_setting('definitely_not_a_real_setting', 'fallback'));
        $this->assertNull(get_setting('definitely_not_a_real_setting'));
    }

    public function testSetAndGetRoundTrip(): void
    {
        $this->assertTrue(set_setting('test_setting', 'value one'));
        $this->assertSame('value one', get_setting('test_setting', 'x'));
        get_db()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['test_setting']);
    }

    public function testSettingAnExistingKeyOverwritesIt(): void
    {
        set_setting('test_setting', 'first');
        set_setting('test_setting', 'second');

        $this->assertSame('second', get_setting('test_setting'));
        $count = $this->row('SELECT COUNT(*) AS n FROM settings WHERE setting_key = ?', ['test_setting']);
        $this->assertSame(1, (int) $count['n']);
        get_db()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['test_setting']);
    }

    public function testAnEmptyStringIsAStoredValueNotAMissingOne(): void
    {
        set_setting('test_setting', '');

        $this->assertSame('', get_setting('test_setting', 'default'));
        get_db()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['test_setting']);
    }

    public function testRootUrlSettingBuildsAbsoluteLinks(): void
    {
        TestDatabase::setSetting('root_url', 'https://example.test/appointments/');

        $this->assertSame('https://example.test/appointments', get_app_root_url(), 'trailing slash is trimmed');
        $this->assertSame('https://example.test/appointments/confirm_appointment.php', build_app_url('confirm_appointment.php'));
        $this->assertSame(
            'https://example.test/appointments/cancel_appointment.php?token=abc&x=1',
            build_app_url('/cancel_appointment.php', ['token' => 'abc', 'x' => 1])
        );
    }

    public function testTestHelperRestoresSettingsAfterATest(): void
    {
        set_setting('restore_me', 'original');
        TestDatabase::setSetting('restore_me', 'changed');
        $this->assertSame('changed', get_setting('restore_me'));

        TestDatabase::restoreSettings();

        $this->assertSame('original', get_setting('restore_me'));
        get_db()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['restore_me']);
    }
}
