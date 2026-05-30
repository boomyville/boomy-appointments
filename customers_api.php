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

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $middleName = trim((string) ($data['middle_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $mobile = trim((string) ($data['mobile'] ?? ''));
        $dateOfBirth = trim((string) ($data['date_of_birth'] ?? ''));
        $gender = trim((string) ($data['gender'] ?? ''));
        $adminOverride = (bool) ($data['admin_override'] ?? false);

        $settings = get_customer_field_settings();

        // Validate each field if it's displayed
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

        // If there are validation errors
        if (!empty($validationErrors)) {
            // Admin can override missing required fields
            if ($adminOverride && $role === 'admin') {
                // Check if ONLY required fields are missing (not format errors)
                $onlyMissingRequired = true;
                foreach ($validationErrors as $field => $error) {
                    // If it's not a "is required" error, it's a format/length error and can't be overridden
                    if (strpos($error, 'is required') === false) {
                        $onlyMissingRequired = false;
                        break;
                    }
                }

                if (!$onlyMissingRequired) {
                    http_response_code(422);
                    echo json_encode(['error' => 'validation_error', 'errors' => $validationErrors]);
                    exit;
                }

                // Allowed to proceed with override
            } else {
                // Not admin or not overriding; report missing required fields
                $missingRequired = [];
                foreach ($validationErrors as $field => $error) {
                    if (strpos($error, 'is required') !== false) {
                        $missingRequired[] = $field;
                    }
                }

                if (!empty($missingRequired)) {
                    // Return missing fields so client can prompt for override
                    http_response_code(422);
                    echo json_encode([
                        'error' => 'missing_required_fields',
                        'missing_fields' => $missingRequired,
                        'details' => $validationErrors
                    ]);
                    exit;
                }

                // Non-required validation errors (format/length)
                http_response_code(422);
                echo json_encode(['error' => 'validation_error', 'errors' => $validationErrors]);
                exit;
            }
        }

        // Build full name from parts
        $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName])));
        if (empty($fullName)) {
            http_response_code(422);
            echo json_encode(['error' => 'At least first name is required']);
            exit;
        }

        // Create customer
        $customerId = create_customer($fullName, $email ?: null, $mobile ?: null, $dateOfBirth ?: null, $gender ?: null);

        if (!$customerId) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create customer']);
            exit;
        }

        $customer = get_customer_by_id($customerId);
        echo json_encode(['ok' => true, 'customer' => $customer]);
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

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
