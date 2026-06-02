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

function save_customer_record(array $data, string $role, bool $isUpdate = false): array
{
    $firstName = trim((string) ($data['first_name'] ?? ''));
    $lastName = trim((string) ($data['last_name'] ?? ''));
    $middleName = trim((string) ($data['middle_name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $mobile = trim((string) ($data['mobile'] ?? ''));
    $dateOfBirth = trim((string) ($data['date_of_birth'] ?? ''));
    $gender = trim((string) ($data['gender'] ?? ''));
    $adminOverride = (bool) ($data['admin_override'] ?? false);
    $customerId = (int) ($data['customer_id'] ?? 0);

    $settings = get_customer_field_settings();
    $validationErrors = [];

    if ($settings['first_name']['display']) {
        $err = validate_customer_field('first_name', $firstName);
        if ($err) {
          $validationErrors['first_name'] = $err;
        }
    }

    if ($settings['last_name']['display']) {
        $err = validate_customer_field('last_name', $lastName);
        if ($err) {
          $validationErrors['last_name'] = $err;
        }
    }

    if ($settings['middle_name']['display']) {
        $err = validate_customer_field('middle_name', $middleName);
        if ($err) {
          $validationErrors['middle_name'] = $err;
        }
    }

    if ($settings['email']['display']) {
        $err = validate_customer_field('email', $email);
        if ($err) {
          $validationErrors['email'] = $err;
        }
    }

    if ($settings['mobile']['display']) {
        $err = validate_customer_field('mobile', $mobile);
        if ($err) {
          $validationErrors['mobile'] = $err;
        }
    }

    if ($settings['date_of_birth']['display'] || $settings['date_of_birth']['required'] || $dateOfBirth !== '') {
        $err = validate_customer_field('date_of_birth', $dateOfBirth);
        if ($err) {
          $validationErrors['date_of_birth'] = $err;
        }
    }

    if ($settings['gender']['display'] || $settings['gender']['required'] || $gender !== '') {
        $err = validate_customer_field('gender', $gender);
        if ($err) {
          $validationErrors['gender'] = $err;
        }
    }

    if (!empty($validationErrors)) {
        if ($adminOverride && $role === 'admin') {
            $onlyMissingRequired = true;
            foreach ($validationErrors as $error) {
                if (strpos($error, 'is required') === false) {
                    $onlyMissingRequired = false;
                    break;
                }
            }

            if (!$onlyMissingRequired) {
                return ['ok' => false, 'status' => 422, 'error' => 'validation_error', 'errors' => $validationErrors];
            }
        } else {
            $missingRequired = [];
            foreach ($validationErrors as $field => $error) {
                if (strpos($error, 'is required') !== false) {
                    $missingRequired[] = $field;
                }
            }

            if (!empty($missingRequired)) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'error' => 'missing_required_fields',
                    'missing_fields' => $missingRequired,
                    'details' => $validationErrors,
                ];
            }

            return ['ok' => false, 'status' => 422, 'error' => 'validation_error', 'errors' => $validationErrors];
        }
    }

    $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName])));
    if ($fullName === '') {
        return ['ok' => false, 'status' => 422, 'error' => 'At least first name is required'];
    }

    if ($isUpdate) {
        if ($customerId < 1) {
            return ['ok' => false, 'status' => 400, 'error' => 'customer_id is required'];
        }

        if (!get_customer_by_id($customerId)) {
            return ['ok' => false, 'status' => 404, 'error' => 'customer not found'];
        }

        if (!update_customer($customerId, $fullName, $email ?: null, $mobile ?: null, $dateOfBirth ?: null, $gender ?: null)) {
            return ['ok' => false, 'status' => 500, 'error' => 'Failed to update customer'];
        }

        return ['ok' => true, 'customer' => get_customer_by_id($customerId)];
    }

    $customerId = create_customer($fullName, $email ?: null, $mobile ?: null, $dateOfBirth ?: null, $gender ?: null);
    if (!$customerId) {
        return ['ok' => false, 'status' => 500, 'error' => 'Failed to create customer'];
    }

    return ['ok' => true, 'customer' => get_customer_by_id($customerId)];
}

try {
    $action = $_GET['action'] ?? 'list';

    // GET /customers_api.php?action=search - Search customers for booking selection
    if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!in_array($role, ['admin', 'provider'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'permission denied']);
            exit;
        }

        $db = get_db();
        $query = trim((string) ($_GET['q'] ?? ''));
        $limit = min(max((int) ($_GET['limit'] ?? 8), 1), 20);

        if ($query === '') {
            echo json_encode(['customers' => []]);
            exit;
        }

        $searchPattern = '%' . $query . '%';
        $stmt = $db->prepare('SELECT id, full_name, email, mobile, date_of_birth, gender, date_added FROM customers WHERE full_name LIKE ? OR email LIKE ? OR mobile LIKE ? ORDER BY full_name ASC LIMIT ?');
        $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $limit]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['customers' => $customers]);
        exit;
    }

    // GET /customers_api.php?action=settings - Returns field settings for the client
    if ($action === 'settings' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $settings = get_customer_field_settings();
        echo json_encode(['settings' => $settings]);
        exit;
    }

    // POST /customers_api.php?action=create - Create a new customer
    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $result = save_customer_record($data, $role, false);
        if (empty($result['ok'])) {
            http_response_code((int) ($result['status'] ?? 400));
            unset($result['status']);
            echo json_encode($result);
            exit;
        }

        $customer = $result['customer'] ?? null;
        echo json_encode(['ok' => true, 'customer' => $customer]);
        exit;
    }

    // POST /customers_api.php?action=update - Update an existing customer
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $result = save_customer_record($data, $role, true);
        if (empty($result['ok'])) {
            http_response_code((int) ($result['status'] ?? 400));
            unset($result['status']);
            echo json_encode($result);
            exit;
        }

        echo json_encode(['ok' => true, 'customer' => $result['customer'] ?? null]);
        exit;
    }

    // GET /customers_api.php?action=list - List all customers (admin only)
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'permission denied']);
            exit;
        }

        $db = get_db();
        $query = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = (int) ($_GET['offset'] ?? (($page - 1) * 20));
        $limit = min(max((int) ($_GET['limit'] ?? 20), 1), 100);
        $sort = strtolower(trim((string) ($_GET['sort'] ?? 'full_name')));

        $offset = max(0, $offset);
        $limit = max(1, $limit);

        $sortMap = [
            'full_name' => 'full_name ASC, id ASC',
            'first_name' => "TRIM(SUBSTRING_INDEX(full_name, ' ', 1)) ASC, full_name ASC, id ASC",
            'last_name' => "TRIM(SUBSTRING_INDEX(full_name, ' ', -1)) ASC, full_name ASC, id ASC",
            'mobile' => 'mobile ASC, full_name ASC, id ASC',
            'email' => 'email ASC, full_name ASC, id ASC',
            'date_added_desc' => 'date_added DESC, full_name ASC, id ASC',
            'date_added_asc' => 'date_added ASC, full_name ASC, id ASC',
            'gender' => 'gender ASC, full_name ASC, id ASC',
        ];
        $orderBy = $sortMap[$sort] ?? $sortMap['full_name'];

        if ($query === '') {
            $stmt = $db->prepare("SELECT id, full_name, email, mobile, date_of_birth, gender, date_added FROM customers ORDER BY {$orderBy} LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = $db->query('SELECT COUNT(*) as cnt FROM customers');
            $totalCount = (int) ($countStmt->fetch()['cnt'] ?? 0);
        } else {
            $searchPattern = '%' . $query . '%';
            $stmt = $db->prepare("SELECT id, full_name, email, mobile, date_of_birth, gender, date_added FROM customers WHERE full_name LIKE ? OR email LIKE ? OR mobile LIKE ? ORDER BY {$orderBy} LIMIT ? OFFSET ?");
            $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $limit, $offset]);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = $db->prepare('SELECT COUNT(*) as cnt FROM customers WHERE full_name LIKE ? OR email LIKE ? OR mobile LIKE ?');
            $countStmt->execute([$searchPattern, $searchPattern, $searchPattern]);
            $totalCount = (int) ($countStmt->fetch()['cnt'] ?? 0);
        }

        echo json_encode(['customers' => $customers, 'total' => $totalCount]);
        exit;
    }

    // GET /customers_api.php?action=meta&id=X — raw metadata for a customer
    if ($action === 'meta' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'permission denied']);
            exit;
        }

        $customerId = (int) ($_GET['id'] ?? 0);
        if ($customerId < 1) {
            http_response_code(422);
            echo json_encode(['error' => 'id is required']);
            exit;
        }

        $db = get_db();
        try {
            $stmt = $db->prepare('
                SELECT id, full_name, email, mobile,
                       last_ip, last_user_agent, last_timezone,
                       last_screen, last_locale, last_seen_at,
                       date_added, date_of_birth, gender
                FROM customers WHERE id = ? LIMIT 1
            ');
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Fallback if meta columns don't exist yet
            $stmt = $db->prepare('SELECT id, full_name, email, mobile, date_added, date_of_birth, gender FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['error' => 'customer not found']);
            exit;
        }

        echo json_encode(['ok' => true, 'customer' => $customer]);
        exit;
    }

    // GET /customers_api.php?action=signals&id=X — duplicate-signal analysis
    if ($action === 'signals' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'permission denied']);
            exit;
        }

        $customerId = (int) ($_GET['id'] ?? 0);
        if ($customerId < 1) {
            http_response_code(422);
            echo json_encode(['error' => 'id is required']);
            exit;
        }

        $db = get_db();

        // Fetch the customer's own values first
        try {
            $stmt = $db->prepare('SELECT email, mobile, last_ip, last_user_agent, last_screen FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $self = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $stmt = $db->prepare('SELECT email, mobile FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $self = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$self) {
            http_response_code(404);
            echo json_encode(['error' => 'customer not found']);
            exit;
        }

        $signals = [];

        // ── Signal 1: shared email ───────────────────────────────────────────
        $email = trim((string) ($self['email'] ?? ''));
        if ($email !== '') {
            $stmt = $db->prepare('SELECT COUNT(*) FROM customers WHERE email = ? AND email != \'\' AND id != ?');
            $stmt->execute([$email, $customerId]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $total = $count + 1; // include self
                $signals[] = [
                    'type'    => 'shared_email',
                    'label'   => 'Email',
                    'count'   => $total,
                    'others'  => $count,
                    'value'   => $email,
                    'severity' => $total <= 4 ? 'green' : ($total <= 10 ? 'yellow' : 'red'),
                ];
            }
        }

        // ── Signal 2: shared mobile ──────────────────────────────────────────
        $mobile = trim((string) ($self['mobile'] ?? ''));
        if ($mobile !== '') {
            $stmt = $db->prepare('SELECT COUNT(*) FROM customers WHERE mobile = ? AND mobile != \'\' AND id != ?');
            $stmt->execute([$mobile, $customerId]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $total = $count + 1;
                $signals[] = [
                    'type'    => 'shared_mobile',
                    'label'   => 'Mobile',
                    'count'   => $total,
                    'others'  => $count,
                    'value'   => $mobile,
                    'severity' => $total <= 4 ? 'green' : ($total <= 10 ? 'yellow' : 'red'),
                ];
            }
        }

        // ── Signal 3: shared IP ──────────────────────────────────────────────
        $ip = trim((string) ($self['last_ip'] ?? ''));
        if ($ip !== '') {
            try {
                $stmt = $db->prepare('SELECT COUNT(*) FROM customers WHERE last_ip = ? AND last_ip IS NOT NULL AND id != ?');
                $stmt->execute([$ip, $customerId]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $total = $count + 1;
                    $signals[] = [
                        'type'    => 'shared_ip',
                        'label'   => 'IP',
                        'count'   => $total,
                        'others'  => $count,
                        'value'   => $ip,
                        'severity' => $total <= 4 ? 'green' : ($total <= 10 ? 'yellow' : 'red'),
                    ];
                }
            } catch (Throwable $e) { /* meta columns not yet migrated */ }
        }

        // ── Signal 4: shared IP + device fingerprint ─────────────────────────
        // Fingerprint = same IP + same user_agent + same screen resolution
        $ua     = trim((string) ($self['last_user_agent'] ?? ''));
        $screen = trim((string) ($self['last_screen'] ?? ''));
        if ($ip !== '' && $ua !== '' && $screen !== '') {
            try {
                $stmt = $db->prepare('
                    SELECT COUNT(*) FROM customers
                    WHERE last_ip = ?
                      AND last_user_agent = ?
                      AND last_screen = ?
                      AND last_ip IS NOT NULL
                      AND id != ?
                ');
                $stmt->execute([$ip, $ua, $screen, $customerId]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $total = $count + 1;
                    $signals[] = [
                        'type'    => 'shared_device',
                        'label'   => 'Device',
                        'count'   => $total,
                        'others'  => $count,
                        'value'   => $screen . ' / ' . substr($ua, 0, 60) . (strlen($ua) > 60 ? '…' : ''),
                        'severity' => $total <= 2 ? 'green' : ($total <= 3 ? 'yellow' : 'red'),
                    ];
                }
            } catch (Throwable $e) { /* meta columns not yet migrated */ }
        }

        echo json_encode(['ok' => true, 'customer_id' => $customerId, 'signals' => $signals]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
