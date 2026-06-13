<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json; charset=utf-8');

try {
    init_session();
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
        exit;
    }

    $db = get_db();
    $action = $_GET['action'] ?? '';

    // GET /daily_notes_api.php?action=get
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get') {
        $date = $_GET['date'] ?? '';
        $providerId = isset($_GET['provider_id']) ? (int)$_GET['provider_id'] : null;
        if (!$date) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Missing date']); exit; }

        $stmt = $db->prepare("SELECT id, title, message FROM daily_notes WHERE note_date = ? AND provider_id <=> ?");
        $stmt->execute([$date, $providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'note' => $row ?: null]);

    // NEW: GET /daily_notes_api.php?action=range&start=YYYY-MM-DD&end=YYYY-MM-DD
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'range') {
        $start = $_GET['start'] ?? '';
        $end = $_GET['end'] ?? '';
        $providerId = isset($_GET['provider_id']) && $_GET['provider_id'] !== '' ? (int)$_GET['provider_id'] : null;

        if (!$start || !$end) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Missing date range parameters']);
            exit;
        }

        // Query both notes specific to this provider and global/universal notes (provider_id IS NULL)
        if ($providerId !== null) {
            $stmt = $db->prepare("
                SELECT id, note_date, title, message, provider_id 
                FROM daily_notes 
                WHERE note_date >= ? AND note_date <= ? 
                  AND (provider_id = ? OR provider_id IS NULL)
                ORDER BY note_date ASC, provider_id DESC
            ");
            $stmt->execute([$start, $end, $providerId]);
        } else {
            $stmt = $db->prepare("
                SELECT id, note_date, title, message, provider_id 
                FROM daily_notes 
                WHERE note_date >= ? AND note_date <= ?
                ORDER BY note_date ASC
            ");
            $stmt->execute([$start, $end]);
        }

        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'notes' => $notes]);
        exit;

    // POST /daily_notes_api.php?action=save
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!verify_csrf_token($data['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF failed']); exit; }

        $date = trim((string)($data['date'] ?? ''));
        $providerId = isset($data['provider_id']) && $data['provider_id'] !== '' ? (int)$data['provider_id'] : null;
        $title = mb_substr(trim((string)($data['title'] ?? '')), 0, 40);
        $message = trim((string)($data['message'] ?? '')) ?: null;

        if (!$date) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Missing date']); exit; }

        $stmt = $db->prepare("
            INSERT INTO daily_notes (note_date, provider_id, title, message)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), message = VALUES(message), updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$date, $providerId, $title, $message]);
        echo json_encode(['ok' => true]);

    // POST /daily_notes_api.php?action=delete
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!verify_csrf_token($data['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF failed']); exit; }

        $date = trim((string)($data['date'] ?? ''));
        $providerId = isset($data['provider_id']) && $data['provider_id'] !== '' ? (int)$data['provider_id'] : null;

        if (!$date) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Missing date']); exit; }

        $stmt = $db->prepare("DELETE FROM daily_notes WHERE note_date = ? AND provider_id <=> ?");
        $stmt->execute([$date, $providerId]);
        echo json_encode(['ok' => true]);

    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}