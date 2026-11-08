<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use Tests\Support\TestDatabase;

/** The "other appointments on the same day for this email" section of the booking emails. */
final class SameDayAppointmentsTest extends DatabaseTestCase
{
    private const DAY = '2030-01-07';   // a Monday, always in the future

    private int $provider;
    private int $type;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabase::setSetting('root_url', 'https://example.test/appointments');
        $this->provider = TestDatabase::provider();
        $this->type = TestDatabase::appointmentType(30, 'Eye test');
    }

    /** @return array<string, mixed> */
    private function book(string $name, string $email, string $time, ?string $status = null, string $day = self::DAY): array
    {
        $customer = TestDatabase::customer($name, $email);
        $appointment = create_appointment($customer, $this->provider, $this->type, $day . ' ' . $time, $status);
        $this->assertIsArray($appointment);
        $this->assertArrayNotHasKey('error', $appointment, 'fixture booking failed: ' . json_encode($appointment));
        return $appointment;
    }

    private function setStatus(int $appointmentId, string $status): void
    {
        $this->db()->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $appointmentId]);
    }

    public function testListsOtherAppointmentsForTheSameEmailOnTheSameDay(): void
    {
        $mark = $this->book('Mark Smith', 'family@example.com', '10:00:00');
        $jay = $this->book('Jay Smith', 'FAMILY@example.com', '11:00:00');           // same email, different case
        $this->book('Other Person', 'someone@else.com', '12:00:00');                 // different email
        $this->book('Next Day', 'family@example.com', '10:00:00', null, '2030-01-08'); // different day
        $cancelled = $this->book('Cancelled Lee', 'family@example.com', '14:00:00');
        $this->setStatus((int) $cancelled['id'], 'cancelled');

        $others = get_same_day_appointments_for_email($jay);

        $this->assertCount(1, $others);
        $this->assertSame($mark['id'], $others[0]['id']);
        $this->assertSame('Mark Smith', $others[0]['customer_name']);
        $this->assertSame('10:00 AM - 10:30 AM', $others[0]['time_label']);
        $this->assertSame('Eye test', $others[0]['type_name']);
    }

    public function testOthersAreOrderedByStartTime(): void
    {
        $late = $this->book('Late', 'family@example.com', '15:00:00');
        $early = $this->book('Early', 'family@example.com', '09:00:00');
        $current = $this->book('Current', 'family@example.com', '12:00:00');

        $others = get_same_day_appointments_for_email($current);

        $this->assertSame([$early['id'], $late['id']], array_column($others, 'id'));
    }

    public function testNoSectionWhenThereAreNoOtherAppointments(): void
    {
        $only = $this->book('Mark Smith', 'family@example.com', '10:00:00');

        $block = build_same_day_appointments_email_block($only, true);

        $this->assertSame(['html' => '', 'plain' => '', 'count' => 0], $block);
    }

    public function testConfirmationEmailGetsButtonsForEachActionableAppointment(): void
    {
        $mark = $this->book('Mark Smith', 'family@example.com', '10:00:00');
        $jay = $this->book('Jay Smith', 'family@example.com', '11:00:00');
        $sam = $this->book('Sam Smith', 'family@example.com', '14:00:00', 'unconfirmed');

        $block = build_same_day_appointments_email_block($jay, true);

        $this->assertSame(2, $block['count']);
        foreach (['Mark Smith', 'Sam Smith', 'Jay Smith (this booking)', 'Awaiting email verification'] as $text) {
            $this->assertStringContainsString($text, $block['html']);
        }
        $this->assertLessThan(strpos($block['html'], 'Jay Smith'), strpos($block['html'], 'Mark Smith'), 'listed in time order');

        $base = 'https://example.test/appointments/confirm_appointment.php?code=';
        $this->assertStringContainsString($base . $mark['hash_key'] . '"', $block['html']);
        $this->assertStringContainsString($base . $jay['hash_key'] . '"', $block['html']);
        $this->assertStringNotContainsString($sam['hash_key'], $block['html'], 'pending appointments cannot be changed by hash');

        $all = $base . rawurlencode($mark['hash_key'] . ',' . $jay['hash_key']);
        $this->assertStringContainsString($all . '"', $block['html'], 'one link carrying both codes in time order');
        $this->assertStringContainsString('Confirm all 2', $block['html']);
        $this->assertStringContainsString('Delete all 2', $block['html']);

        $this->assertStringContainsString('Confirm Mark Smith: ' . $base . $mark['hash_key'], $block['plain']);
        $this->assertStringContainsString($all, $block['plain']);
    }

    public function testVerificationEmailListsAppointmentsWithoutAnyActionLinks(): void
    {
        $this->book('Mark Smith', 'family@example.com', '10:00:00');
        $jay = $this->book('Jay Smith', 'family@example.com', '11:00:00', 'unconfirmed');

        $block = build_same_day_appointments_email_block($jay, false);

        $this->assertSame(1, $block['count']);
        $this->assertStringContainsString('Mark Smith', $block['html']);
        $this->assertStringContainsString('Jay Smith (this booking)', $block['html']);
        $this->assertStringNotContainsString('confirm_appointment.php', $block['html']);
        $this->assertStringNotContainsString('confirm_appointment.php', $block['plain']);
    }

    public function testAlreadyConfirmedAppointmentsCanOnlyBeDeleted(): void
    {
        $mark = $this->book('Mark Smith', 'family@example.com', '10:00:00');
        $jay = $this->book('Jay Smith', 'family@example.com', '11:00:00');
        $this->setStatus((int) $mark['id'], 'confirmed');

        $others = get_same_day_appointments_for_email($jay);

        $this->assertFalse($others[0]['can_confirm']);
        $this->assertTrue($others[0]['can_delete']);
    }

    public function testNoActionsForAppointmentsThatHaveAlreadyHappened(): void
    {
        $past = '2000-01-03';   // a Monday long ago
        $mark = $this->book('Mark Smith', 'family@example.com', '10:00:00', null, $past);
        $jay = $this->book('Jay Smith', 'family@example.com', '11:00:00', null, $past);

        $block = build_same_day_appointments_email_block($jay, true);

        $this->assertSame(1, $block['count'], 'still listed');
        $this->assertStringNotContainsString('confirm_appointment.php', $block['html']);
        $this->assertFalse(get_same_day_appointments_for_email($jay)[0]['can_delete']);
        $this->assertSame($mark['id'], get_same_day_appointments_for_email($jay)[0]['id']);
    }

    public function testNoAllButtonsWhenOnlyOneAppointmentIsActionable(): void
    {
        $mark = $this->book('Mark Smith', 'family@example.com', '10:00:00');
        $jay = $this->book('Jay Smith', 'family@example.com', '11:00:00');
        $this->setStatus((int) $mark['id'], 'completed');

        $block = build_same_day_appointments_email_block($jay, true);

        $this->assertStringNotContainsString('Confirm all', $block['html']);
    }
}
