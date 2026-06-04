<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();
header('Content-Type: application/json; charset=utf-8');

function booking_api_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function booking_timezone(): DateTimeZone
{
    return new DateTimeZone(get_app_timezone_name());
}

function booking_parse_date(string $value): ?DateTimeImmutable
{
    $raw = trim($value);
    if ($raw === '') return null;
    try {
        return new DateTimeImmutable($raw, booking_timezone());
    } catch (Throwable $e) {
        return null;
    }
}

function booking_parse_month(string $value): ?DateTimeImmutable
{
    $raw = trim($value);
    if ($raw === '') return null;
    try {
        $date = new DateTimeImmutable($raw . '-01', booking_timezone());
        return $date->modify('first day of this month')->setTime(0, 0, 0);
    } catch (Throwable $e) {
        return null;
    }
}

function booking_to_date_key(DateTimeImmutable $date): string { return $date->format('Y-m-d'); }
function booking_to_time_key(DateTimeImmutable $date): string { return $date->format('H:i:s'); }
function booking_slot_label(DateTimeImmutable $date): string { return $date->format('g:ia'); }

function booking_visible_time(DateTimeImmutable $date, string $timeValue): DateTimeImmutable
{
    $parts = explode(':', trim($timeValue));
    $hours = isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : 0;
    $minutes = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
    $seconds = isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : 0;
    return $date->setTime($hours, $minutes, $seconds);
}

function booking_add_minutes(DateTimeImmutable $date, int $minutes): DateTimeImmutable
{
    return $date->modify('+' . max(1, $minutes) . ' minutes');
}

function booking_server_now(): DateTimeImmutable
{
    return new DateTimeImmutable(get_time('Y-m-d H:i:s'), booking_timezone());
}

function booking_conflict_end_time(?array $conflict): ?DateTimeImmutable
{
    if (!$conflict) return null;
    $endValue = trim((string) ($conflict['end_time'] ?? $conflict['break_end'] ?? ''));
    if ($endValue === '') return null;
    try {
        return new DateTimeImmutable($endValue, booking_timezone());
    } catch (Throwable $e) {
        return null;
    }
}

function booking_get_appointment_duration(int $appointmentTypeId): ?int
{
    $appointmentType = get_appointment_type_by_id($appointmentTypeId);
    if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) return null;
    return max(1, (int) ($appointmentType['duration_minutes'] ?? 30));
}

function booking_validate_day_slots(
    int $providerId,
    DateTimeImmutable $date,
    int $appointmentTypeId,
    array $prefetchedAppointments = [],  // ← new
    array $prefetchedRecurring = [],     // ← new
    array $prefetchedSingle = []         // ← new
): array {
    $durationMinutes = booking_get_appointment_duration($appointmentTypeId);
    if ($durationMinutes === null) return [];

    $bookingSettings = get_booking_calendar_settings(); // cached, free
    if (!is_booking_day_enabled($date, $bookingSettings)) return [];

    $serverNow   = booking_server_now();
    $todayStart  = $serverNow->setTime(0, 0, 0);
    $dayStartDate = $date->setTime(0, 0, 0);
    if ($dayStartDate < $todayStart) return [];

    $dayStart  = booking_visible_time($date, (string) $bookingSettings['start_time']);
    $dayEnd    = booking_visible_time($date, (string) $bookingSettings['end_time']);
    $lastStart = $dayEnd->modify('-' . $durationMinutes . ' minutes');
    if ($lastStart < $dayStart) return [];

    $tz = booking_timezone();
    $dayKey = $date->format('Y-m-d');

    // Filter prefetched appointments down to this day
    $dayAppointments = array_filter($prefetchedAppointments, function($appt) use ($dayKey) {
        return str_starts_with((string)$appt['start_time'], $dayKey)
            || str_starts_with((string)$appt['end_time'], $dayKey)
            || ($appt['start_time'] < $dayKey . ' 23:59:59' && $appt['end_time'] > $dayKey . ' 00:00:00');
    });

    // Filter recurring unavailabilities to this day-of-week
    $dayOfWeek = (int) $date->format('N');
    $dayRecurring = array_filter($prefetchedRecurring, function($r) use ($dayOfWeek, $dayKey) {
        return (int)$r['day_of_week'] === $dayOfWeek
            && $r['rolling_start_date'] <= $dayKey
            && ($r['rolling_end_date'] === null || $r['rolling_end_date'] >= $dayKey);
    });

    // Filter single blocks overlapping this day
    $daySingle = array_filter($prefetchedSingle, function($s) use ($dayKey) {
        return $s['start_datetime'] < $dayKey . ' 23:59:59'
            && $s['end_datetime'] > $dayKey . ' 00:00:00';
    });

    $slots = [];
    $cursor = $dayStart;

    while ($cursor <= $lastStart) {
        if ($cursor < $serverNow) {
            $cursor = booking_add_minutes($cursor, $durationMinutes);
            continue;
        }

        $slotEnd = booking_add_minutes($cursor, $durationMinutes);
        $slotStartStr = $cursor->format('Y-m-d H:i:s');
        $slotEndStr   = $slotEnd->format('Y-m-d H:i:s');
        $blocked = false;

        // Check appointment overlaps (in-memory)
        foreach ($dayAppointments as $appt) {
            if ($appt['start_time'] < $slotEndStr && $appt['end_time'] > $slotStartStr) {
                // Jump cursor past this appointment
                $cursor = new DateTimeImmutable($appt['end_time'], $tz);
                $blocked = true;
                break;
            }
        }
        if ($blocked) continue;

        // Check standard breaks (already cached by get_all_standard_breaks())
        $breakConflict = find_overlapping_break($cursor, $slotEnd);
        if ($breakConflict) {
            $breakEnd = new DateTimeImmutable($breakConflict['break_end'], $tz);
            $cursor = $breakEnd > $cursor ? $breakEnd : booking_add_minutes($cursor, $durationMinutes);
            continue;
        }

        // Check single unavailability blocks (in-memory)
        foreach ($daySingle as $s) {
            if ($s['start_datetime'] < $slotEndStr && $s['end_datetime'] > $slotStartStr) {
                $cursor = new DateTimeImmutable($s['end_datetime'], $tz);
                $blocked = true;
                break;
            }
        }
        if ($blocked) continue;

        // Check recurring unavailabilities (in-memory)
        $slotStartSec = (int)$cursor->format('H') * 3600 + (int)$cursor->format('i') * 60;
        $slotEndSec   = (int)$slotEnd->format('H') * 3600 + (int)$slotEnd->format('i') * 60;
        foreach ($dayRecurring as $r) {
            [$rh, $rm] = explode(':', $r['start_time']);
            [$eh, $em] = explode(':', $r['end_time']);
            $rStart = (int)$rh * 3600 + (int)$rm * 60;
            $rEnd   = (int)$eh * 3600 + (int)$em * 60;
            if ($rStart < $slotEndSec && $rEnd > $slotStartSec) {
                // Jump to end of recurring block
                $cursor = booking_visible_time($date, $r['end_time']);
                $blocked = true;
                break;
            }
        }
        if ($blocked) continue;

        $slots[] = [
            'start' => $slotStartStr,
            'end'   => $slotEndStr,
            'label' => booking_slot_label($cursor),
            'value' => $slotStartStr,
        ];
        $cursor = booking_add_minutes($cursor, $durationMinutes);
    }

    return $slots;
}

function booking_build_month_view(int $providerId, int $appointmentTypeId, DateTimeImmutable $monthStart): array
{
    $bookingSettings = get_booking_calendar_settings(); // now cached
    $weekStartDay = (int) ($bookingSettings['week_start_day'] ?? 0);
    $monthEnd = $monthStart->modify('first day of next month')->setTime(0, 0, 0);
    $serverNow = booking_server_now();
    $todayStart = $serverNow->setTime(0, 0, 0);

    // ── Prefetch all appointments for this provider this month ──────────────
    $db = get_db();
    $monthStartStr = $monthStart->format('Y-m-d 00:00:00');
    $monthEndStr   = $monthEnd->format('Y-m-d 00:00:00');

    $stmt = $db->prepare("
        SELECT start_time, end_time
        FROM appointments
        WHERE provider_id = ?
          AND start_time < ? AND end_time > ?
          AND status NOT IN ('cancelled', 'no_show')
    ");
    $stmt->execute([$providerId, $monthEndStr, $monthStartStr]);
    $monthAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Prefetch recurring unavailabilities for this provider this month ────
    $stmt2 = $db->prepare("
        SELECT start_time, end_time, day_of_week, reason, rolling_start_date, rolling_end_date
        FROM unavailabilities
        WHERE provider_id = ? AND type = 'recurring'
          AND rolling_start_date <= ?
          AND (rolling_end_date IS NULL OR rolling_end_date >= ?)
    ");
    $stmt2->execute([$providerId, $monthEnd->format('Y-m-d'), $monthStart->format('Y-m-d')]);
    $recurringUnavail = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // ── Prefetch single-block unavailabilities for this month ───────────────
    $stmt3 = $db->prepare("
        SELECT start_datetime, end_datetime, reason
        FROM unavailabilities
        WHERE provider_id = ? AND type = 'single'
          AND start_datetime < ? AND end_datetime > ?
    ");
    $stmt3->execute([$providerId, $monthEndStr, $monthStartStr]);
    $singleUnavail = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    // Build day entries
    $days = [];
    $leadingBlankCount = ((int) $monthStart->format('w') - $weekStartDay + 7) % 7;
    for ($i = 0; $i < $leadingBlankCount; $i++) {
        $days[] = ['date' => null, 'day' => null, 'weekday' => null, 'in_month' => false,
                   'is_today' => false, 'has_slots' => false, 'slot_count' => 0];
    }

    $cursor = $monthStart->setTime(0, 0, 0);
    while ($cursor < $monthEnd) {
        $isPastDay = $cursor < $todayStart;
        $slots = $isPastDay ? [] : booking_validate_day_slots(
            $providerId, $cursor, $appointmentTypeId,
            $monthAppointments, $recurringUnavail, $singleUnavail // ← pass prefetched data
        );
        $days[] = [
            'date'      => $cursor->format('Y-m-d'),
            'day'       => (int) $cursor->format('j'),
            'weekday'   => (int) $cursor->format('w'),
            'in_month'  => true,
            'is_today'  => $cursor->format('Y-m-d') === $todayStart->format('Y-m-d'),
            'has_slots' => !$isPastDay && !empty($slots),
            'slot_count'=> $isPastDay ? 0 : count($slots),
        ];
        $cursor = $cursor->modify('+1 day');
    }

    $trailingBlankCount = (7 - (count($days) % 7)) % 7;
    for ($i = 0; $i < $trailingBlankCount; $i++) {
        $days[] = ['date' => null, 'day' => null, 'weekday' => null, 'in_month' => false,
                   'is_today' => false, 'has_slots' => false, 'slot_count' => 0];
    }

    $weekdayLabels = [];
    $labelMap = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    for ($offset = 0; $offset < 7; $offset++) {
        $weekdayLabels[] = $labelMap[($weekStartDay + $offset) % 7];
    }

    return [
        'month'          => $monthStart->format('Y-m'),
        'label'          => $monthStart->format('F Y'),
        'week_start_day' => $weekStartDay,
        'weekday_labels' => $weekdayLabels,
        'days'           => $days,
    ];
}

function booking_find_exact_customer_matches(string $email, string $mobile): array
{
    return get_customers_by_exact_contact($email, $mobile);
}

function booking_compose_customer_name(array $data): string
{
    $parts = [];
    foreach (['customer_first_name', 'customer_middle_name', 'customer_last_name'] as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return $parts ? trim(implode(' ', $parts)) : trim((string) ($data['customer_name'] ?? ''));
}

function booking_resolve_customer_name(array $data, string $email = ''): string
{
    $name = booking_compose_customer_name($data);
    if ($name !== '') return $name;

    $email = trim($email);
    if ($email !== '' && str_contains($email, '@')) {
        $localPart = trim((string) strstr($email, '@', true));
        if ($localPart !== '') {
            $localPart = preg_replace('/[._-]+/', ' ', $localPart) ?: $localPart;
            $localPart = trim(preg_replace('/\s+/', ' ', $localPart) ?: $localPart);
            if ($localPart !== '') return ucwords($localPart);
        }
    }
    return 'New Customer';
}
function booking_extract_client_meta(array $data): array
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] 
        ?? $_SERVER['HTTP_X_REAL_IP'] 
        ?? $_SERVER['REMOTE_ADDR'] 
        ?? null;

    // X-Forwarded-For can be a comma-separated list; take the first
    if ($ip && str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }

    return [
        'last_ip'         => $ip ? substr($ip, 0, 45) : null,
        'last_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) 
                               ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) 
                               : null,
        'last_timezone'   => isset($data['client_timezone']) 
                               ? substr(trim($data['client_timezone']), 0, 64) 
                               : null,
        'last_screen'     => isset($data['client_screen']) 
                               ? substr(trim($data['client_screen']), 0, 32) 
                               : null,
        'last_locale'     => isset($data['client_locale']) 
                               ? substr(trim($data['client_locale']), 0, 32) 
                               : null,
        'last_seen_at'    => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ];
}

function booking_validate_new_customer_data(array $data): array
{
    $settings = get_customer_field_settings();
    $errors = [];

    $fields = [
        'first_name' => (string) ($data['customer_first_name'] ?? ''),
        'middle_name' => (string) ($data['customer_middle_name'] ?? ''),
        'last_name' => (string) ($data['customer_last_name'] ?? ''),
        'email' => (string) ($data['customer_email'] ?? ''),
        'mobile' => (string) ($data['customer_mobile'] ?? ''),
        'date_of_birth' => (string) ($data['customer_date_of_birth'] ?? ''),
        'gender' => (string) ($data['customer_gender'] ?? ''),
    ];

    foreach ($fields as $fieldName => $value) {
        $cfg = $settings[$fieldName] ?? null;
        if (!$cfg) continue;

        $raw = trim($value);
        if ($raw === '' && empty($cfg['display']) && empty($cfg['required'])) continue;

        $error = validate_customer_field($fieldName, $raw);
        if ($error) $errors[$fieldName] = $error;
    }

    if (booking_compose_customer_name($data) === '') {
        $errors['customer_name'] = 'Customer name is required';
    }
    return [$errors, booking_compose_customer_name($data)];
}

function booking_send_verification_email(array $appointment, array $provider, array $appointmentType, array $matches): bool
{
    if (!email_transport_is_ready()) return false;

    $siteName = trim((string) get_setting('site_name', APP_NAME ?? 'Boomy Appointments'));
    if ($siteName === '') $siteName = APP_NAME ?? 'Boomy Appointments';

    $verification = get_appointment_verification_by_appointment_id((int) ($appointment['id'] ?? 0));
    $verifyCode = (string) ($verification['code'] ?? '');
    if ($verifyCode === '') return false;

    $verifyUrl = build_app_url('verify_appointment.php', ['token' => $verifyCode]);
    $providerName = format_appointment_person_name($provider) ?: 'Selected provider';
    $startDate = format_app_datetime((string) ($appointment['start_time'] ?? ''));
    $appointmentTypeName = trim((string) ($appointmentType['name'] ?? 'Appointment'));
    $reasonLine = count($matches) > 1 ? 'We found multiple customer profiles that share this contact.' : 'We need one more step before the booking can be completed.';

    $subject = 'Appointment confirmation required for ' . $siteName;
    $bodyHtml = '<h2>Appointment confirmation required</h2>'
        . '<p>' . htmlspecialchars($reasonLine, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<ul>'
        . '<li><strong>Appointment type:</strong> ' . htmlspecialchars($appointmentTypeName, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Provider:</strong> ' . htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Start time:</strong> ' . htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>'
        . '<p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#2457ff;color:#fff;display:inline-block;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:700;">Verify customer details</a></p>';

    $bodyPlain = "Appointment confirmation required for {$siteName}\n\n{$reasonLine}\n\nAppointment type: {$appointmentTypeName}\nProvider: {$providerName}\nStart time: {$startDate}\n\nVerify customer details: {$verifyUrl}\n";

    $customer = get_customer_by_id((int) ($appointment['customer_id'] ?? 0));
    $recipient = trim((string) ($customer['email'] ?? ''));
    if ($recipient === '') return false;

    return send_email($recipient, $subject, $bodyHtml, $bodyPlain) === true;
}

function booking_send_confirmation_email(array $appointment): bool
{
    $result = send_appointment_confirmation_email((int) ($appointment['id'] ?? 0));
    return !empty($result['ok']);
}

function booking_respond_with_appointment(array $appointment): void
{
    $provider = get_user_by_id((int) ($appointment['provider_id'] ?? 0));
    $appointmentType = get_appointment_type_by_id((int) ($appointment['appointment_type_id'] ?? 0));
    $customer = !empty($appointment['customer_id']) ? get_customer_by_id((int) $appointment['customer_id']) : null;
    $matches = $customer ? booking_find_exact_customer_matches((string) ($customer['email'] ?? ''), (string) ($customer['mobile'] ?? '')) : [];

    booking_api_response([
        'ok' => true,
        'appointment' => $appointment,
        'provider' => $provider,
        'appointment_type' => $appointmentType,
        'customer' => $customer,
        'matches' => $matches,
    ]);
}

try {
    $action = strtolower(trim((string) ($_GET['action'] ?? 'init')));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

   if ($action === 'init' && $method === 'GET') {
        $appointmentTypes = get_appointment_types(true);
        $stmt = get_db()->query("SELECT id, email, full_name, role, is_active FROM users WHERE role IN ('provider','admin') AND is_active = 1 ORDER BY full_name ASC");
        $providers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $settings = get_booking_calendar_settings();

        booking_api_response([
            'ok' => true,
            'csrf_token' => csrf_token(),
            'site_name' => get_setting('site_name', APP_NAME ?? 'Boomy Appointments'),
            'timezone' => get_app_timezone_name(),
            'settings' => $settings,
            'prevent_same_user_booking' => (int) get_setting('prevent_same_user_booking', 0), // <-- EXPLICIT ROOT PROPERTY
            'customer_field_settings' => get_customer_field_settings(),
            'appointment_types' => $appointmentTypes,
            'providers' => $providers,
            'month_start' => booking_server_now()->modify('first day of this month')->format('Y-m-d'),
            'next_month_start' => booking_server_now()->modify('first day of next month')->format('Y-m-d'),
        ]);
    }
if ($action === 'month' && $method === 'GET') {
    $__t = ['start' => microtime(true)];

    $providerId        = (int) ($_GET['provider_id'] ?? 0);
    $appointmentTypeId = (int) ($_GET['appointment_type_id'] ?? 0);
    $month             = booking_parse_month((string) ($_GET['month'] ?? ''));
    $__t['after_parse'] = microtime(true);

    if ($providerId < 1 || $appointmentTypeId < 1 || !$month) {
        booking_api_response(['error' => 'provider_id, appointment_type_id, and month are required'], 422);
    }

    $provider = get_user_by_id($providerId);
    $__t['after_get_provider'] = microtime(true);

    $appointmentType = get_appointment_type_by_id($appointmentTypeId);
    $__t['after_get_type'] = microtime(true);

    if (!$provider || !in_array((string) ($provider['role'] ?? ''), ['provider', 'admin'], true)) {
        booking_api_response(['error' => 'provider not found'], 404);
    }
    if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
        booking_api_response(['error' => 'appointment type not found'], 404);
    }

    $monthView = booking_build_month_view($providerId, $appointmentTypeId, $month);
    $__t['after_build_month'] = microtime(true);

    $base = $__t['start'];
    $timings = [];
    foreach ($__t as $k => $v) {
        if ($k === 'start') continue;
        $timings[$k] = round(($v - $base) * 1000) . 'ms';
    }

    booking_api_response([
        'ok'       => true,
        'month'    => $monthView,
        '_timings' => $timings,
    ]);
}

    if ($action === 'day_slots' && $method === 'GET') {
        $providerId = (int) ($_GET['provider_id'] ?? 0);
        $appointmentTypeId = (int) ($_GET['appointment_type_id'] ?? 0);
        $date = booking_parse_date((string) ($_GET['date'] ?? ''));

        if ($providerId < 1 || $appointmentTypeId < 1 || !$date) {
            booking_api_response(['error' => 'provider_id, appointment_type_id, and date are required'], 422);
        }

        $provider = get_user_by_id($providerId);
        if (!$provider || !in_array((string) ($provider['role'] ?? ''), ['provider', 'admin'], true)) {
            booking_api_response(['error' => 'provider not found'], 404);
        }

        $appointmentType = get_appointment_type_by_id($appointmentTypeId);
        if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
            booking_api_response(['error' => 'appointment type not found'], 404);
        }

        $slots = booking_validate_day_slots($providerId, $date->setTime(0, 0, 0), $appointmentTypeId);
        booking_api_response([
            'ok' => true,
            'date' => $date->format('Y-m-d'),
            'label' => $date->format('l, F j, Y'),
            'slots' => $slots,
            'slot_count' => count($slots),
            'has_slots' => !empty($slots),
        ]);
    }

    if ($action === 'customer_check' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = (string) ($data['csrf_token'] ?? '');
        if (!verify_csrf_token($token)) booking_api_response(['error' => 'csrf'], 400);

        $mode = strtolower(trim((string) ($data['customer_mode'] ?? 'new')));
        $email = trim((string) ($data['customer_email'] ?? ''));
        $mobile = trim((string) ($data['customer_mobile'] ?? ''));

        if ($email === '' || $mobile === '') booking_api_response(['error' => 'email and mobile are required'], 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) booking_api_response(['error' => 'invalid email'], 422);

        $matches = booking_find_exact_customer_matches($email, $mobile);
        $matchCount = count($matches);

        if ($mode === 'existing') {
            if ($matchCount < 1) booking_api_response(['error' => 'No customer matches this email and mobile combination'], 404);
            booking_api_response([
                'ok' => true, 'customer_mode' => 'existing', 'match_count' => $matchCount,
                'requires_verification' => $matchCount > 1, 'matches' => $matches,
                'selected_customer' => $matchCount === 1 ? $matches[0] : null,
            ]);
        }
        $matches = booking_find_exact_customer_matches($email, $mobile);
        $matchCount = count($matches);
        booking_api_response([
            'ok' => true,
            'customer_mode' => 'new',
            'match_count' => $matchCount,
            'duplicate_contact' => $matchCount > 0,
            'can_continue' => true,
            'matches' => $matches,
        ]);

    }

    if ($action === 'request' && $method === 'GET') {
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '') booking_api_response(['error' => 'token is required'], 422);

        $verification = get_appointment_verification_by_code($token);
        if (!$verification) booking_api_response(['error' => 'request not found'], 404);

        $appointmentRow = get_appointment_by_id((int) ($verification['appointment_id'] ?? 0));
        if (!$appointmentRow) booking_api_response(['error' => 'request not found'], 404);

        booking_respond_with_appointment($appointmentRow);
    }

    if ($action === 'create' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = (string) ($data['csrf_token'] ?? '');
        if (!verify_csrf_token($token)) booking_api_response(['error' => 'csrf'], 400);

        $providerId = (int) ($data['provider_id'] ?? 0);
        $appointmentTypeId = (int) ($data['appointment_type_id'] ?? 0);
        $startTime = trim((string) ($data['start_time'] ?? ''));
        $customerMode = strtolower(trim((string) ($data['customer_mode'] ?? 'new')));
        $customerEmail = trim((string) ($data['customer_email'] ?? ''));
        $customerMobile = trim((string) ($data['customer_mobile'] ?? ''));
        $customerDob = trim((string) ($data['customer_date_of_birth'] ?? ''));
        $customerGender = trim((string) ($data['customer_gender'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($providerId < 1 || $appointmentTypeId < 1 || $startTime === '') {
            booking_api_response(['error' => 'provider, appointment type, and start time are required'], 422);
        }

        $provider = get_user_by_id($providerId);
        if (!$provider || !in_array((string) ($provider['role'] ?? ''), ['provider', 'admin'], true)) {
            booking_api_response(['error' => 'provider not found'], 404);
        }

        $appointmentType = get_appointment_type_by_id($appointmentTypeId);
        if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
            booking_api_response(['error' => 'appointment type not found'], 404);
        }

        $startDate = booking_parse_date($startTime);
        if (!$startDate) booking_api_response(['error' => 'invalid start time'], 422);

        $durationMinutes = max(1, (int) ($appointmentType['duration_minutes'] ?? 30));
        $endDate = booking_add_minutes($startDate, $durationMinutes);
        $windowError = validate_appointment_window($providerId, $startDate, $endDate);
        
        if ($windowError) {
            booking_api_response([
                'error' => $windowError['message'] ?? 'Selected time is not available',
                'code' => $windowError['error'] ?? 'outside_booking_hours',
            ], 422);
        }

        $matches = booking_find_exact_customer_matches($customerEmail, $customerMobile);
        $matchCount = count($matches);
        $notesValue = $notes !== '' ? $notes : null;
        $allowDuplicate = !empty($data['allow_duplicate']);
        $needsVerification = ($matchCount > 0) && !$allowDuplicate;
        $resolvedCustomerName = booking_resolve_customer_name($data, $customerEmail);

        if ($customerMode === 'existing') {
            if ($matchCount < 1) booking_api_response(['error' => 'No customer matches this email and mobile combination'], 404);
        } else {
            if ($customerEmail === '' || $customerMobile === '') booking_api_response(['error' => 'email and mobile are required'], 422);
        }

        if ($needsVerification) {
            $customer = null;
            if ($customerMode === 'existing') {
                $customer = $matches[0] ?? null;
            } else {
                $customerId = create_customer($resolvedCustomerName, $customerEmail ?: null, $customerMobile ?: null, $customerDob !== '' ? $customerDob : null, $customerGender !== '' ? $customerGender : null);
                if (!$customerId) booking_api_response(['error' => 'Failed to create customer'], 500);
                $customer = get_customer_by_id($customerId);
            }

            if (!$customer) booking_api_response(['error' => 'Failed to load customer'], 500);
update_customer_meta((int) $customer['id'], booking_extract_client_meta($data));

            $appointment = create_appointment((int)$customer['id'], $providerId, $appointmentTypeId, $startDate->format('Y-m-d H:i:s'), 'unconfirmed', null, $notesValue);
            if (!$appointment) booking_api_response(['error' => 'Failed to create appointment'], 500);

            $verificationCode = create_appointment_verification_token((int) $appointment['id']);
            $emailSent = booking_send_verification_email($appointment, $provider, $appointmentType, $matches);
            
            booking_api_response([
                'ok' => true, 'pending' => true, 'requires_verification' => true, 'appointment' => $appointment,
                'verification_email_sent' => $emailSent, 'verify_url' => build_app_url('verify_appointment.php', ['token' => $verificationCode]),
                'message' => 'Appointment request received. Please check your email to complete booking.',
            ]);
        }

        $customer = null;
        if ($customerMode === 'existing') {
            $customer = $matches[0] ?? null;
        } else {
            $customerId = create_customer($resolvedCustomerName, $customerEmail ?: null, $customerMobile ?: null, $customerDob !== '' ? $customerDob : null, $customerGender !== '' ? $customerGender : null);
            if (!$customerId) booking_api_response(['error' => 'Failed to create customer'], 500);
            $customer = get_customer_by_id($customerId);
        }

        if (!$customer) booking_api_response(['error' => 'Failed to load customer'], 500);
update_customer_meta((int) $customer['id'], booking_extract_client_meta($data));

        $appointment = create_appointment((int) $customer['id'], $providerId, $appointmentTypeId, $startDate->format('Y-m-d H:i:s'), 'booked', null, $notesValue);
        if (!$appointment) booking_api_response(['error' => 'Failed to create appointment'], 500);

        if (isset($appointment['error'])) {
            $code = ($appointment['error'] === 'overlap' || $appointment['error'] === 'break_overlap') ? 409 : 422;
            $errorMessage = 'Selected time is not available';
            if ($appointment['error'] === 'break_overlap') $errorMessage = 'Booking overlaps a standard break';
            else if ($appointment['error'] === 'overlap') $errorMessage = 'Booking overlaps an existing appointment';
            else if ($appointment['error'] === 'provider_unavailable') $errorMessage = $appointment['message'] ?? 'Provider is unavailable during this time slot';

            booking_api_response(['error' => $errorMessage, 'conflict' => $appointment['conflict'] ?? null], $code);
        }

        $emailSent = booking_send_confirmation_email($appointment);
        booking_api_response(['ok' => true, 'pending' => false, 'customer' => $customer, 'appointment' => $appointment, 'email_sent' => $emailSent, 'message' => 'Booking created successfully']);
    }

    if ($action === 'verify' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = (string) ($data['csrf_token'] ?? '');
        if (!verify_csrf_token($token)) booking_api_response(['error' => 'csrf'], 400);

        $appointmentToken = trim((string) ($data['token'] ?? ''));
        if ($appointmentToken === '') booking_api_response(['error' => 'token is required'], 422);

        $verification = get_appointment_verification_by_code($appointmentToken);
        if (!$verification) booking_api_response(['error' => 'request not found'], 404);

        $appointmentRow = get_appointment_by_id((int) ($verification['appointment_id'] ?? 0));
        if (!$appointmentRow) booking_api_response(['error' => 'request not found'], 404);

        $customerOption = trim((string) ($data['customer_option'] ?? 'current'));
        $inputFirstName = trim((string) ($data['first_name'] ?? ''));
        $inputLastName  = trim((string) ($data['last_name'] ?? ''));

        $originalCustomerId = (int) ($appointmentRow['customer_id'] ?? 0);
        $originalCustomer   = get_customer_by_id($originalCustomerId);
        if (!$originalCustomer) booking_api_response(['error' => 'Original booking customer details could not be found'], 422);

        $customerIdToAssign = $originalCustomerId;

        if ($customerOption === 'new') {
            if ($inputFirstName === '' || $inputLastName === '') booking_api_response(['error' => 'First and last name are required.'], 422);

            $newCustomerId = create_customer(
                trim($inputFirstName . ' ' . $inputLastName),
                !empty($originalCustomer['email']) ? (string) $originalCustomer['email'] : null,
                !empty($originalCustomer['mobile']) ? (string) $originalCustomer['mobile'] : null,
                !empty($originalCustomer['date_of_birth']) ? (string) $originalCustomer['date_of_birth'] : null,
                !empty($originalCustomer['gender']) ? (string) $originalCustomer['gender'] : null
            );

            if (!$newCustomerId) booking_api_response(['error' => 'Failed to build customer profile'], 500);
            $customerIdToAssign = (int) $newCustomerId;
        } else if (is_numeric($customerOption)) {
            $customerIdToAssign = (int) $customerOption;
        }

        if ((string) ($appointmentRow['status'] ?? '') !== 'unconfirmed') {
            booking_api_response(['ok' => true, 'status' => (string) ($appointmentRow['status'] ?? 'booked'), 'appointment' => $appointmentRow, 'customer' => get_customer_by_id($customerIdToAssign)]);
        }

        $provider = get_user_by_id((int) ($appointmentRow['provider_id'] ?? 0));
        $appointmentType = get_appointment_type_by_id((int) ($appointmentRow['appointment_type_id'] ?? 0));
        if (!$provider || !$appointmentType) booking_api_response(['error' => 'booking appointment data is invalid'], 422);

        $appointment = update_appointment((int) $appointmentRow['id'], $customerIdToAssign, (int) $appointmentRow['provider_id'], (int) $appointmentRow['appointment_type_id'], (string) $appointmentRow['start_time'], (string) $appointmentRow['end_time'], 'booked', !empty($appointmentRow['notes']) ? (string) $appointmentRow['notes'] : null);
        if (!$appointment) booking_api_response(['error' => 'Failed to finalize appointment'], 500);

        delete_verification_token((string) ($verification['code'] ?? ''));
        $emailSent = booking_send_confirmation_email($appointment);
        booking_api_response(['ok' => true, 'status' => 'completed', 'customer' => get_customer_by_id((int) $appointment['customer_id']), 'appointment' => $appointment, 'email_sent' => $emailSent, 'message' => 'Appointment verified and booked successfully']);
    }
if ($action === 'check_metadata_limit' && $method === 'POST') {
    // 1. Temporarily skip CSRF verification for direct browser link testing
    // if (!verify_csrf_token($_GET['csrf_token'] ?? null)) { ... }

    // 2. Fetch the threshold limit setting
    $limit = (int) get_setting('prevent_same_user_booking', 0);
    
    // 3. Collect visitor meta telemetry
    $clientIp  = get_client_ip();
    $timezone  = trim((string)($_GET['client_timezone'] ?? ''));
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $db = get_db();

    // 4. Wide-Net Query: Grab all active appointments sharing this IP address
    $query = "SELECT a.id, c.id AS customer_id, c.last_ip, c.last_timezone, c.last_user_agent 
              FROM appointments a
              INNER JOIN customers c ON a.customer_id = c.id
              WHERE a.status IN ('booked', 'confirmed', 'unconfirmed')
              AND c.last_ip = ?";

    $stmt = $db->prepare($query);
    $stmt->execute([$clientIp]);
    $ipMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Weight-matching loop logic
    $matchCount = 0;
    foreach ($ipMatches as $record) {
        // NOTE: If testing from the same browser/timezone, these will usually match
        $sameTimezone  = (!empty($record['last_timezone']) && $record['last_timezone'] === $timezone);
        $sameUserAgent = (!empty($record['last_user_agent']) && $record['last_user_agent'] === $userAgent);

        // For this live browser test, if your database records have blank timezones, 
        // let's count them purely on IP + UserAgent matching.
        if ($sameUserAgent || $sameTimezone || empty($timezone)) {
            $matchCount++;
        }
    }

    // 6. Return comprehensive debug payload so you can see your data rows
    booking_api_response([
        'is_blocked'       => ($matchCount >= $limit),
        'match_count'      => $matchCount,
        'limit'            => $limit,
        'your_current_ip'  => $clientIp,
        'database_records' => $ipMatches
    ]);
    exit;
}

    booking_api_response(['error' => 'unknown action'], 400);
} catch (Throwable $e) {
    booking_api_response(['error' => $e->getMessage()], 500);
}