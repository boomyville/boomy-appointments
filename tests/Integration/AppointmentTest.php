<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use Tests\Support\TestDatabase;

final class AppointmentTest extends DatabaseTestCase
{
    // Mon 7 Jan 2030 and Sat 12 Jan 2030. Booking days are Mon-Fri, 09:00-21:00 (see TestSchema::boot()).
    private const MON = '2030-01-07';
    private const TUE = '2030-01-08';
    private const SAT = '2030-01-12';

    private int $provider;
    private int $type;
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = TestDatabase::provider();
        $this->type = TestDatabase::appointmentType(30);
        $this->customer = TestDatabase::customer('Mark Smith', 'family@example.com');
    }

    /** @return array<string, mixed> */
    private function book(string $start, ?string $status = null, ?string $end = null, ?int $provider = null, ?int $type = null): array
    {
        $result = create_appointment($this->customer, $provider ?? $this->provider, $type ?? $this->type, $start, $status, $end);
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result, 'Booking was rejected: ' . json_encode($result));
        return $result;
    }

    public function testCreatesAppointmentUsingTheTypeDuration(): void
    {
        $appt = $this->book(self::MON . ' 10:00:00');

        $this->assertSame(self::MON . ' 10:00:00', $appt['start_time']);
        $this->assertSame(self::MON . ' 10:30:00', $appt['end_time']);
        $this->assertSame('booked', $appt['status']);
        $this->assertSame('unknown', $appt['booking_source']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $appt['hash_key']));

        $stored = get_appointment_by_id($appt['id']);
        $this->assertNotNull($stored);
        $this->assertSame($appt['start_time'], $stored['start_time']);
        $this->assertSame($appt['end_time'], $stored['end_time']);
        $this->assertSame($this->customer, (int) $stored['customer_id']);
        $this->assertSame($this->provider, (int) $stored['provider_id']);
    }

    public function testHonoursAnExplicitEndTime(): void
    {
        $appt = $this->book(self::MON . ' 10:00:00', null, self::MON . ' 11:15:00');
        $this->assertSame(self::MON . ' 11:15:00', $appt['end_time']);
    }

    public function testRecordsWhereTheBookingCameFrom(): void
    {
        $online = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 10:00:00', null, null, null, false, 'online');
        $staff = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 11:00:00', null, null, null, false, 'staff');
        $junk = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 12:00:00', null, null, null, false, 'robot');

        $this->assertSame('online', $online['booking_source']);
        $this->assertSame('staff', $staff['booking_source']);
        $this->assertSame('unknown', $junk['booking_source'], 'an unrecognised source falls back to unknown');
        $this->assertSame('online', get_appointment_by_id($online['id'])['booking_source']);
    }

    public function testExplicitStatusIsKept(): void
    {
        $appt = $this->book(self::MON . ' 10:00:00', 'unconfirmed');
        $this->assertSame('unconfirmed', $appt['status']);
        $this->assertSame('unconfirmed', get_appointment_by_id($appt['id'])['status']);
    }

    public function testNotesAreTrimmedAndBlankNotesBecomeNull(): void
    {
        $withNotes = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 10:00:00', null, null, '  bring glasses  ');
        $blank = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 11:00:00', null, null, '   ');

        $this->assertSame('bring glasses', $withNotes['notes']);
        $this->assertNull($blank['notes']);
    }

    public function testInactiveAppointmentTypeCannotBeBooked(): void
    {
        $inactive = TestDatabase::appointmentType(30, 'Retired', false);
        $this->assertNull(create_appointment($this->customer, $this->provider, $inactive, self::MON . ' 10:00:00'));
        $this->assertNull(create_appointment($this->customer, $this->provider, 999999, self::MON . ' 10:00:00'));
    }

    public function testDaysThatAreNotBookableAreRejected(): void
    {
        $result = create_appointment($this->customer, $this->provider, $this->type, self::SAT . ' 10:00:00');
        $this->assertSame('outside_booking_hours', $result['error']);
    }

    public function testTimesOutsideTheBookingWindowAreRejected(): void
    {
        $tooEarly = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 08:30:00');
        $this->assertSame('outside_booking_hours', $tooEarly['error']);

        $runsPastClosing = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 20:45:00');
        $this->assertSame('outside_booking_hours', $runsPastClosing['error']);

        $lastSlot = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 20:30:00');
        $this->assertArrayNotHasKey('error', $lastSlot, 'finishing exactly at closing time is fine');
    }

    public function testOverlappingAppointmentsAreRejected(): void
    {
        $first = $this->book(self::MON . ' 10:00:00');

        $clash = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 10:15:00');
        $this->assertSame('overlap', $clash['error']);
        $this->assertSame($first['id'], (int) $clash['conflict']['id']);
    }

    public function testBackToBackAppointmentsAreAllowed(): void
    {
        $this->book(self::MON . ' 10:00:00');
        $second = $this->book(self::MON . ' 10:30:00');
        $this->assertSame(self::MON . ' 10:30:00', $second['start_time']);
    }

    public function testDifferentProvidersDoNotClash(): void
    {
        $this->book(self::MON . ' 10:00:00');
        $otherProvider = TestDatabase::provider('Dr Other');
        $other = $this->book(self::MON . ' 10:00:00', null, null, $otherProvider);
        $this->assertSame($otherProvider, (int) $other['provider_id']);
    }

    public function testCancelledAndNoShowAppointmentsFreeTheSlot(): void
    {
        $first = $this->book(self::MON . ' 10:00:00');
        $this->db()->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$first['id']]);
        $this->book(self::MON . ' 10:00:00');

        $second = $this->book(self::MON . ' 14:00:00');
        $this->db()->prepare("UPDATE appointments SET status = 'no_show' WHERE id = ?")->execute([$second['id']]);
        $this->book(self::MON . ' 14:00:00');
    }

    public function testStaffOverrideSkipsTheWindowAndOverlapChecks(): void
    {
        $this->book(self::MON . ' 10:00:00');

        $overlapping = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 10:00:00', null, null, null, true);
        $this->assertArrayNotHasKey('error', $overlapping);

        $saturdayNight = create_appointment($this->customer, $this->provider, $this->type, self::SAT . ' 03:00:00', null, null, null, true);
        $this->assertArrayNotHasKey('error', $saturdayNight);
        $this->assertSame(self::SAT . ' 03:00:00', $saturdayNight['start_time']);
    }

    public function testOneOffProviderUnavailabilityBlocksBooking(): void
    {
        $this->db()->prepare("INSERT INTO unavailabilities (provider_id, type, start_datetime, end_datetime, reason) VALUES (?, 'single', ?, ?, 'Conference')")
            ->execute([$this->provider, self::MON . ' 10:00:00', self::MON . ' 12:00:00']);

        $blocked = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 11:00:00');
        $this->assertSame('provider_unavailable', $blocked['error']);
        $this->assertStringContainsString('Conference', $blocked['message']);

        $after = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 12:00:00');
        $this->assertArrayNotHasKey('error', $after);
    }

    public function testRecurringProviderUnavailabilityOnlyBlocksItsWeekday(): void
    {
        $this->db()->prepare("INSERT INTO unavailabilities (provider_id, type, day_of_week, start_time, end_time, rolling_start_date, reason) VALUES (?, 'recurring', 1, '13:00:00', '14:00:00', '2029-01-01', 'Mondays off')")
            ->execute([$this->provider]);

        $monday = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 13:30:00');
        $this->assertSame('provider_unavailable', $monday['error']);

        $tuesday = create_appointment($this->customer, $this->provider, $this->type, self::TUE . ' 13:30:00');
        $this->assertArrayNotHasKey('error', $tuesday);
    }

    public function testHashKeysAreUniqueAndLookUpTheAppointment(): void
    {
        $a = $this->book(self::MON . ' 10:00:00');
        $b = $this->book(self::MON . ' 11:00:00');

        $this->assertNotSame($a['hash_key'], $b['hash_key']);
        $this->assertSame($a['id'], (int) get_appointment_by_hash_key($a['hash_key'])['id']);
        $this->assertNull(get_appointment_by_hash_key(str_repeat('0', 64)));
    }

    public function testUpdateMovesAnAppointmentAndKeepsItsBookingSource(): void
    {
        $created = create_appointment($this->customer, $this->provider, $this->type, self::MON . ' 10:00:00', null, null, null, false, 'online');

        $updated = update_appointment($created['id'], $this->customer, $this->provider, $this->type, self::MON . ' 14:00:00', self::MON . ' 14:30:00', 'confirmed');

        $this->assertIsArray($updated);
        $this->assertArrayNotHasKey('error', $updated);
        $stored = get_appointment_by_id($created['id']);
        $this->assertSame(self::MON . ' 14:00:00', $stored['start_time']);
        $this->assertSame(self::MON . ' 14:30:00', $stored['end_time']);
        $this->assertSame('confirmed', $stored['status']);
        $this->assertSame('online', $stored['booking_source'], 'moving an appointment must not change where it was booked');
    }

    public function testUpdateIgnoresItselfWhenCheckingOverlapButNotOthers(): void
    {
        $a = $this->book(self::MON . ' 10:00:00');
        $b = $this->book(self::MON . ' 11:00:00');

        $nudged = update_appointment($a['id'], $this->customer, $this->provider, $this->type, self::MON . ' 10:15:00', self::MON . ' 10:45:00');
        $this->assertArrayNotHasKey('error', $nudged, 'overlapping its own old slot is fine');

        $clash = update_appointment($b['id'], $this->customer, $this->provider, $this->type, self::MON . ' 10:30:00', self::MON . ' 11:00:00');
        $this->assertSame('overlap', $clash['error']);
        $this->assertSame(self::MON . ' 11:00:00', get_appointment_by_id($b['id'])['start_time'], 'a rejected update changes nothing');
    }

    public function testUpdateRejectsUnknownAppointmentsAndInactiveTypes(): void
    {
        $a = $this->book(self::MON . ' 10:00:00');
        $inactive = TestDatabase::appointmentType(30, 'Retired', false);

        $this->assertNull(update_appointment(999999, $this->customer, $this->provider, $this->type, self::MON . ' 10:00:00', self::MON . ' 10:30:00'));
        $this->assertNull(update_appointment($a['id'], $this->customer, $this->provider, $inactive, self::MON . ' 10:00:00', self::MON . ' 10:30:00'));
    }

    public function testDeleteRemovesTheAppointment(): void
    {
        $a = $this->book(self::MON . ' 10:00:00');

        $this->assertTrue(delete_appointment($a['id']));
        $this->assertNull(get_appointment_by_id($a['id']));
    }

    public function testDeletingACustomerDeletesTheirAppointments(): void
    {
        $a = $this->book(self::MON . ' 10:00:00');

        $this->db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$this->customer]);

        $this->assertNull(get_appointment_by_id($a['id']));
    }
}
