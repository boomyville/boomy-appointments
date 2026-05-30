<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
init_session();
header('Content-Type: application/json; charset=utf-8');

// Only allow logged-in users to fetch appointments
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'] ?? '';
$isStaff = $role === 'admin' || $role === 'provider';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'method not allowed']);
        exit;
    }

    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    if (!$start || !$end) {
        // FullCalendar will often request with ISO8601 dates; we accept broader input.
        // If absent, return an empty array rather than an error.
        echo json_encode([]);
        exit;
    }

    $db = get_db();
    $timezone = new DateTimeZone(get_app_timezone_name());
    $rangeStartDate = (new DateTimeImmutable((string) $start))->setTimezone($timezone);
    $rangeEndDate = (new DateTimeImmutable((string) $end))->setTimezone($timezone);
    $rangeStart = $rangeStartDate->format('Y-m-d H:i:s');
    $rangeEnd = $rangeEndDate->format('Y-m-d H:i:s');

    // Updated SELECT query to pull contact fields, notes, and prevent index errors
    $sql = "SELECT a.id, a.customer_id, a.provider_id, a.appointment_type_id, a.start_time, a.end_time, a.status, a.notes,
                   c.full_name AS customer_name, c.email AS customer_email, c.mobile AS customer_mobile, t.name AS type_name, t.color_hex
            FROM appointments a
            LEFT JOIN customers c ON c.id = a.customer_id
            LEFT JOIN appointment_types t ON t.id = a.appointment_type_id
            WHERE a.start_time < ? AND a.end_time > ?";

    $params = [$rangeEnd, $rangeStart];

    if ($isStaff && $role === 'provider') {
        $sql .= ' AND a.provider_id = ?';
        $params[] = (int)$user['id'];
    } elseif (isset($_GET['provider_id']) && is_numeric($_GET['provider_id']) && $role === 'admin') {
        $sql .= ' AND a.provider_id = ?';
        $params[] = (int)$_GET['provider_id'];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    foreach ($rows as $r) {
        $title = $r['customer_name'] ? $r['customer_name'] : 'Appt #' . $r['id'];
        $color = $r['color_hex'] ?: '#3498db';

        // CRITICAL FIX: Convert "YYYY-MM-DD HH:MM:SS" into standard ISO8601 "YYYY-MM-DDTHH:MM:SSZ"
        $isoStart = str_replace(' ', 'T', $r['start_time']) . 'Z';
        $isoEnd = str_replace(' ', 'T', $r['end_time']) . 'Z';

        $events[] = [
            'id' => (int)$r['id'],
            'title' => $title,
            'start' => $isoStart, 
            'end' => $isoEnd,     
            'color' => $color,
            'extendedProps' => [
                'customer_name' => $r['customer_name'] ?? null,
                'status' => $r['status'],
                'provider_id' => (int)$r['provider_id'],
                'appointment_type' => $r['type_name'] ?? null,
                'appointment_type_id' => (int)$r['appointment_type_id'],
                'customer_id' => isset($r['customer_id']) ? (int)$r['customer_id'] : null,
                'customer_email' => $r['customer_email'] ?? null,   
                'customer_mobile' => $r['customer_mobile'] ?? null, 
                'end_time' => $isoEnd,
                'notes' => $r['notes'] ?? null,                     
            ]
        ];
    }

    $breakEvents = get_standard_break_events($rangeStartDate, $rangeEndDate);
    $events = array_merge($events, $breakEvents);

    echo json_encode($events);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
