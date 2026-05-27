<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$user = $_SESSION['user'];
$role = (string) ($user['role'] ?? '');

try {
    $action = $_GET['action'] ?? 'create';

    if ($action !== 'create' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
        exit;
    }

    if (!in_array($role, ['admin', 'provider'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $data['csrf_token'] ?? null;
    if (!verify_csrf_token($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'csrf']);
        exit;
    }

    $selectedCustomerId = (int) ($data['selected_customer_id'] ?? 0);
    $customerName = trim((string) ($data['customer_name'] ?? ''));
    $customerEmail = trim((string) ($data['customer_email'] ?? ''));
    $customerMobile = trim((string) ($data['customer_mobile'] ?? ''));
    $dateOfBirth = trim((string) ($data['date_of_birth'] ?? ''));
    $providerId = (int) ($data['provider_id'] ?? 0);
    $appointmentTypeId = (int) ($data['appointment_type_id'] ?? 0);
    $startTime = trim((string) ($data['start_time'] ?? ''));

    if ($providerId < 1) {
        http_response_code(422);
        echo json_encode(['error' => 'provider is required']);
        exit;
    }

    if ($appointmentTypeId < 1) {
        http_response_code(422);
        echo json_encode(['error' => 'appointment type is required']);
        exit;
    }

    if ($startTime === '') {
        http_response_code(422);
        echo json_encode(['error' => 'start time is required']);
        exit;
    }

    $appointmentType = get_appointment_type_by_id($appointmentTypeId);
    if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
        http_response_code(422);
        echo json_encode(['error' => 'appointment type not found']);
        exit;
    }

    $timezone = new DateTimeZone(get_app_timezone_name());
    $startDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startTime, $timezone);
    if (!$startDate) {
        $startDate = new DateTimeImmutable($startTime, $timezone);
    }
    $durationMinutes = max(1, (int) ($appointmentType['duration_minutes'] ?? 30));
    $endDate = $startDate->modify('+' . $durationMinutes . ' minutes');
    $overlappingAppointment = find_overlapping_appointment($providerId, $startDate, $endDate);
    if ($overlappingAppointment) {
        $conflictName = trim((string) ($overlappingAppointment['customer_name'] ?? 'another booking'));
        http_response_code(409);
        echo json_encode([
            'error' => 'Booking overlaps an existing appointment' . ($conflictName !== '' ? ' for ' . $conflictName : ''),
            'conflict' => $overlappingAppointment,
        ]);
        exit;
    }

    $customer = null;
    if ($selectedCustomerId > 0) {
        $customer = get_customer_by_id($selectedCustomerId);
        if (!$customer) {
            http_response_code(422);
            echo json_encode(['error' => 'customer not found']);
            exit;
        }
    } else {
        if ($customerName === '') {
            http_response_code(422);
            echo json_encode(['error' => 'customer name is required']);
            exit;
        }

        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['error' => 'invalid customer email']);
            exit;
        }

        if ($dateOfBirth !== '') {
            $dob = DateTimeImmutable::createFromFormat('Y-m-d', $dateOfBirth);
            $dobErrors = DateTimeImmutable::getLastErrors();
            if (!$dob || ($dobErrors['warning_count'] ?? 0) > 0 || ($dobErrors['error_count'] ?? 0) > 0) {
                http_response_code(422);
                echo json_encode(['error' => 'invalid date of birth']);
                exit;
            }
        }

        $createdCustomerId = create_customer($customerName, $customerEmail ?: null, $customerMobile ?: null, $dateOfBirth ?: null);
        if (!$createdCustomerId) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create customer']);
            exit;
        }

        $customer = get_customer_by_id($createdCustomerId);
        $selectedCustomerId = $createdCustomerId;
    }

    if (!$customer) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load customer']);
        exit;
    }

    $appointment = create_appointment($selectedCustomerId, $providerId, $appointmentTypeId, $startTime, 'booked');
    if (!$appointment) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create appointment']);
        exit;
    }

    if (isset($appointment['error']) && $appointment['error'] === 'overlap') {
        $conflictName = trim((string) ($appointment['conflict']['customer_name'] ?? 'another booking'));
        http_response_code(409);
        echo json_encode([
            'error' => 'Booking overlaps an existing appointment' . ($conflictName !== '' ? ' for ' . $conflictName : ''),
            'conflict' => $appointment['conflict'],
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'customer' => $customer,
        'appointment' => $appointment,
        'message' => 'Booking created successfully'
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
