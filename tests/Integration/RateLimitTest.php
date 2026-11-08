<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class RateLimitTest extends DatabaseTestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function testHitsAreAllowedUpToTheLimitThenRefused(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(rate_limit_hit('t_bucket', 'k', 3, 60), "hit $i should pass");
        }
        $this->assertFalse(rate_limit_hit('t_bucket', 'k', 3, 60));
        $this->assertFalse(rate_limit_hit('t_bucket', 'K', 3, 60), 'keys are case-insensitive');
    }

    public function testBucketsAndKeysAreIndependent(): void
    {
        $this->assertTrue(rate_limit_hit('a', 'k', 1, 60));
        $this->assertFalse(rate_limit_hit('a', 'k', 1, 60));
        $this->assertTrue(rate_limit_hit('b', 'k', 1, 60));
        $this->assertTrue(rate_limit_hit('a', 'other', 1, 60));
    }

    public function testOldHitsFallOutOfTheWindow(): void
    {
        $this->assertTrue(rate_limit_hit('w', 'k', 1, 60));
        $this->assertFalse(rate_limit_hit('w', 'k', 1, 60));
        $this->db()->exec("UPDATE rate_limits SET hit_at = (NOW() - INTERVAL 2 MINUTE) WHERE bucket = 'w'");
        $this->assertTrue(rate_limit_hit('w', 'k', 1, 60));
    }

    public function testFiveFailedLoginsForTheSameIpAndEmailLockThatPairOnly(): void
    {
        for ($i = 0; $i < 4; $i++) {
            insert_login_attempt('admin@example.com', false);
        }
        $this->assertFalse(login_attempts_exceeded('admin@example.com'));

        insert_login_attempt('admin@example.com', false);
        $this->assertTrue(login_attempts_exceeded('admin@example.com'));
        $this->assertFalse(login_attempts_exceeded('someone-else@example.com'));

        // The real admin, coming from another address, is not locked out by the attacker's failures.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->assertFalse(login_attempts_exceeded('admin@example.com'));
    }

    public function testManyFailuresAcrossDifferentEmailsFromOneIpAreBlocked(): void
    {
        for ($i = 0; $i < 20; $i++) {
            insert_login_attempt("user$i@example.com", false);
        }
        $this->assertTrue(login_attempts_exceeded('brand-new@example.com'));
    }

    public function testSuccessfulLoginsDoNotCountTowardsTheLimit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            insert_login_attempt('admin@example.com', true);
        }
        $this->assertFalse(login_attempts_exceeded('admin@example.com'));
    }
}
