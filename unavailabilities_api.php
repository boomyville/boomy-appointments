<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// Strict application header setup to delivery clean JSON error logs
header('Content-Type: application/json; charset=utf-8');

try {
    init_session();
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication session invalid. Please log back in.']);
        exit;
    }

    $db = get_db();
    $action = $_GET['action'] ?? '';

    // Route actions
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
        handle_list_action($db);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
        handle_create_action($db);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
        handle_delete_action($db);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action track request strategy routing mapping.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Critical backend api engine fault: ' . $e->getMessage()]);
}

/**
 * Lists unavailabilities filtered by provider_id
 */
function handle_list_action(PDO $db): void
{
    $providerId = isset($_GET['provider_id']) ? (int) $_GET['provider_id'] : 0;
    if ($providerId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing valid provider staff profile parameter.']);
        return;
    }

    $stmt = $db->prepare("
        SELECT id, provider_id, type, start_datetime, end_datetime, day_of_week, start_time, end_time, rolling_start_date, rolling_end_date, reason 
        FROM unavailabilities 
        WHERE provider_id = ? 
        ORDER BY type DESC, start_datetime ASC, day_of_week ASC, start_time ASC
    ");
    $stmt->execute([$providerId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'unavailabilities' => $results]);
}

/**
 * Creates single or recurring unavailabilities with transactional sanitization loops
 */
function handle_create_action(PDO $db): void
{
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: [];

    if (!verify_csrf_token($data['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Security token verification failed. Reload screen workspace session context.']);
        return;
    }

    $providerId = isset($data['provider_id']) ? (int) $data['provider_id'] : 0;
    $type = trim((string) ($data['type'] ?? 'single'));
    $reason = trim((string) ($data['reason'] ?? ''));

    if ($providerId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please select a valid staff target profile track layout.']);
        return;
    }

    if (!in_array($type, ['single', 'recurring'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid configuration rule tracking layout selected.']);
        return;
    }

    if ($type === 'single') {
        $startDt = trim((string) ($data['start_datetime'] ?? ''));
        $endDt = trim((string) ($data['end_datetime'] ?? ''));

        if (!$startDt || !$endDt) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Both start and end dates with time slots are strictly mandatory.']);
            return;
        }

        // Translate timestamps cleanly into local operational constraints
        $startParsed = date_create($startDt);
        $endParsed = date_create($endDt);

        if (!$startParsed || !$endParsed || $endParsed <= $startParsed) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'End timestamp marker bounds must fall chronologically after start timestamps.']);
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO unavailabilities (provider_id, type, start_datetime, end_datetime, reason) 
            VALUES (?, 'single', ?, ?, ?)
        ");
        $stmt->execute([
            $providerId, 
            $startParsed->format('Y-m-d H:i:s'), 
            $endParsed->format('Y-m-d H:i:s'), 
            $reason !== '' ? $reason : null
        ]);

    } else {
        // Evaluate rolling validation patterns
        $dayOfWeek = isset($data['day_of_week']) ? (int) $data['day_of_week'] : 0;
        $startTime = trim((string) ($data['start_time'] ?? ''));
        $endTime = trim((string) ($data['end_time'] ?? ''));
        $rollingStart = trim((string) ($data['rolling_start_date'] ?? ''));
        $rollingEnd = trim((string) ($data['rolling_end_date'] ?? ''));

        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid day of week parameter choice logged.']);
            return;
        }

        if (!$startTime || !$endTime || !$rollingStart) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Daily time ranges and active constraint start limits are required fields.']);
            return;
        }

        if (time_to_seconds_local($endTime) <= time_to_seconds_local($startTime)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Daily rule ending time matrix must fall after starting time metrics context windows.']);
            return;
        }

        $rollingStartParsed = date_create($rollingStart);
        $rollingEndFormat = null;

        if ($rollingEnd !== '') {
            $rollingEndParsed = date_create($rollingEnd);
            if (!$rollingEndParsed || $rollingEndParsed < $rollingStartParsed) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Rolling lifespan end calendar target cannot conclude before launch dates.']);
                return;
            }
            $rollingEndFormat = $rollingEndParsed->format('Y-m-d');
        }

        $stmt = $db->prepare("
            INSERT INTO unavailabilities (provider_id, type, day_of_week, start_time, end_time, rolling_start_date, rolling_end_date, reason) 
            VALUES (?, 'recurring', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $providerId, 
            $dayOfWeek, 
            $startTime . ':00', 
            $endTime . ':00', 
            $rollingStartParsed->format('Y-m-d'), 
            $rollingEndFormat, 
            $reason !== '' ? $reason : null
        ]);
    }

    echo json_encode(['ok' => true]);
}

/**
 * Drops targeted lockout rows safely
 */
function handle_delete_action(PDO $db): void
{
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: [];

    if (!verify_csrf_token($data['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Security token verification failed. Reload screen workspace session context.']);
        return;
    }

    $id = isset($data['id']) ? (int) $data['id'] : 0;
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing valid row layout constraint target identity tracking parameter.']);
        return;
    }

    $stmt = $db->prepare("DELETE FROM unavailabilities WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['ok' => true]);
}

/**
 * Simple inner conversion tool for safe integer comparisons
 */
function time_to_seconds_local(string $timeValue): int
{
    $parts = explode(':', $timeValue);
    return ((int)($parts[0] ?? 0) * 3600) + ((int)($parts[1] ?? 0) * 60);
}