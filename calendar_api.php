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
        echo json_encode([]);
        exit;
    }

    $db = get_db();
    $timezone = new DateTimeZone(get_app_timezone_name());
    $rangeStartDate = (new DateTimeImmutable((string) $start))->setTimezone($timezone);
    $rangeEndDate = (new DateTimeImmutable((string) $end))->setTimezone($timezone);
    $rangeStart = $rangeStartDate->format('Y-m-d H:i:s');
    $rangeEnd = $rangeEndDate->format('Y-m-d H:i:s');

    // Build base SELECT query for real appointments
    $sql = "SELECT a.id, a.customer_id, a.provider_id, a.appointment_type_id, a.start_time, a.end_time, a.status, a.notes,
                    c.full_name AS customer_name, c.email AS customer_email, c.mobile AS customer_mobile, t.name AS type_name, t.color_hex
            FROM appointments a
            LEFT JOIN customers c ON c.id = a.customer_id
            LEFT JOIN appointment_types t ON t.id = a.appointment_type_id
            WHERE a.start_time < ? AND a.end_time > ?";

    $params = [$rangeEnd, $rangeStart];

    // Determine target provider IDs context safely
    $targetProviderIds = [];

    if ($isStaff && $role === 'provider') {
        $sql .= ' AND a.provider_id = ?';
        $params[] = (int)$user['id'];
        $targetProviderIds[] = (int)$user['id'];
    } elseif (!empty($_GET['provider_id'])) {
        // Explode handles single integers ("2") and dynamic split combinations ("2,4") smoothly
        $rawIds = explode(',', (string)$_GET['provider_id']);
        $cleanIds = array_filter(array_map('intval', $rawIds));
        
        if (!empty($cleanIds)) {
            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $sql .= " AND a.provider_id IN ($placeholders)";
            foreach ($cleanIds as $id) {
                $params[] = $id;
                $targetProviderIds[] = $id;
            }
        }
    }
    // If no specific providers targeted, load all active providers for unavailability events
    if (empty($targetProviderIds)) {
        try {
            $allStmt = $db->query("SELECT id FROM users WHERE role IN ('admin','provider') AND is_active = 1");
            $targetProviderIds = array_map('intval', $allStmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            $targetProviderIds = [];
        }
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    foreach ($rows as $r) {
        $title = $r['customer_name'] ? $r['customer_name'] : 'Appt #' . $r['id'];
        $color = $r['color_hex'] ?: '#3498db';

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

    // Append global background breaks
    $breakEvents = get_standard_break_events($rangeStartDate, $rangeEndDate);
    $events = array_merge($events, $breakEvents);

    // =======================================================================
    // NEW BLOCK: EXTRACT AND INTEGRATE CUSTOM PROVIDER LOCKOUT CONSTRAINTS
    // REPLACED INTEGRATION BLOCK: FIXING PROVIDER ID MATCHING
    // =======================================================================
    if (!empty($targetProviderIds)) {
        // Convert array to a string of placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($targetProviderIds), '?'));

        // 1. Fetch Single Unavailabilities
        $stmt = $db->prepare("
            SELECT id, provider_id, start_datetime, end_datetime, reason 
            FROM unavailabilities 
            WHERE type = 'single' AND provider_id IN ($placeholders)
            AND start_datetime < ? AND end_datetime > ?
        ");
        $paramsSingle = array_merge($targetProviderIds, [$rangeEnd, $rangeStart]);
        $stmt->execute($paramsSingle);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $events[] = [
                'id' => 'unavail-single-' . $row['id'],
                'title' => $row['reason'] ?: 'Unavailable',
                'start' => str_replace(' ', 'T', $row['start_datetime']) . 'Z',
                'end' => str_replace(' ', 'T', $row['end_datetime']) . 'Z',
                'display' => 'background',
                'classNames' => ['unavail-event'], 
                'backgroundColor' => 'transparent', 
                'extendedProps' => ['provider_id' => (int)$row['provider_id'], 'type' => 'unavailability', 'unavailability_type' => 'single', 'unavailability_id' => (int)$row['id']]
            ];
        }

        // 2. Fetch Recurring Unavailabilities
        // REMOVED: (day_of_week = ? OR day_of_week = 0) constraint to prevent parameter array offset bugs
        $stmt = $db->prepare("
            SELECT id, provider_id, day_of_week, start_time, end_time, reason, rolling_start_date, rolling_end_date 
            FROM unavailabilities 
            WHERE type = 'recurring' AND provider_id IN ($placeholders)
            AND rolling_start_date <= ? 
            AND (rolling_end_date IS NULL OR rolling_end_date >= ?)
        ");
        
        // Match parameters cleanly to the expected placeholders: Provider IDs, Range End Date, Range Start Date
        $paramsRecurring = array_merge($targetProviderIds, [
            $rangeEndDate->format('Y-m-d'), 
            $rangeStartDate->format('Y-m-d')
        ]);
        $stmt->execute($paramsRecurring);
        $recurringRules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recurringRules)) {
            $cursor = $rangeStartDate->setTime(0, 0, 0);
            while ($cursor <= $rangeEndDate) {
                // PHP format 'N' treats Monday as 1 and Sunday as 7.
                // Note: Ensure your database data model aligns with this or alter tracking values accordingly.
                $currentDayOfWeek = (int) $cursor->format('N');
                $currentDateStr = $cursor->format('Y-m-d');
                
                foreach ($recurringRules as $rule) {
                    $ruleDay = (int)$rule['day_of_week'];
                    
                    // Allow ruleDay 0 (Every day) to pass through, or explicitly verify match
                    if ($ruleDay !== 0 && $ruleDay !== $currentDayOfWeek) {
                        continue; 
                    }
                    if ($currentDateStr < $rule['rolling_start_date']) continue;
                    if ($rule['rolling_end_date'] && $currentDateStr > $rule['rolling_end_date']) continue;

                    $events[] = [
                        'id' => 'unavail-recurring-' . $rule['id'] . '-' . $currentDateStr,
                        'title' => $rule['reason'] ?: 'Unavailable',
                        'start' => $currentDateStr . 'T' . $rule['start_time'] . 'Z',
                        'end' => $currentDateStr . 'T' . $rule['end_time'] . 'Z',
                        'display' => 'background',
                        'classNames' => ['unavail-event'], 
                        'backgroundColor' => 'transparent', 
                        'extendedProps' => ['provider_id' => (int)$rule['provider_id'], 'type' => 'unavailability', 'unavailability_id' => (int)$rule['id']]
                    ];
                }
                $cursor = $cursor->modify('+1 day');
            }
        }
    }
    // =======================================================================

    echo json_encode($events);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}