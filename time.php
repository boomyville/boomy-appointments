<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$format = $_GET['format'] ?? null;

try {
    $formatted = get_time($format ?: null);
    $iso = get_time(DateTime::ATOM);
    echo json_encode(['ok' => true, 'iso' => $iso, 'formatted' => $formatted]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
