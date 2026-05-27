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

    $sql = "SELECT a.id, a.customer_id, a.provider_id, a.appointment_type_id, a.start_time, a.end_time, a.status,
                   c.full_name AS customer_name, t.name AS type_name, t.color_hex
            FROM appointments a
            LEFT JOIN customers c ON c.id = a.customer_id
            LEFT JOIN appointment_types t ON t.id = a.appointment_type_id
            WHERE a.start_time BETWEEN ? AND ?";

    $params = [$start, $end];

    // If requester is a provider, limit to their provider_id unless admin requests a specific provider
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
        $title = 'Booked';
        if ($isStaff) {
            $title = $r['customer_name'] ? $r['customer_name'] : 'Appt #' . $r['id'];
        }
        $color = $r['color_hex'] ?: '#3498db';

        $events[] = [
            'id' => (int)$r['id'],
            'title' => $title,
            'start' => $r['start_time'],
            'end' => $r['end_time'],
            'color' => $color,
            'extendedProps' => [
                'status' => $r['status'],
                'provider_id' => (int)$r['provider_id'],
                'appointment_type' => $r['type_name'] ?? null
            ]
        ];
    }

    echo json_encode($events);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
