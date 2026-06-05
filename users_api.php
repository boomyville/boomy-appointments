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
if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'permission denied']);
    exit;
}
try {
    $action = $_GET['action'] ?? 'search';

    // ── SEARCH ──────────────────────────────────────────────────────────────
    if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $query  = trim((string) ($_GET['q'] ?? ''));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $limit  = min((int) ($_GET['limit'] ?? 50), 200);
        echo json_encode([
            'users'  => search_users($query, $limit, $offset),
            'total'  => count_users($query),
            'offset' => $offset,
            'limit'  => $limit,
        ]);
        exit;
    }

    // ── CREATE / UPDATE ──────────────────────────────────────────────────────
    if (in_array($action, ['create', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $first_name = trim((string) ($body['first_name'] ?? ''));
        $last_name  = trim((string) ($body['last_name']  ?? ''));
        $email      = trim((string) ($body['email']      ?? ''));
        $phone      = trim((string) ($body['phone']      ?? ''));
        $role_new   = trim((string) ($body['role']       ?? 'provider'));
        $is_active  = (int) ($body['is_active'] ?? 1);
        $bookable   = (int) ($body['bookable']  ?? 1);

        // Validate
        $errors = [];
        if ($first_name === '') $errors['first_name'] = 'First name is required.';
        if ($last_name  === '') $errors['last_name']  = 'Last name is required.';
        if ($email      === '') $errors['email']      = 'Email is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email address.';
        if ($phone === '') $errors['phone'] = 'Phone is required.';
        if (!in_array($role_new, ['admin', 'provider'], true)) $errors['role'] = 'Invalid role.';

        if ($errors) {
            http_response_code(422);
            echo json_encode(['errors' => $errors]);
            exit;
        }

        $full_name = trim($first_name . ' ' . $last_name);
        $db = get_db();

        // ── UPDATE ────────────────────────────────────────────────────────────
        if ($action === 'update') {
            $user_id = (int) ($body['user_id'] ?? 0);
            if ($user_id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid user ID.']);
                exit;
            }

            $existing = get_user_by_id($user_id);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found.']);
                exit;
            }

            // Check email uniqueness (excluding this user)
            $emailOwner = get_user_by_email($email);
            if ($emailOwner && (int) $emailOwner['id'] !== $user_id) {
                http_response_code(422);
                echo json_encode(['errors' => ['email' => 'Email is already in use by another user.']]);
                exit;
            }

            $stmt = $db->prepare('
                UPDATE users
                SET first_name = ?, last_name = ?, full_name = ?, email = ?,
                    phone = ?, role = ?, is_active = ?, bookable = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $first_name, $last_name, $full_name, $email,
                $phone, $role_new, $is_active, $bookable, $user_id,
            ]);

            echo json_encode(['message' => 'User updated successfully.']);
            exit;
        }

        // ── CREATE ────────────────────────────────────────────────────────────
        if (!email_is_configured()) {
            http_response_code(400);
            echo json_encode(['error' => 'Email is not configured. Cannot create user.']);
            exit;
        }

        // Check email uniqueness
        if (get_user_by_email($email)) {
            http_response_code(422);
            echo json_encode(['errors' => ['email' => 'A user with this email already exists.']]);
            exit;
        }

        // Create with a random placeholder password; user sets their own via the reset link
        $placeholder_password = bin2hex(random_bytes(16));
        $new_user_id = create_user_with_active(
            $email,
            $placeholder_password,
            $full_name,
            $role_new,
            $is_active,
            $first_name,
            $last_name,
            $phone
        );

        if (!$new_user_id) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create user.']);
            exit;
        }

        // Set bookable flag (not in create_user_with_active signature)
        $db->prepare('UPDATE users SET bookable = ? WHERE id = ?')->execute([$bookable, $new_user_id]);

        // Generate password reset link so the new user can set their own password
        $reset_code = create_verification_token($new_user_id, 'password_reset', 1440); // 24h
        send_password_reset_email($email, $reset_code);
        $reset_link = build_app_url('reset_password.php', ['code' => $reset_code]);

        echo json_encode([
            'message'    => 'User created successfully. A password reset link has been sent.',
            'reset_link' => $reset_link,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}