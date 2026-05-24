<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

init_session();
header('Content-Type: application/json; charset=utf-8');

// Read input (JSON preferred, fallback to $_POST)
function input_data(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
    }
    return $_POST ?? [];
}

$action = $_GET['action'] ?? 'status';

try {
    if ($action === 'status') {
        if (count_admin_users() === 0) {
            echo json_encode(['setup_required' => true]);
            exit;
        }

        if (!empty($_SESSION['user'])) {
            echo json_encode(['authenticated' => true, 'user' => $_SESSION['user']]);
            exit;
        }

        echo json_encode(['authenticated' => false]);
        exit;
    }

    if ($action === 'register') {
        if (!can_register_admin()) {
            http_response_code(403);
            echo json_encode(['error' => 'registrations blocked']);
            exit;
        }

        $data = input_data();
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $first_name = trim((string)($data['first_name'] ?? ''));
        $last_name = trim((string)($data['last_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $full_name = trim(($first_name ? $first_name : '') . ' ' . ($last_name ? $last_name : ''));
        if ($full_name === '' || $full_name === ' ') $full_name = $email;
        $token = $data['csrf_token'] ?? null;

        $emailValidation = validate_email($email);
        if ($emailValidation !== true) {
            http_response_code(422);
            echo json_encode(['error' => $emailValidation]);
            exit;
        }

        $passwordValidation = validate_password($password);
        if ($passwordValidation !== true) {
            http_response_code(422);
            echo json_encode(['error' => $passwordValidation]);
            exit;
        }

        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        $id = create_user($email, $password, $full_name, 'admin', $first_name ?: null, $last_name ?: null, $phone ?: null);
        $user = get_user_by_email($email);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        echo json_encode(['ok' => true, 'redirect' => 'admin.php']);
        exit;
    }

    if ($action === 'register_first_admin') {
        if (!can_register_admin()) {
            http_response_code(403);
            echo json_encode(['error' => 'registration disabled']);
            exit;
        }

        $data = input_data();
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $first_name = trim((string)($data['first_name'] ?? ''));
        $last_name = trim((string)($data['last_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $full_name = trim(($first_name ? $first_name : '') . ' ' . ($last_name ? $last_name : ''));
        if ($full_name === '' || $full_name === ' ') $full_name = $email;
        $token = $data['csrf_token'] ?? null;

        $emailValidation = validate_email($email);
        if ($emailValidation !== true) {
            http_response_code(422);
            echo json_encode(['error' => $emailValidation]);
            exit;
        }

        $passwordValidation = validate_password($password);
        if ($passwordValidation !== true) {
            http_response_code(422);
            echo json_encode(['error' => $passwordValidation]);
            exit;
        }

        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        $id = create_user($email, $password, $full_name, 'admin', $first_name ?: null, $last_name ?: null, $phone ?: null);
        $user = get_user_by_email($email);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        echo json_encode(['ok' => true, 'redirect' => 'admin.php']);
        exit;
    }

    if ($action === 'login') {
        $data = input_data();
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $token = $data['csrf_token'] ?? null;

        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        $emailValidation = validate_email($email);
        if ($emailValidation !== true) {
            http_response_code(422);
            echo json_encode(['error' => $emailValidation]);
            exit;
        }

        $user = verify_user_credentials($email, $password);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            exit;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'role' => $user['role']
        ];
        echo json_encode(['ok' => true, 'redirect' => 'admin.php']);
        exit;
    }

    if ($action === 'logout') {
        $data = input_data();
        $token = $data['csrf_token'] ?? null;
        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        // Clear session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        echo json_encode(['ok' => true, 'redirect' => 'login.php']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
