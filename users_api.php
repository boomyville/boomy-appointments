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
$role = $user['role'] ?? '';

// Only admins can access this
if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'permission denied']);
    exit;
}

try {
    $action = $_GET['action'] ?? 'search';

    if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $limit = min((int) ($_GET['limit'] ?? 50), 200);

        $users = search_users($query, $limit, $offset);
        $total = count_users($query);

        echo json_encode([
            'users' => $users,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
