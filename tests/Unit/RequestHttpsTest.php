<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The session cookie's Secure flag depends on this; behind a TLS proxy PHP only sees plain HTTP. */
final class RequestHttpsTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        foreach (['HTTPS', 'SERVER_PORT', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_PROTO'] as $key) {
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testPlainHttpIsNotHttps(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['SERVER_PORT'] = '80';
        $this->assertFalse(request_is_https());
    }

    public function testNativeHttpsAndPort443(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(request_is_https());

        $_SERVER['HTTPS'] = 'off';
        $this->assertFalse(request_is_https());

        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertTrue(request_is_https());
    }

    public function testForwardedProtoIsTrustedFromAProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(request_is_https());

        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
        $this->assertTrue(request_is_https(), 'first value is the client-facing hop');

        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $this->assertFalse(request_is_https());
    }

    public function testForwardedProtoIsIgnoredWhenNotFromAProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertFalse(request_is_https(), 'a direct client must not be able to claim HTTPS');
    }
}
