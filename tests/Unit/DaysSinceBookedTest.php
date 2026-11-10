<?php
declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** The little number on each calendar appointment: whole clinic-local days since it was booked. */
final class DaysSinceBookedTest extends TestCase
{
    private DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->tz = new DateTimeZone(get_app_timezone_name());
    }

    private function local(string $dateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($dateTime, $this->tz);
    }

    public function testBookedTodayIsZeroNoMatterWhatTime(): void
    {
        $now = $this->local('2026-11-10 15:00:00');
        $this->assertSame(0, appointment_days_since_created($this->local('2026-11-10 00:00:01')->getTimestamp(), $now));
        $this->assertSame(0, appointment_days_since_created($this->local('2026-11-10 14:59:59')->getTimestamp(), $now));
    }

    public function testBookedTwelveDaysAgoIsTwelve(): void
    {
        $now = $this->local('2026-11-10 15:00:00');
        $this->assertSame(12, appointment_days_since_created($this->local('2026-10-29 09:30:00')->getTimestamp(), $now));
        $this->assertSame(1, appointment_days_since_created($this->local('2026-11-09 23:59:59')->getTimestamp(), $now));
    }

    public function testDaysAreCountedByCalendarDayNotBy24HourBlocks(): void
    {
        // 2 minutes apart, but across midnight = 1 day.
        $created = $this->local('2026-11-09 23:59:00')->getTimestamp();
        $this->assertSame(1, appointment_days_since_created($created, $this->local('2026-11-10 00:01:00')));
        // 23 hours apart, same calendar day = 0.
        $created = $this->local('2026-11-10 00:30:00')->getTimestamp();
        $this->assertSame(0, appointment_days_since_created($created, $this->local('2026-11-10 23:30:00')));
    }

    public function testDaylightSavingChangeDoesNotShiftTheCount(): void
    {
        // Spans the October daylight saving change in Melbourne (the clocks move on 2026-10-04).
        $created = $this->local('2026-10-01 12:00:00')->getTimestamp();
        $this->assertSame(9, appointment_days_since_created($created, $this->local('2026-10-10 12:00:00')));
    }

    public function testTheClinicTimeZoneDecidesWhereTheDayStartsNotUtc(): void
    {
        // 00:53 UTC on 9 Nov is the middle of the morning in Melbourne, still 9 Nov there.
        $createdTs = (new DateTimeImmutable('2026-11-09 00:53:16', new DateTimeZone('UTC')))->getTimestamp();
        $this->assertSame(0, appointment_days_since_created($createdTs, $this->local('2026-11-09 20:00:00')));
        $this->assertSame(1, appointment_days_since_created($createdTs, $this->local('2026-11-10 00:10:00')));
    }

    public function testMissingOrBrokenTimestampGivesNull(): void
    {
        $this->assertNull(appointment_days_since_created(null));
        $this->assertNull(appointment_days_since_created(''));
        $this->assertNull(appointment_days_since_created('not a number'));
    }

    public function testNumericStringsFromThePdoDriverWork(): void
    {
        $now = $this->local('2026-11-10 15:00:00');
        $ts = (string) $this->local('2026-11-05 10:00:00')->getTimestamp();
        $this->assertSame(5, appointment_days_since_created($ts, $now));
    }

    public function testABookingFromTheFutureCountsAsZero(): void
    {
        $now = $this->local('2026-11-10 15:00:00');
        $this->assertSame(0, appointment_days_since_created($this->local('2026-11-12 09:00:00')->getTimestamp(), $now));
    }
}
