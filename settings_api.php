<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();
header('Content-Type: application/json; charset=utf-8');

if (!function_exists('normalize_booking_calendar_height_setting')) {
    function normalize_booking_calendar_height_setting($value)
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return 1800;
        }

        if ($raw === 'auto') {
            return 'auto';
        }

        if (preg_match('/^(\d+)(?:px)?$/', $raw, $matches)) {
            $height = (int) $matches[1];
            if ($height >= 600 && $height <= 10000) {
                return $height;
            }
        }

        return 1800;
    }
}

$action = $_GET['action'] ?? 'get';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

try {
    $db = get_db();
    if ($action === 'get') {
        $keys = [
            'site_name', 'root_url', 'organization_email', 'organization_number', 'date_format', 'booking_deadline_hours', 'booking_max_advance_days', 'booking_step1_message', 'booking_step4_message',
            'booking_firstname_display', 'booking_firstname_required', 'booking_firstname_letters_only', 'booking_firstname_min', 'booking_firstname_max',
            'booking_lastname_display', 'booking_lastname_required', 'booking_lastname_letters_only', 'booking_lastname_min', 'booking_lastname_max',
            'booking_middlename_display', 'booking_middlename_required', 'booking_middlename_letters_only', 'booking_middlename_min', 'booking_middlename_max',
            'booking_email_display', 'booking_email_required',
            'booking_phone_display', 'booking_phone_required', 'booking_phone_numbers_only', 'booking_phone_min', 'booking_phone_max',
            'booking_dob_display', 'booking_dob_required', 'booking_gender_display', 'booking_gender_required',
            'booking_show_monday', 'booking_show_tuesday', 'booking_show_wednesday', 'booking_show_thursday', 'booking_show_friday', 'booking_show_saturday', 'booking_show_sunday',
            'booking_start_time', 'booking_end_time', 'notify_admin_on_booking', 'notify_admin_on_cancel', 'booking_week_start_day', 'booking_calendar_height', 'booking_show_now_indicator', 'booking_snap_minutes', 'auto_no_show', 'prevent_same_user_booking', 'disable_online_booking',
            'password_min_length', 'password_max_length', 'password_require_lowercase', 'password_require_uppercase', 'password_require_number', 'password_require_symbol', 'confirmation_email_message', 'reminder_email_message',
            'email_whitelist_enabled', 'email_domain_whitelist', 'email_domain_blacklist',
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'mail_from_address', 'mail_from_name'
        ];
        $out = [];
        foreach ($keys as $k) $out[$k] = get_setting($k, null);
        echo json_encode(['ok' => true, 'settings' => $out]);
        exit;
    }

    if ($action === 'breaks') {
        if (empty($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $rawInput = file_get_contents('php://input');
        $data = $rawInput ? json_decode($rawInput, true) : $_POST;

        if ($requestMethod === 'GET') {
            $dayValue = $_GET['day_of_week'] ?? $_GET['day'] ?? '';
            $dayOfWeek = normalize_break_day_of_week(is_string($dayValue) ? $dayValue : (string) $dayValue);
            $breaks = get_standard_breaks($dayOfWeek, true);
            foreach ($breaks as &$breakRow) {
                $breakRow['day_label'] = format_break_day_label(isset($breakRow['day_of_week']) ? ($breakRow['day_of_week'] !== null ? (int) $breakRow['day_of_week'] : null) : null);
            }
            unset($breakRow);

            echo json_encode(['ok' => true, 'breaks' => $breaks]);
            exit;
        }

        if ($requestMethod !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'method not allowed']);
            exit;
        }

        $token = $data['csrf_token'] ?? null;
        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        $command = (string) ($data['command'] ?? 'create');
        if ($command === 'delete') {
            $breakId = (int) ($data['break_id'] ?? 0);
            if ($breakId < 1) {
                http_response_code(422);
                echo json_encode(['error' => 'break id is required']);
                exit;
            }

            $deleted = delete_standard_break($breakId);
            if (!$deleted) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete break']);
                exit;
            }

            echo json_encode(['ok' => true, 'message' => 'Break deleted successfully']);
            exit;
        }

        $dayValue = $data['day_of_week'] ?? '';
        $dayOfWeek = normalize_break_day_of_week(is_string($dayValue) ? $dayValue : (string) $dayValue);
        $startTime = trim((string) ($data['start_time'] ?? ''));
        $endTime = trim((string) ($data['end_time'] ?? ''));
        $isActive = array_key_exists('is_active', $data) ? !empty($data['is_active']) : true;

        if ($startTime === '' || $endTime === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Start time and end time are required']);
            exit;
        }

        $breakId = create_standard_break($dayOfWeek, $startTime, $endTime, $isActive);
        if (!$breakId) {
            http_response_code(422);
            echo json_encode(['error' => 'Unable to create break. Check the selected times.']);
            exit;
        }

        $createdBreaks = get_standard_breaks($dayOfWeek, true);
        foreach ($createdBreaks as &$breakRow) {
            $breakRow['day_label'] = format_break_day_label(isset($breakRow['day_of_week']) ? ($breakRow['day_of_week'] !== null ? (int) $breakRow['day_of_week'] : null) : null);
        }
        unset($breakRow);

        echo json_encode(['ok' => true, 'message' => 'Break created successfully', 'breaks' => $createdBreaks]);
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

        if (empty($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        // Allowed fields array mapping structure
        $allowed = [
            'general' => ['site_name', 'root_url', 'organization_email', 'organization_number', 'date_format'],
            'customer_registration' => [
                'booking_firstname_display', 'booking_firstname_required', 'booking_firstname_letters_only', 'booking_firstname_min', 'booking_firstname_max',
                'booking_lastname_display', 'booking_lastname_required', 'booking_lastname_letters_only', 'booking_lastname_min', 'booking_lastname_max',
                'booking_middlename_display', 'booking_middlename_required', 'booking_middlename_letters_only', 'booking_middlename_min', 'booking_middlename_max',
                'booking_email_display', 'booking_email_required',
                'booking_phone_display', 'booking_phone_required', 'booking_phone_numbers_only', 'booking_phone_min', 'booking_phone_max',
                'booking_dob_display', 'booking_dob_required', 'booking_gender_display', 'booking_gender_required'
            ],
            'booking' => [
                'booking_deadline_hours', 'booking_max_advance_days',
                'booking_show_monday', 'booking_show_tuesday', 'booking_show_wednesday', 'booking_show_thursday', 'booking_show_friday', 'booking_show_saturday', 'booking_show_sunday',
                'booking_start_time', 'notify_admin_on_booking', 'notify_admin_on_cancel', 'booking_end_time', 'booking_week_start_day', 'booking_calendar_height', 'booking_show_now_indicator', 'booking_snap_minutes', 'auto_no_show', 'prevent_same_user_booking', 'disable_online_booking',
                'booking_step1_message', 'booking_step4_message', 'user_questions'
            ],
            'authentication' => [
                'password_min_length', 'password_max_length', 'password_require_lowercase', 'password_require_uppercase', 'password_require_number', 'password_require_symbol',
                'email_whitelist_enabled', 'email_domain_whitelist', 'email_domain_blacklist'
            ],
            'phpmailer' => [
                'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'mail_from_address', 'mail_from_name',
                'confirmation_email_message'
            ],
            'sessions' => ['session_lifetime_hours'],
            'cron' => ['cron_key'],
            'reminders' => ['reminder_enabled', 'reminder_days_before', 'reminder_time', 'reminder_email_message']
        ];

        $tab = $data['tab'] ?? 'general';
        $fields_to_save = $allowed[$tab] ?? $allowed['general'];

        if ($tab === 'customer_registration') {
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

        if ($tab === 'sessions') {
            $lifetime = (int)($data['session_lifetime_hours'] ?? 18);
            if ($lifetime < 1 || $lifetime > 2000) {
                http_response_code(422);
                echo json_encode(['error' => 'Session lifetime must be between 1 and 2000 hours']);
                exit;
            }
            $data['session_lifetime_hours'] = (string)$lifetime;
        }

                if ($tab === 'reminders') {
            if (!empty($data['reminder_enabled']) && $data['reminder_enabled'] === '1') {
                if (!email_transport_is_ready()) {
                    http_response_code(422);
                    echo json_encode(['error' => 'SMTP must be fully configured before enabling reminders']);
                    exit;
                }
            }
            $days = (int) ($data['reminder_days_before'] ?? 1);
            if ($days < 0 || $days > 30) {
                http_response_code(422);
                echo json_encode(['error' => 'Reminder days must be between 0 and 30']);
                exit;
            }
            $reminderTime = trim((string) ($data['reminder_time'] ?? '09:00'));
            if (!preg_match('/^\d{2}:\d{2}$/', $reminderTime)) {
                http_response_code(422);
                echo json_encode(['error' => 'Reminder time must be in HH:MM format']);
                exit;
            }
            $data['reminder_days_before'] = (string) $days;
            $data['reminder_time']        = $reminderTime;
        }


        if ($tab === 'booking') {
            $deadline_hours = (int) ($data['booking_deadline_hours'] ?? 24); 
            $max_advance_days = (int) ($data['booking_max_advance_days'] ?? 60);
            $start_time = trim((string) ($data['booking_start_time'] ?? '09:00'));
            $end_time = trim((string) ($data['booking_end_time'] ?? '21:00'));
            $week_start_day = normalize_booking_week_start_day_setting($data['booking_week_start_day'] ?? '0');
            $calendar_height_raw = trim((string) ($data['booking_calendar_height'] ?? '1800'));
            $snap_options = [1, 2, 5, 10, 15, 20, 30, 40, 45, 60];
            $snap_minutes = (int) ($data['booking_snap_minutes'] ?? 15);

            if ($deadline_hours < 0) {
                http_response_code(422);
                echo json_encode(['error' => 'Booking deadline must be zero or greater']);
                exit;
            }
            if ($max_advance_days < 1) {
                http_response_code(422);
                echo json_encode(['error' => 'Max advance booking days must be at least 1']);
                exit;
            }

            if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $start_time) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $end_time)) {
                http_response_code(422);
                echo json_encode(['error' => 'Booking times must be valid HH:MM values']);
                exit;
            }

            if (strlen($start_time) === 5) $start_time .= ':00';
            if (strlen($end_time) === 5) $end_time .= ':00';

            if ($end_time <= $start_time) {
                http_response_code(422);
                echo json_encode(['error' => 'Booking end time must be after start time']);
                exit;
            }

            if ($week_start_day < 0 || $week_start_day > 6) {
                http_response_code(422);
                echo json_encode(['error' => 'Week start day must be between 0 and 6']);
                exit;
            }

            if (strcasecmp($calendar_height_raw, 'auto') === 0) {
                $calendarHeight = 'auto';
            } elseif (preg_match('/^(\d+)(?:px)?$/i', $calendar_height_raw, $matches)) {
                $calendarHeight = (int) $matches[1];
                if ($calendarHeight < 600 || $calendarHeight > 10000) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Calendar height must be auto or a value between 600 and 10000']);
                    exit;
                }
            } else {
                http_response_code(422);
                echo json_encode(['error' => 'Calendar height must be auto or a value between 600 and 10000']);
                exit;
            }

            $days_enabled = false;
            foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
                if (!empty($data['booking_show_' . $day])) {
                    $days_enabled = true;
                    break;
                }
            }

            if (!$days_enabled) {
                http_response_code(422);
                echo json_encode(['error' => 'Select at least one booking day']);
                exit;
            }

            if (!in_array($snap_minutes, $snap_options, true)) {
                http_response_code(422);
                echo json_encode(['error' => 'Booking snap window must be one of the configured minute values']);
                exit;
            }

            $data['booking_start_time'] = substr($start_time, 0, 5);
            $data['booking_end_time'] = substr($end_time, 0, 5);
            $data['booking_week_start_day'] = (string) $week_start_day;
            $data['booking_calendar_height'] = $calendarHeight === 'auto' ? 'auto' : (string) $calendarHeight;
            $data['booking_deadline_hours'] = (string) $deadline_hours;
            $data['booking_snap_minutes'] = (string) $snap_minutes;
            $data['booking_max_advance_days'] = (string) $max_advance_days;
            $data['auto_no_show'] = isset($data['auto_no_show']) && $data['auto_no_show'] === '1' ? '1' : '0';
            $data['prevent_same_user_booking'] = (string) max(0, (int)($data['prevent_same_user_booking'] ?? 0));
            
            $existingTypes = [];
            try {
                $stmt = $db->query('SELECT id, name, duration_minutes, color_hex, is_active FROM appointment_types ORDER BY id ASC');
                $existingTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $existingTypes = [];
            }

            try {
                $db->beginTransaction();

                foreach ($existingTypes as $typeRow) {
                    $typeId = (int) $typeRow['id'];
                    $typePrefix = 'appt_type_' . $typeId . '_';
                    $deleteRequested = !empty($data[$typePrefix . 'delete']);

                    if ($deleteRequested) {
                        $stmt = $db->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_type_id = ?');
                        $stmt->execute([$typeId]);
                        $appointmentCount = (int) $stmt->fetchColumn();

                        $stmt = $db->prepare('SELECT COUNT(*) FROM provider_appointment_types WHERE appointment_type_id = ?');
                        $stmt->execute([$typeId]);
                        $providerTypeCount = (int) $stmt->fetchColumn();

                        if ($appointmentCount > 0 || $providerTypeCount > 0) {
                            throw new RuntimeException('Cannot delete an appointment type that is already in use. Unassign it first or set it inactive.');
                        }

                        $stmt = $db->prepare('DELETE FROM appointment_types WHERE id = ?');
                        $stmt->execute([$typeId]);
                        continue;
                    }

                    $name = trim((string) ($data[$typePrefix . 'name'] ?? ''));
                    if ($name === '') {
                        throw new RuntimeException('Appointment type name cannot be empty.');
                    }

                    $durationMinutes = (int) ($data[$typePrefix . 'duration_minutes'] ?? 30);
                    if ($durationMinutes < 1) {
                        throw new RuntimeException('Appointment type duration must be at least 1 minute.');
                    }

                    $colorHex = trim((string) ($data[$typePrefix . 'color_hex'] ?? '#2457ff'));
                    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colorHex)) {
                        throw new RuntimeException('Appointment type colour must be a valid hex value.');
                    }

                    $isActive = !empty($data[$typePrefix . 'is_active']) ? 1 : 0;
                    $isBookable = !empty($data[$typePrefix . 'is_bookable']) ? 1 : 0;
                    $stmt = $db->prepare('UPDATE appointment_types SET name = ?, duration_minutes = ?, color_hex = ?, is_active = ?, is_bookable = ? WHERE id = ?');
                    $stmt->execute([$name, $durationMinutes, $colorHex, $isActive, $isBookable, $typeId]);
                }

                $newName = trim((string) ($data['new_appt_type_name'] ?? ''));
                if ($newName !== '') {
                    $newDuration = (int) ($data['new_appt_type_duration_minutes'] ?? 30);
                    if ($newDuration < 1) {
                        throw new RuntimeException('New appointment type duration must be at least 1 minute.');
                    }

                    $newColor = trim((string) ($data['new_appt_type_color_hex'] ?? '#2457ff'));
                    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $newColor)) {
                        throw new RuntimeException('New appointment type colour must be a valid hex value.');
                    }

                    $newActive = !empty($data['new_appt_type_is_active']) ? 1 : 0;
                    $newBookable = !empty($data['new_appt_type_is_bookable']) ? 1 : 0;
                    $stmt = $db->prepare('INSERT INTO appointment_types (name, duration_minutes, color_hex, is_active, is_bookable) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$newName, $newDuration, $newColor, $newActive, $newBookable]);
                }

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                http_response_code(422);
                echo json_encode(['error' => $e->getMessage()]);
                exit;
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
            if (isset($data['email_domain_whitelist'])) {
                $lines = array_filter(array_map('trim', explode("\n", $data['email_domain_whitelist'])));
                $data['email_domain_whitelist'] = implode("\n", $lines);
            }
            if (isset($data['email_domain_blacklist'])) {
                $lines = array_filter(array_map('trim', explode("\n", $data['email_domain_blacklist'])));
                $data['email_domain_blacklist'] = implode("\n", $lines);
            }
        }

        // Booking questions: the textarea (one question per line) is stored as JSON in `user_questions`
        if ($tab === 'booking' && isset($data['user_questions_text'])) {
            $data['user_questions'] = json_encode(parse_user_questions_text((string) $data['user_questions_text']), JSON_UNESCAPED_UNICODE);
        }

        // Processing Loop
        foreach ($fields_to_save as $k) {
            $isCheckbox = (
                strpos($k, '_display') !== false || 
                strpos($k, '_required') !== false || 
                strpos($k, '_require') !== false || 
                strpos($k, '_enabled') !== false || 
                strpos($k, '_letters_only') !== false || 
                strpos($k, '_numbers_only') !== false || 
                strpos($k, '_show_') !== false || 
                $k === 'auto_no_show' ||
                $k === 'reminder_enabled' ||
                $k === 'notify_admin_on_booking' ||
                $k === 'notify_admin_on_cancel'

            );

            if (isset($data[$k])) {
                $value = $data[$k];
                if ($isCheckbox) {
                    $value = $value === '1' ? '1' : '0';
                }
                
                // Safety logic layer for masked fields
                if (in_array($k, ['smtp_password', 'cron_key'], true) && trim((string)$value) === '') {
                    continue; 
                }
                
                set_setting($k, $value);
            } elseif ($isCheckbox) {
                set_setting($k, '0');
            }
        }

        $message = 'Settings saved successfully';
        if ($tab === 'authentication') {
            $message = 'Authentication settings saved successfully';
        } elseif ($tab === 'customer_registration') {
            $message = 'Customer registration settings saved successfully';
        } elseif ($tab === 'booking') {
            $message = 'Booking settings and appointment types saved successfully';
        } elseif ($tab === 'phpmailer') {
            $message = 'PHPMailer settings saved successfully';
        } elseif ($tab === 'general') {
            $message = 'General settings saved successfully';
        } elseif ($tab === 'reminders') {
            $message = 'Reminder settings saved successfully';
        } elseif ($tab === 'cron') {
            $message = 'Cron security settings saved successfully';
        }


        echo json_encode(['ok' => true, 'message' => $message]);
        exit;
    }

    if ($action === 'test_email') {
        if (empty($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : $_POST;
        $token = $data['csrf_token'] ?? null;
        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        $to_email = $_SESSION['user']['email'];
        $subject = 'Test Email from ' . (get_setting('site_name') ?? 'Boomy Appointments');
        $body_html = '
            <h2>Test Email</h2>
            <p>This is a test email to verify your SMTP configuration is working correctly.</p>
            <p>If you received this, your email settings are properly configured!</p>
        ';
        $body_plain = "Test Email\n\nThis is a test email to verify your SMTP configuration is working correctly.\n\nIf you received this, your email settings are properly configured!";

        $result = send_email($to_email, $subject, $body_html, $body_plain);

        if ($result === true) {
            $diag = email_diagnostics($to_email, $subject);
            echo json_encode(['ok' => true, 'sent_to' => $to_email, 'message' => 'Test email sent successfully', 'debug' => $diag]);
        } else if (is_array($result)) {
            http_response_code(422);
            echo json_encode([
                'error' => 'Failed to send email',
                'debug' => $result
            ]);
        } else {
            http_response_code(500);
            $diag = email_diagnostics($to_email, $subject);
            echo json_encode(['error' => 'Unknown error sending email', 'debug' => $diag]);
        }
        exit;
    }

    if ($action === 'login_attempts') {
        $db = get_db(); 
        if (empty($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per = 50;
            $offset = ($page - 1) * $per;
            $stmt = $db->prepare('SELECT COUNT(*) AS c FROM login_attempts');
            $stmt->execute();
            $total = (int)$stmt->fetchColumn();
            $stmt = $db->prepare('SELECT id, attempted_at, ip_address, username, success, user_agent FROM login_attempts ORDER BY attempted_at DESC LIMIT ? OFFSET ?');
            $stmt->bindValue(1, $per, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $total_pages = (int) ceil($total / $per);
            echo json_encode(['ok' => true, 'attempts' => $rows, 'total' => $total, 'page' => $page, 'total_pages' => $total_pages]);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : $_POST;
        $token = $data['csrf_token'] ?? null;
        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        $cmd = $data['command'] ?? '';
        if ($cmd === 'delete_all') {
            $db->exec('TRUNCATE TABLE login_attempts');
            echo json_encode(['ok' => true]);
            exit;
        }
        if ($cmd === 'delete_older_7_days') {
            $stmt = $db->prepare('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 7 DAY)');
            $stmt->execute();
            echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount()]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['error' => 'unknown command']);
        exit;
    }

    if ($action === 'change_password') {
        $db = get_db();
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : $_POST;
        $token = $data['csrf_token'] ?? null;
        if (!verify_csrf_token($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'csrf']);
            exit;
        }

        $current = (string)($data['current_password'] ?? '');
        $new = (string)($data['new_password'] ?? '');
        $confirm = (string)($data['new_password_confirm'] ?? '');

        if ($new !== $confirm) {
            http_response_code(422);
            echo json_encode(['error' => 'Passwords do not match']);
            exit;
        }

        $email = $_SESSION['user']['email'] ?? null;
        if (!$email) {
            http_response_code(400);
            echo json_encode(['error' => 'Unable to determine current user']);
            exit;
        }

        $user = verify_user_credentials($email, $current);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Current password is incorrect']);
            exit;
        }

        $pass_err = validate_password($new);
        if ($pass_err !== true) {
            http_response_code(422);
            echo json_encode(['error' => $pass_err]);
            exit;
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        if (!$stmt->execute([$hash, $user['id']])) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update password']);
            exit;
        }

        echo json_encode(['ok' => true, 'message' => 'Password changed successfully']);
        exit;
    }

    if ($action === 'sessions') {
    if (empty($_SESSION['user']['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }

    $userId = (int)$_SESSION['user']['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sessions = get_active_sessions_for_user($userId);
        echo json_encode(['ok' => true, 'sessions' => $sessions]);
        exit;
    }

    // POST — revoke
    $raw   = file_get_contents('php://input');
    $data  = $raw ? json_decode($raw, true) : $_POST;
    $token = $data['csrf_token'] ?? null;
    if (!verify_csrf_token($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'csrf']);
        exit;
    }

    $command = $data['command'] ?? '';

    if ($command === 'revoke_all_others') {
        $db = get_db();
        $currentSessionId = session_id();
        $stmt = $db->prepare("
            UPDATE sessions SET is_active = 0
            WHERE user_id = ? AND session_id != ?
        ");
        $stmt->execute([$userId, $currentSessionId]);
        echo json_encode(['ok' => true, 'message' => 'All other sessions revoked']);
        exit;
    }

    if ($command === 'revoke') {
        $targetSessionId = trim((string)($data['session_id'] ?? ''));
        if ($targetSessionId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'session_id is required']);
            exit;
        }
        // Verify it belongs to this user
        $db = get_db();
        $stmt = $db->prepare("SELECT user_id FROM sessions WHERE session_id = ? LIMIT 1");
        $stmt->execute([$targetSessionId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }
        invalidate_session_record($targetSessionId);
        echo json_encode(['ok' => true, 'message' => 'Session revoked']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown command']);
    exit;
}

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}