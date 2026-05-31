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
    if ($raw === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($raw, booking_timezone());
    } catch (Throwable $e) {
        return null;
    }
}

function booking_parse_month(string $value): ?DateTimeImmutable
{
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($raw . '-01', booking_timezone());
        return $date->modify('first day of this month')->setTime(0, 0, 0);
    } catch (Throwable $e) {
        return null;
    }
}

function booking_to_date_key(DateTimeImmutable $date): string
{
    return $date->format('Y-m-d');
}

function booking_to_time_key(DateTimeImmutable $date): string
{
    return $date->format('H:i:s');
}

function booking_slot_label(DateTimeImmutable $date): string
{
    return $date->format('g:ia');
}

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
    if (!$conflict) {
        return null;
    }

    $endValue = trim((string) ($conflict['end_time'] ?? $conflict['break_end'] ?? ''));
    if ($endValue === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($endValue, booking_timezone());
    } catch (Throwable $e) {
        return null;
    }
}

function booking_get_appointment_duration(int $appointmentTypeId): ?int
{
    $appointmentType = get_appointment_type_by_id($appointmentTypeId);
    if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
        return null;
    }

    return max(1, (int) ($appointmentType['duration_minutes'] ?? 30));
}

function booking_validate_day_slots(int $providerId, DateTimeImmutable $date, int $appointmentTypeId): array
{
    $durationMinutes = booking_get_appointment_duration($appointmentTypeId);
    if ($durationMinutes === null) {
        return [];
    }

    $bookingSettings = get_booking_calendar_settings();
    $serverNow = booking_server_now();
    $dayStartDate = $date->setTime(0, 0, 0);
    $todayStart = $serverNow->setTime(0, 0, 0);

    if ($dayStartDate < $todayStart) {
        return [];
    }

    $dayStart = booking_visible_time($date, (string) $bookingSettings['start_time']);
    $dayEnd = booking_visible_time($date, (string) $bookingSettings['end_time']);
    $lastStart = $dayEnd->modify('-' . $durationMinutes . ' minutes');
    if ($lastStart < $dayStart) {
        return [];
    }

    $slots = [];
    $cursor = $dayStart;
    while ($cursor <= $lastStart) {
        if ($cursor < $serverNow) {
            $cursor = booking_add_minutes($cursor, $durationMinutes);
            continue;
        }

        $slotEnd = booking_add_minutes($cursor, $durationMinutes);
        $windowError = validate_appointment_window($providerId, $cursor, $slotEnd);
        if ($windowError === null) {
            $slots[] = [
                'start' => $cursor->format('Y-m-d H:i:s'),
                'end' => $slotEnd->format('Y-m-d H:i:s'),
                'label' => booking_slot_label($cursor),
                'value' => $cursor->format('Y-m-d H:i:s'),
            ];

            $cursor = booking_add_minutes($cursor, $durationMinutes);
            continue;
        }

        $conflictEnd = booking_conflict_end_time($windowError['conflict'] ?? null);
        if ($conflictEnd instanceof DateTimeImmutable && $conflictEnd > $cursor) {
            $cursor = $conflictEnd;
            continue;
        }

        $cursor = booking_add_minutes($cursor, $durationMinutes);
    }

    return $slots;
}

function booking_build_month_view(int $providerId, int $appointmentTypeId, DateTimeImmutable $monthStart): array
{
    $bookingSettings = get_booking_calendar_settings();
    $weekStartDay = (int) ($bookingSettings['week_start_day'] ?? 0);

    $monthEnd = $monthStart->modify('first day of next month')->setTime(0, 0, 0);
    $serverNow = booking_server_now();
    $todayStart = $serverNow->setTime(0, 0, 0);

    $days = [];
    $leadingBlankCount = ((int) $monthStart->format('w') - $weekStartDay + 7) % 7;
    for ($index = 0; $index < $leadingBlankCount; $index++) {
        $days[] = [
            'date' => null,
            'day' => null,
            'weekday' => null,
            'in_month' => false,
            'is_today' => false,
            'has_slots' => false,
            'slot_count' => 0,
        ];
    }

    $cursor = $monthStart->setTime(0, 0, 0);
    while ($cursor < $monthEnd) {
        $isPastDay = $cursor < $todayStart;
        $slots = $isPastDay ? [] : booking_validate_day_slots($providerId, $cursor, $appointmentTypeId);
        $days[] = [
            'date' => $cursor->format('Y-m-d'),
            'day' => (int) $cursor->format('j'),
            'weekday' => (int) $cursor->format('w'),
            'in_month' => true,
            'is_today' => $cursor->format('Y-m-d') === $todayStart->format('Y-m-d'),
            'has_slots' => !$isPastDay && !empty($slots),
            'slot_count' => $isPastDay ? 0 : count($slots),
        ];
        $cursor = $cursor->modify('+1 day');
    }

    $trailingBlankCount = (7 - (count($days) % 7)) % 7;
    for ($index = 0; $index < $trailingBlankCount; $index++) {
        $days[] = [
            'date' => null,
            'day' => null,
            'weekday' => null,
            'in_month' => false,
            'is_today' => false,
            'has_slots' => false,
            'slot_count' => 0,
        ];
    }

    $weekdayLabels = [];
    $labelMap = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    for ($offset = 0; $offset < 7; $offset++) {
        $weekdayLabels[] = $labelMap[($weekStartDay + $offset) % 7];
    }

    return [
        'month' => $monthStart->format('Y-m'),
        'label' => $monthStart->format('F Y'),
        'week_start_day' => $weekStartDay,
        'weekday_labels' => $weekdayLabels,
        'days' => $days,
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
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    if ($parts) {
        return trim(implode(' ', $parts));
    }

    return trim((string) ($data['customer_name'] ?? ''));
}

function booking_resolve_customer_name(array $data, string $email = ''): string
{
    $name = booking_compose_customer_name($data);
    if ($name !== '') {
        return $name;
    }

    $email = trim($email);
    if ($email !== '' && str_contains($email, '@')) {
        $localPart = trim((string) strstr($email, '@', true));
        if ($localPart !== '') {
            $localPart = preg_replace('/[._-]+/', ' ', $localPart) ?: $localPart;
            $localPart = trim(preg_replace('/\s+/', ' ', $localPart) ?: $localPart);
            if ($localPart !== '') {
                return ucwords($localPart);
            }
        }
    }

    return 'New Customer';
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
        if (!$cfg) {
            continue;
        }

        $raw = trim($value);
        if ($raw === '' && empty($cfg['display']) && empty($cfg['required'])) {
            continue;
        }

        $error = validate_customer_field($fieldName, $raw);
        if ($error) {
            $errors[$fieldName] = $error;
        }
    }

    $fullName = booking_compose_customer_name($data);
    if ($fullName === '') {
        $errors['customer_name'] = 'Customer name is required';
    }

    return [$errors, $fullName];
}

function booking_send_verification_email(array $appointment, array $provider, array $appointmentType, array $matches): bool
{
    if (!email_transport_is_ready()) {
        return false;
    }

    $siteName = trim((string) get_setting('site_name', APP_NAME ?? 'Boomy Appointments'));
    if ($siteName === '') {
        $siteName = APP_NAME ?? 'Boomy Appointments';
    }

    $verification = get_appointment_verification_by_appointment_id((int) ($appointment['id'] ?? 0));
    $verifyCode = (string) ($verification['code'] ?? '');
    if ($verifyCode === '') {
        return false;
    }

    $verifyUrl = build_app_url('verify_appointment.php', ['token' => $verifyCode]);
    $providerName = format_appointment_person_name($provider);
    if ($providerName === '') {
        $providerName = 'Selected provider';
    }

    $startDate = format_app_datetime((string) ($appointment['start_time'] ?? ''));
    $appointmentTypeName = trim((string) ($appointmentType['name'] ?? 'Appointment'));
    $matchCount = count($matches);
    $reasonLine = $matchCount > 1
        ? 'We found multiple customer profiles that share this email and mobile combination.'
        : 'We need one more step before the booking can be completed.';

    $subject = 'Appointment confirmation required for ' . $siteName;
    $bodyHtml = '<h2>Appointment confirmation required</h2>'
        . '<p>' . htmlspecialchars($reasonLine, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>The booking details are ready below. Please verify the customer profile to complete the appointment.</p>'
        . '<ul>'
        . '<li><strong>Appointment type:</strong> ' . htmlspecialchars($appointmentTypeName, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Provider:</strong> ' . htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Start time:</strong> ' . htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>'
        . '<p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#2457ff;color:#fff;display:inline-block;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:700;">Verify customer details</a></p>'
        . '<p>Confirmation cannot be completed until the customer profile is verified.</p>';

    $bodyPlain = "Appointment confirmation required for {$siteName}\n\n"
        . $reasonLine . "\n"
        . "The booking details are ready below. Please verify the customer profile to complete the appointment.\n\n"
        . "Appointment type: {$appointmentTypeName}\n"
        . "Provider: {$providerName}\n"
        . "Start time: {$startDate}\n\n"
        . "Verify customer details: {$verifyUrl}\n\n"
        . "Confirmation cannot be completed until the customer profile is verified.\n";

    $customer = get_customer_by_id((int) ($appointment['customer_id'] ?? 0));
    $recipient = trim((string) ($customer['email'] ?? ''));
    if ($recipient === '') {
        return false;
    }

    $sendResult = send_email($recipient, $subject, $bodyHtml, $bodyPlain);
    return $sendResult === true;
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
        $providers = get_active_booking_providers();
        $settings = get_booking_calendar_settings();

        booking_api_response([
            'ok' => true,
            'csrf_token' => csrf_token(),
            'site_name' => get_setting('site_name', APP_NAME ?? 'Boomy Appointments'),
            'timezone' => get_app_timezone_name(),
            'settings' => $settings,
            'customer_field_settings' => get_customer_field_settings(),
            'appointment_types' => $appointmentTypes,
            'providers' => $providers,
            'month_start' => booking_server_now()->modify('first day of this month')->format('Y-m-d'),
            'next_month_start' => booking_server_now()->modify('first day of next month')->format('Y-m-d'),
        ]);
    }

    if ($action === 'month' && $method === 'GET') {
        $providerId = (int) ($_GET['provider_id'] ?? 0);
        $appointmentTypeId = (int) ($_GET['appointment_type_id'] ?? 0);
        $month = booking_parse_month((string) ($_GET['month'] ?? ''));

        if ($providerId < 1 || $appointmentTypeId < 1 || !$month) {
            booking_api_response(['error' => 'provider_id, appointment_type_id, and month are required'], 422);
        }

        $provider = get_user_by_id($providerId);
        if (!$provider || (int) ($provider['is_active'] ?? 0) !== 1 || !in_array((string) ($provider['role'] ?? ''), ['provider', 'admin'], true)) {
            booking_api_response(['error' => 'provider not found'], 404);
        }

        $appointmentType = get_appointment_type_by_id($appointmentTypeId);
        if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
            booking_api_response(['error' => 'appointment type not found'], 404);
        }

        booking_api_response([
            'ok' => true,
            'month' => booking_build_month_view($providerId, $appointmentTypeId, $month),
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
        if (!$provider || (int) ($provider['is_active'] ?? 0) !== 1 || !in_array((string) ($provider['role'] ?? ''), ['provider', 'admin'], true)) {
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
        if (!verify_csrf_token($token)) {
            booking_api_response(['error' => 'csrf'], 400);
        }

        $mode = strtolower(trim((string) ($data['customer_mode'] ?? 'new')));
        $email = trim((string) ($data['customer_email'] ?? ''));
        $mobile = trim((string) ($data['customer_mobile'] ?? ''));

        if ($email === '' || $mobile === '') {
            booking_api_response(['error' => 'email and mobile are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            booking_api_response(['error' => 'invalid email'], 422);
        }

        $matches = booking_find_exact_customer_matches($email, $mobile);
        $matchCount = count($matches);

        if ($mode === 'existing') {
            if ($matchCount < 1) {
                booking_api_response(['error' => 'No customer matches this email and mobile combination'], 404);
            }

            booking_api_response([
                'ok' => true,
                'customer_mode' => 'existing',
                'match_count' => $matchCount,
                'requires_verification' => $matchCount > 1,
                'matches' => $matches,
                'selected_customer' => $matchCount === 1 ? $matches[0] : null,
            ]);
        }

        booking_api_response([
            'ok' => true,
            'customer_mode' => 'new',
            'match_count' => 0,
            'duplicate_contact' => false,
            'can_continue' => true,
            'matches' => [],
        ]);
    }

    if ($action === 'request' && $method === 'GET') {
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '') {
            booking_api_response(['error' => 'token is required'], 422);
        }

        $verification = get_appointment_verification_by_code($token);
        if (!$verification) {
            booking_api_response(['error' => 'request not found'], 404);
        }

        $appointmentRow = get_appointment_by_id((int) ($verification['appointment_id'] ?? 0));
        if (!$appointmentRow) {
            booking_api_response(['error' => 'request not found'], 404);
        }

        booking_respond_with_appointment($appointmentRow);
    }

    if ($action === 'create' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = (string) ($data['csrf_token'] ?? '');
        if (!verify_csrf_token($token)) {
            booking_api_response(['error' => 'csrf'], 400);
        }

        $providerId = (int) ($data['provider_id'] ?? 0);
        $appointmentTypeId = (int) ($data['appointment_type_id'] ?? 0);
        $startTime = trim((string) ($data['start_time'] ?? ''));
        $customerMode = strtolower(trim((string) ($data['customer_mode'] ?? 'new')));
        $customerName = booking_compose_customer_name($data);
        $customerEmail = trim((string) ($data['customer_email'] ?? ''));
        $customerMobile = trim((string) ($data['customer_mobile'] ?? ''));
        $customerDob = trim((string) ($data['customer_date_of_birth'] ?? ''));
        $customerGender = trim((string) ($data['customer_gender'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));
        $allowDuplicate = !empty($data['allow_duplicate']);

        if ($providerId < 1) {
            booking_api_response(['error' => 'provider is required'], 422);
        }
        if ($appointmentTypeId < 1) {
            booking_api_response(['error' => 'appointment type is required'], 422);
        }
        if ($startTime === '') {
            booking_api_response(['error' => 'start time is required'], 422);
        }

        $provider = get_user_by_id($providerId);
        if (!$provider || (int) ($provider['is_active'] ?? 0) !== 1 || !in_array((string) ($provider['role'] ?? ''), ['provider', 'admin'], true)) {
            booking_api_response(['error' => 'provider not found'], 404);
        }

        $appointmentType = get_appointment_type_by_id($appointmentTypeId);
        if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
            booking_api_response(['error' => 'appointment type not found'], 404);
        }

        $startDate = booking_parse_date($startTime);
        if (!$startDate) {
            booking_api_response(['error' => 'invalid start time'], 422);
        }

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
        
        $needsVerification = ($matchCount > 0); 
        $resolvedCustomerName = booking_resolve_customer_name($data, $customerEmail);

        if ($customerMode === 'existing') {
            if ($matchCount < 1) {
                booking_api_response(['error' => 'No customer matches this email and mobile combination'], 404);
            }
        } else {
            if ($customerEmail === '' || $customerMobile === '') {
                booking_api_response(['error' => 'email and mobile are required'], 422);
            }
        }

        if ($needsVerification) {
            $customer = null;
            if ($customerMode === 'existing') {
                $customer = $matches[0] ?? null;
            } else {
                $customerId = create_customer($resolvedCustomerName, $customerEmail ?: null, $customerMobile ?: null, $customerDob !== '' ? $customerDob : null, $customerGender !== '' ? $customerGender : null);
                if (!$customerId) {
                    booking_api_response(['error' => 'Failed to create customer'], 500);
                }
                $customer = get_customer_by_id($customerId);
            }

            if (!$customer) {
                booking_api_response(['error' => 'Failed to load customer'], 500);
            }

            $appointment = create_appointment(
                (int) $customer['id'],
                $providerId,
                $appointmentTypeId,
                $startDate->format('Y-m-d H:i:s'),
                'unconfirmed',
                null,
                $notesValue
            );

            if (!$appointment) {
                booking_api_response(['error' => 'Failed to create appointment'], 500);
            }

            $verificationCode = create_appointment_verification_token((int) $appointment['id']);
            if ($verificationCode === '') {
                booking_api_response(['error' => 'Failed to create verification token'], 500);
            }

            $emailSent = booking_send_verification_email($appointment, $provider, $appointmentType, $matches);
            
            booking_api_response([
                'ok' => true,
                'pending' => true,
                'requires_verification' => true,
                'appointment' => $appointment,
                'verification_email_sent' => $emailSent,
                'verify_url' => build_app_url('verify_appointment.php', ['token' => $verificationCode]),
                'message' => 'Appointment request received. Please check your email to verify your details and complete the booking.',
            ]);
        }

        $customer = null;
        if ($customerMode === 'existing') {
            $customer = $matches[0] ?? null;
        } else {
            if ($customerEmail === '' || $customerMobile === '') {
                booking_api_response(['error' => 'email and mobile are required'], 422);
            }

            $customerId = create_customer($resolvedCustomerName, $customerEmail ?: null, $customerMobile ?: null, $customerDob !== '' ? $customerDob : null, $customerGender !== '' ? $customerGender : null);
            if (!$customerId) {
                booking_api_response(['error' => 'Failed to create customer'], 500);
            }
            $customer = get_customer_by_id($customerId);
        }

        if (!$customer) {
            booking_api_response(['error' => 'Failed to load customer'], 500);
        }

        $appointment = create_appointment((int) $customer['id'], $providerId, $appointmentTypeId, $startDate->format('Y-m-d H:i:s'), 'booked', null, $notesValue);
        if (!$appointment) {
            booking_api_response(['error' => 'Failed to create appointment'], 500);
        }

        if (isset($appointment['error'])) {
            $code = ($appointment['error'] === 'overlap' || $appointment['error'] === 'break_overlap') ? 409 : 422;
            booking_api_response([
                'error' => $appointment['error'] === 'break_overlap' ? 'Booking overlaps a standard break' : 'Booking overlaps an existing appointment',
                'conflict' => $appointment['conflict'] ?? null,
            ], $code);
        }

        $emailSent = booking_send_confirmation_email($appointment);
        booking_api_response([
            'ok' => true,
            'pending' => false,
            'customer' => $customer,
            'appointment' => $appointment,
            'email_sent' => $emailSent,
            'message' => 'Booking created successfully',
        ]);
    }

    if ($action === 'verify' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = (string) ($data['csrf_token'] ?? '');
        if (!verify_csrf_token($token)) {
            booking_api_response(['error' => 'csrf'], 400);
        }

        $appointmentToken = trim((string) ($data['token'] ?? ''));

        if ($appointmentToken === '') {
            booking_api_response(['error' => 'token is required'], 422);
        }

        $verification = get_appointment_verification_by_code($appointmentToken);
        if (!$verification) {
            booking_api_response(['error' => 'request not found'], 404);
        }

        $appointmentRow = get_appointment_by_id((int) ($verification['appointment_id'] ?? 0));
        if (!$appointmentRow) {
            booking_api_response(['error' => 'request not found'], 404);
        }

        $customerOption = trim((string) ($data['customer_option'] ?? 'current'));
        $inputFirstName = trim((string) ($data['first_name'] ?? ''));
        $inputLastName  = trim((string) ($data['last_name'] ?? ''));

        $originalCustomerId = (int) ($appointmentRow['customer_id'] ?? 0);
        $originalCustomer   = get_customer_by_id($originalCustomerId);
        if (!$originalCustomer) {
            booking_api_response(['error' => 'Original booking customer details could not be found'], 422);
        }

        $customerIdToAssign = $originalCustomerId;

        if ($customerOption === 'new') {
            if ($inputFirstName === '') {
                booking_api_response(['error' => 'First name is required.'], 422);
            }
            if ($inputLastName === '') {
                booking_api_response(['error' => 'Last name is required.'], 422);
            }

            $resolvedName = trim($inputFirstName . ' ' . $inputLastName);

            $customerEmail  = !empty($originalCustomer['email']) ? (string) $originalCustomer['email'] : null;
            $customerMobile = !empty($originalCustomer['mobile']) ? (string) $originalCustomer['mobile'] : null;
            $customerDob    = !empty($originalCustomer['date_of_birth']) ? (string) $originalCustomer['date_of_birth'] : null;
            $customerGender = !empty($originalCustomer['gender']) ? (string) $originalCustomer['gender'] : null;

            $newCustomerId = create_customer(
                $resolvedName,
                $customerEmail,
                $customerMobile,
                $customerDob,
                $customerGender
            );

            if (!$newCustomerId) {
                booking_api_response(['error' => 'Failed to build an isolated customer profile entity'], 500);
            }

            $customerIdToAssign = (int) $newCustomerId;

        } else if (is_numeric($customerOption)) {
            $customerIdToAssign = (int) $customerOption;
        }

        if ((string) ($appointmentRow['status'] ?? '') !== 'unconfirmed') {
            $customer = get_customer_by_id($customerIdToAssign);
            booking_api_response([
                'ok' => true,
                'status' => (string) ($appointmentRow['status'] ?? 'booked'),
                'appointment' => $appointmentRow,
                'customer' => $customer,
            ]);
        }

        $provider = get_user_by_id((int) ($appointmentRow['provider_id'] ?? 0));
        $appointmentType = get_appointment_type_by_id((int) ($appointmentRow['appointment_type_id'] ?? 0));
        if (!$provider || !$appointmentType) {
            booking_api_response(['error' => 'booking appointment data is invalid'], 422);
        }

        $appointment = update_appointment(
            (int) $appointmentRow['id'],
            $customerIdToAssign, 
            (int) $appointmentRow['provider_id'],
            (int) $appointmentRow['appointment_type_id'],
            (string) $appointmentRow['start_time'],
            (string) $appointmentRow['end_time'],
            'booked',
            !empty($appointmentRow['notes']) ? (string) $appointmentRow['notes'] : null
        );
        
        if (!$appointment) {
            booking_api_response(['error' => 'Failed to finalize appointment'], 500);
        }

        delete_verification_token((string) ($verification['code'] ?? ''));

        $emailSent = booking_send_confirmation_email($appointment);
        booking_api_response([
            'ok' => true,
            'status' => 'completed',
            'customer' => get_customer_by_id((int) $appointment['customer_id']),
            'appointment' => $appointment,
            'email_sent' => $emailSent,
            'message' => 'Appointment verified and booked successfully',
        ]);
    }

    booking_api_response(['error' => 'unknown action'], 400);
} catch (Throwable $e) {
    booking_api_response(['error' => $e->getMessage()], 500);
}
