<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use Tests\Support\TestDatabase;

final class VerificationTokenTest extends DatabaseTestCase
{
    private int $appointmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $customer = TestDatabase::customer('Mark Smith', 'family@example.com');
        $appointment = create_appointment($customer, TestDatabase::provider(), TestDatabase::appointmentType(30), '2030-01-07 10:00:00', 'unconfirmed');
        $this->assertIsArray($appointment);
        $this->appointmentId = (int) $appointment['id'];
    }

    public function testBookingVerificationTokenCanBeLookedUp(): void
    {
        $code = create_appointment_verification_token($this->appointmentId);

        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $code));
        $row = get_appointment_verification_by_code($code);
        $this->assertNotNull($row);
        $this->assertSame($this->appointmentId, (int) $row['appointment_id']);
        $this->assertSame('appointment_booking', $row['task']);
        $this->assertNull(get_appointment_verification_by_code(str_repeat('a', 64)));
    }

    public function testRequestingANewTokenInvalidatesTheOldOne(): void
    {
        $first = create_appointment_verification_token($this->appointmentId);
        $second = create_appointment_verification_token($this->appointmentId);

        $this->assertNotSame($first, $second);
        $this->assertNull(get_appointment_verification_by_code($first));
        $this->assertNotNull(get_appointment_verification_by_code($second));
    }

    public function testExpiredTokensAreNotReturned(): void
    {
        $code = create_appointment_verification_token($this->appointmentId);
        $this->db()->prepare('UPDATE verification SET expires_at = ? WHERE code = ?')->execute(['2000-01-01 00:00:00', $code]);

        $this->assertNull(get_appointment_verification_by_code($code));
    }

    public function testDeletingATokenRemovesIt(): void
    {
        $code = create_appointment_verification_token($this->appointmentId);

        delete_verification_token($code);

        $this->assertNull(get_appointment_verification_by_code($code));
    }

    public function testCancelTokenIsReusedAndNeverExpires(): void
    {
        $first = get_or_create_cancel_token($this->appointmentId);
        $second = get_or_create_cancel_token($this->appointmentId);

        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', (string) $first));
        $this->assertSame($first, $second);

        $row = $this->row("SELECT expires_at FROM verification WHERE code = ? AND task = 'cancel_appointment'", [$first]);
        $this->assertNotNull($row);
        $this->assertNull($row['expires_at'], 'cancel links rely on the live appointment time, not a stored expiry');
    }

    public function testStaleCancelTokenIsReplacedWithoutViolatingTheUniqueIndex(): void
    {
        $old = get_or_create_cancel_token($this->appointmentId);
        $this->db()->prepare("UPDATE verification SET expires_at = ? WHERE code = ? AND task = 'cancel_appointment'")->execute(['2000-01-01 00:00:00', $old]);

        $fresh = get_or_create_cancel_token($this->appointmentId);

        $this->assertNotSame($old, $fresh);
        $count = $this->row("SELECT COUNT(*) AS n FROM verification WHERE appointment_id = ? AND task = 'cancel_appointment'", [$this->appointmentId]);
        $this->assertSame(1, (int) $count['n']);
    }

    public function testCancelTokenForAnUnknownAppointmentIsNull(): void
    {
        $this->assertNull(get_or_create_cancel_token(999999));
    }

    public function testEachAppointmentGetsItsOwnCancelToken(): void
    {
        $customer = TestDatabase::customer('Jay Smith', 'family@example.com');
        $other = create_appointment($customer, TestDatabase::provider('Dr Two'), TestDatabase::appointmentType(30, 'Other'), '2030-01-07 11:00:00');

        $this->assertNotSame(get_or_create_cancel_token($this->appointmentId), get_or_create_cancel_token((int) $other['id']));
    }
}
