<?php
declare(strict_types=1);

namespace Tests\Integration;

use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\TestDatabase;

/** Links that carry secrets (password reset etc.) must never be built from an attacker-controlled Host header. */
final class RootUrlTest extends DatabaseTestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function testSecretLinksRefuseToUseTheHostHeaderWhenRootUrlIsNotConfigured(): void
    {
        TestDatabase::setSetting('root_url', '');
        $_SERVER['HTTP_HOST'] = 'evil.example';

        $this->expectException(RuntimeException::class);
        build_app_url('reset_password.php', ['code' => 'abc'], true);
    }

    public function testConfiguredRootUrlIsAlwaysUsedEvenIfTheHostHeaderIsForged(): void
    {
        TestDatabase::setSetting('root_url', 'https://book.example.com/');
        $_SERVER['HTTP_HOST'] = 'evil.example';

        $this->assertSame(
            'https://book.example.com/reset_password.php?code=abc',
            build_app_url('reset_password.php', ['code' => 'abc'], true)
        );
    }

    public function testMalformedHostHeaderFallsBackToLocalhostForNonSecretLinks(): void
    {
        TestDatabase::setSetting('root_url', '');
        $_SERVER['HTTP_HOST'] = "evil.example/\r\nX-Injected: 1";
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        $this->assertStringStartsWith('http://localhost', build_app_url('login.php'));
    }
}
