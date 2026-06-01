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

function normalize_user_role_value(?string $value): ?string
{
    $role = strtolower(trim((string) $value));
    return in_array($role, ['admin', 'provider'], true) ? $role : null;
}

function normalize_user_status_value($value): ?int
{
    if (is_int($value)) {
        return $value === 1 ? 1 : 0;
    }

    $status = strtolower(trim((string) $value));
    if ($status === '') {
        return null;
    }

    if (in_array($status, ['1', 'active', 'enabled', 'true', 'yes'], true)) {
        return 1;
    }

    if (in_array($status, ['0', 'disabled', 'inactive', 'false', 'no'], true)) {
        return 0;
    }

    return null;
}

function normalize_user_bookable_value($value): ?int
{
    return normalize_user_status_value($value);
}

function build_user_payload(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'email' => (string) ($row['email'] ?? ''),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'role' => (string) ($row['role'] ?? ''),
        'is_active' => (int) ($row['is_active'] ?? 0),
        'bookable' => (int) ($row['bookable'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function read_user_request_data(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function save_user_record(array $data, bool $isUpdate = false): array
{
    $userId = (int) ($data['user_id'] ?? 0);
    $email = trim((string) ($data['email'] ?? ''));
    $firstName = trim((string) ($data['first_name'] ?? ''));
    $lastName = trim((string) ($data['last_name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $role = normalize_user_role_value($data['role'] ?? null);
    $isActive = normalize_user_status_value($data['is_active'] ?? null);
    $bookable = normalize_user_bookable_value($data['bookable'] ?? null);

    $errors = [];

    if ($email === '') {
        $errors['email'] = 'Email is required';
    } elseif (validate_email($email) !== true) {
        $errors['email'] = 'Please enter a valid email address';
    }

    if ($firstName === '') {
        $errors['first_name'] = 'First name is required';
    }

    if ($lastName === '') {
        $errors['last_name'] = 'Last name is required';
    }

    if ($phone === '') {
        $errors['phone'] = 'Phone is required';
    }

    if ($role === null) {
        $errors['role'] = 'Role must be admin or provider';
    }

    if ($isActive === null) {
        $errors['is_active'] = 'Status must be active or disabled';
    }

    if ($bookable === null) {
        $errors['bookable'] = 'Bookable must be bookable or not bookable';
    }

    if (!empty($errors)) {
        return [
            'ok' => false,
            'status' => 422,
            'error' => 'validation_error',
            'errors' => $errors,
        ];
    }

    $db = get_db();
    $fullName = trim($firstName . ' ' . $lastName);

    if ($isUpdate) {
        if ($userId < 1) {
            return ['ok' => false, 'status' => 400, 'error' => 'user_id is required'];
        }

        $existingUser = get_user_by_id($userId);
        if (!$existingUser) {
            return ['ok' => false, 'status' => 404, 'error' => 'user not found'];
        }

        $duplicateUser = get_user_by_email($email);
        if ($duplicateUser && (int) $duplicateUser['id'] !== $userId) {
            return ['ok' => false, 'status' => 409, 'error' => 'Email already exists'];
        }

        $stmt = $db->prepare('UPDATE users SET email = ?, full_name = ?, first_name = ?, last_name = ?, phone = ?, role = ?, is_active = ?, bookable = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$email, $fullName, $firstName, $lastName, $phone, $role, $isActive, $bookable, $userId]);

        $updatedUser = get_user_by_id($userId);
        if (!$updatedUser) {
            return ['ok' => false, 'status' => 500, 'error' => 'Failed to load updated user'];
        }

        return [
            'ok' => true,
            'user' => build_user_payload($updatedUser),
            'message' => 'User updated successfully',
        ];
    }

    if (get_user_by_email($email)) {
        return ['ok' => false, 'status' => 409, 'error' => 'Email already exists'];
    }

    $tempPassword = bin2hex(random_bytes(16));
    $newUserId = create_user_with_active($email, $tempPassword, $fullName, $role, $isActive, $firstName, $lastName, $phone, $bookable);
    $createdUser = get_user_by_id($newUserId);
    if (!$createdUser) {
        return ['ok' => false, 'status' => 500, 'error' => 'Failed to create user'];
    }

    $resetCode = create_verification_token($newUserId, 'password_reset');
    $resetLink = null;
    $message = 'User created successfully';

    if (email_is_configured()) {
        $emailSent = send_password_reset_email($email, $resetCode);
        if ($emailSent) {
            $message = 'User created successfully. A password reset email was sent.';
        } else {
            $message = 'User created successfully, but the password reset email could not be sent.';
            $resetLink = build_app_url('reset_password.php', ['code' => $resetCode]);
        }
    } else {
        $resetLink = build_app_url('reset_password.php', ['code' => $resetCode]);
    }

    return [
        'ok' => true,
        'user' => build_user_payload($createdUser),
        'message' => $message,
        'reset_link' => $resetLink,
    ];
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

    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id is required']);
            exit;
        }

        $userRow = get_user_by_id($id);
        if (!$userRow) {
            http_response_code(404);
            echo json_encode(['error' => 'user not found']);
            exit;
        }

        echo json_encode(['ok' => true, 'user' => build_user_payload($userRow)]);
        exit;
    }

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!email_is_configured()) {
            http_response_code(503);
            echo json_encode(['error' => 'email_not_configured', 'message' => 'New user creation is disabled until email is configured']);
            exit;
        }

        $data = read_user_request_data();
        $result = save_user_record($data, false);
        if (empty($result['ok'])) {
            http_response_code((int) ($result['status'] ?? 400));
            unset($result['status']);
            echo json_encode($result);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'user' => $result['user'] ?? null,
            'message' => $result['message'] ?? 'User created successfully',
            'reset_link' => $result['reset_link'] ?? null,
        ]);
        exit;
    }

    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = read_user_request_data();
        $result = save_user_record($data, true);
        if (empty($result['ok'])) {
            http_response_code((int) ($result['status'] ?? 400));
            unset($result['status']);
            echo json_encode($result);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'user' => $result['user'] ?? null,
            'message' => $result['message'] ?? 'User updated successfully',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
