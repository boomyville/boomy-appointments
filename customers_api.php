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
        $customerId = create_customer($fullName, $email ?: null, $mobile ?: null);

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
        $offset = (int) ($_GET['offset'] ?? 0);
        $limit = min((int) ($_GET['limit'] ?? 50), 200);

        $offset = max(0, $offset);
        $limit = max(1, $limit);

        if ($query === '') {
            $stmt = $db->prepare('SELECT id, full_name, email, mobile FROM customers ORDER BY full_name ASC LIMIT ? OFFSET ?');
            $stmt->execute([$limit, $offset]);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = $db->query('SELECT COUNT(*) as cnt FROM customers');
            $totalCount = (int) ($countStmt->fetch()['cnt'] ?? 0);
        } else {
            $searchPattern = '%' . $query . '%';
            $stmt = $db->prepare('SELECT id, full_name, email, mobile FROM customers WHERE full_name LIKE ? OR email LIKE ? OR mobile LIKE ? ORDER BY full_name ASC LIMIT ? OFFSET ?');
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
