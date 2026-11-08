<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The client IP feeds login throttling, session binding and the same-user booking check, so it must not be forgeable. */
final class ClientIpTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $key) {
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testCidrMatching(): void
    {
        $this->assertTrue(ip_matches_cidr('10.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(ip_matches_cidr('172.18.0.5', '172.16.0.0/12'));
        $this->assertFalse(ip_matches_cidr('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue(ip_matches_cidr('203.0.113.9', '203.0.113.9'));
        $this->assertFalse(ip_matches_cidr('203.0.113.10', '203.0.113.9'));
        $this->assertTrue(ip_matches_cidr('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(ip_matches_cidr('2001:db9::1', '2001:db8::/32'));
        $this->assertFalse(ip_matches_cidr('10.0.0.1', '2001:db8::/32'), 'IPv4 never matches an IPv6 range');
        $this->assertFalse(ip_matches_cidr('10.0.0.1', ''));
    }

    public function testPrivateAndLoopbackPeersAreTrustedProxiesPublicOnesAreNot(): void
    {
        $this->assertTrue(is_trusted_proxy_ip('127.0.0.1'));
        $this->assertTrue(is_trusted_proxy_ip('172.18.0.2'));
        $this->assertTrue(is_trusted_proxy_ip('192.168.1.10'));
        $this->assertFalse(is_trusted_proxy_ip('203.0.113.9'));
        $this->assertFalse(is_trusted_proxy_ip('not-an-ip'));
    }

    public function testForwardedHeadersAreIgnoredWhenThePeerIsNotAProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6';
        $_SERVER['HTTP_X_REAL_IP'] = '7.7.7.7';
        $_SERVER['HTTP_CLIENT_IP'] = '8.8.8.8';

        $this->assertSame('203.0.113.9', get_client_ip());
        $this->assertSame('203.0.113.9', get_client_ip_for_session());
    }

    public function testClientSuppliedLeftmostForwardedEntriesAreIgnoredBehindAProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';
        // 6.6.6.6 is whatever the attacker typed; 203.0.113.9 is what the proxy actually saw.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.9';

        $this->assertSame('203.0.113.9', get_client_ip());
    }

    public function testInnerProxiesInTheChainAreSkipped(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.7';

        $this->assertSame('203.0.113.9', get_client_ip());
    }

    public function testRealIpHeaderIsUsedBehindAProxyWithoutForwardedFor(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.9';

        $this->assertSame('203.0.113.9', get_client_ip());
    }

    public function testGarbageForwardedValuesFallBackToThePeer(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = "<script>, ' OR 1=1 --";

        $this->assertSame('127.0.0.1', get_client_ip());
    }
}
