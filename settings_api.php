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

$action = $_GET['action'] ?? 'get';

try {
    if ($action === 'get') {
        $keys = [
            'site_name', 'organization_email', 'date_format', 'booking_deadline_hours',
            'booking_firstname_display', 'booking_firstname_required', 'booking_firstname_letters_only', 'booking_firstname_min', 'booking_firstname_max',
            'booking_lastname_display', 'booking_lastname_required', 'booking_lastname_letters_only', 'booking_lastname_min', 'booking_lastname_max',
            'booking_middlename_display', 'booking_middlename_required', 'booking_middlename_letters_only', 'booking_middlename_min', 'booking_middlename_max',
            'booking_email_display', 'booking_email_required', 'booking_email_allowed_domains',
            'booking_phone_display', 'booking_phone_required', 'booking_phone_numbers_only', 'booking_phone_min', 'booking_phone_max',
            'password_min_length', 'password_max_length', 'password_require_lowercase', 'password_require_uppercase', 'password_require_number', 'password_require_symbol',
            'email_whitelist_enabled', 'email_domain_whitelist', 'email_domain_blacklist',
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'mail_from_address', 'mail_from_name'
        ];
        $out = [];
        foreach ($keys as $k) $out[$k] = get_setting($k, null);
        echo json_encode(['ok' => true, 'settings' => $out]);
        exit;
    }

    if ($action === 'save') {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : $_POST;
        $token = $data['csrf_token'] ?? null;
        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        // only admin can save
        if (empty($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        // Allowed fields per tab
        $allowed = [
            'general' => ['site_name', 'organization_email', 'date_format'],
            'booking' => [
                'booking_firstname_display', 'booking_firstname_required', 'booking_firstname_letters_only', 'booking_firstname_min', 'booking_firstname_max',
                'booking_lastname_display', 'booking_lastname_required', 'booking_lastname_letters_only', 'booking_lastname_min', 'booking_lastname_max',
                'booking_middlename_display', 'booking_middlename_required', 'booking_middlename_letters_only', 'booking_middlename_min', 'booking_middlename_max',
                'booking_email_display', 'booking_email_required', 'booking_email_allowed_domains',
                'booking_phone_display', 'booking_phone_required', 'booking_phone_numbers_only', 'booking_phone_min', 'booking_phone_max'
            ],
            'authentication' => [
                'password_min_length', 'password_max_length', 'password_require_lowercase', 'password_require_uppercase', 'password_require_number', 'password_require_symbol',
                'email_whitelist_enabled', 'email_domain_whitelist', 'email_domain_blacklist'
            ],
            'phpmailer' => ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'mail_from_address', 'mail_from_name']
        ];

        $tab = $data['tab'] ?? 'general';
        $fields_to_save = $allowed[$tab] ?? $allowed['general'];

        // Validate lengths and dependencies
        if ($tab === 'booking') {
            foreach (['firstname', 'lastname', 'middlename', 'phone'] as $field) {
                $min_key = "booking_{$field}_min";
                $max_key = "booking_{$field}_max";
                $min_val = (int)($data[$min_key] ?? 1);
                $max_val = (int)($data[$max_key] ?? 255);
                if ($max_val < $min_val) {
                    http_response_code(422);
                    echo json_encode(['error' => "{$field} max length must be >= min length"]);
                    exit;
                }
            }
        }
        
        if ($tab === 'authentication') {
            $min_pass = (int)($data['password_min_length'] ?? 8);
            $max_pass = (int)($data['password_max_length'] ?? 128);
            if ($max_pass < $min_pass) {
                http_response_code(422);
                echo json_encode(['error' => 'Password max length must be >= min length']);
                exit;
            }
            // Clean up domain lists (strip whitespace, remove empty lines)
            if (isset($data['email_domain_whitelist'])) {
                $lines = array_filter(array_map('trim', explode('\n', $data['email_domain_whitelist'])));
                $data['email_domain_whitelist'] = implode('\n', $lines);
            }
            if (isset($data['email_domain_blacklist'])) {
                $lines = array_filter(array_map('trim', explode('\n', $data['email_domain_blacklist'])));
                $data['email_domain_blacklist'] = implode('\n', $lines);
            }
        }

        // Save fields
        foreach ($fields_to_save as $k) {
            if (isset($data[$k])) {
                $value = $data[$k];
                // Handle checkboxes (they're only present if checked)
                if (strpos($k, '_display') !== false || strpos($k, '_required') !== false || strpos($k, '_letters_only') !== false || strpos($k, '_numbers_only') !== false) {
                    $value = $value === '1' ? '1' : '0';
                }
                set_setting($k, $value);
            } elseif (strpos($k, '_display') !== false || strpos($k, '_required') !== false || strpos($k, '_letters_only') !== false || strpos($k, '_numbers_only') !== false) {
                // Checkbox not present means it was unchecked
                set_setting($k, '0');
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
