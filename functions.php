<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Session hardening and initialization
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Harden session cookie params
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();

        // Run the automatic stale appointment cleanup pass cleanly once per load cycle
        process_automatic_no_shows();
    }
}

// PDO database connection (singleton)
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Only run schema checks if not already confirmed this session
    if (empty($_SESSION['schema_checked'])) {
        ensure_customer_schema($pdo);
        ensure_breaks_schema($pdo);
        ensure_user_schema($pdo);
        ensure_verification_schema($pdo);
        ensure_appointment_schema($pdo);
        ensure_customer_meta_schema($pdo);
        $_SESSION['schema_checked'] = true;
    }

    return $pdo;
}

function ensure_customer_meta_schema(PDO $db): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $tableExists = (bool) $db->query("SHOW TABLES LIKE 'customers'")->fetch();
        if (!$tableExists) return;

        $columns = [
            'last_ip'         => "VARCHAR(45) NULL DEFAULT NULL",
            'last_user_agent' => "VARCHAR(512) NULL DEFAULT NULL",
            'last_timezone'   => "VARCHAR(64) NULL DEFAULT NULL",
            'last_screen'     => "VARCHAR(32) NULL DEFAULT NULL",
            'last_locale'     => "VARCHAR(32) NULL DEFAULT NULL",
            'last_seen_at'    => "TIMESTAMP NULL DEFAULT NULL",
        ];

        foreach ($columns as $column => $definition) {
            $exists = (bool) $db->query(
                "SHOW COLUMNS FROM customers LIKE '{$column}'"
            )->fetch();
            if (!$exists) {
                $db->exec("ALTER TABLE customers ADD COLUMN {$column} {$definition}");
            }
        }
    } catch (Throwable $e) {
        // Non-fatal — tracking columns are optional
    }
}

function ensure_user_schema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExists = (bool) $db->query("SHOW TABLES LIKE 'users'")->fetch();
        if (!$tableExists) {
            return;
        }

        // Checks for the bookable column layout flag, adding it if missing
        $hasBookable = (bool) $db->query("SHOW COLUMNS FROM users LIKE 'bookable'")->fetch();
        if (!$hasBookable) {
            $db->exec("ALTER TABLE users ADD COLUMN bookable TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Throwable $e) {
        // Safe execution catch
    }
}
function ensure_customer_schema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $hasDateAdded = (bool) $db->query("SHOW COLUMNS FROM customers LIKE 'date_added'")->fetch();
        if (!$hasDateAdded) {
            $db->exec("ALTER TABLE customers ADD COLUMN date_added TIMESTAMP NULL DEFAULT NULL AFTER date_of_birth");
            $db->exec("UPDATE customers SET date_added = created_at WHERE date_added IS NULL");
            $db->exec("ALTER TABLE customers MODIFY date_added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }

        $hasGender = (bool) $db->query("SHOW COLUMNS FROM customers LIKE 'gender'")->fetch();
        if (!$hasGender) {
            $db->exec("ALTER TABLE customers ADD COLUMN gender ENUM('male','female','other','unknown') NOT NULL DEFAULT 'unknown' AFTER date_added");
        }
    } catch (Throwable $e) {
        // Keep the app running even if the schema cannot be updated automatically.
    }
}

function ensure_breaks_schema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExists = (bool) $db->query("SHOW TABLES LIKE 'breaks'")->fetch();
        if (!$tableExists) {
            $db->exec("CREATE TABLE `breaks` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `day_of_week` TINYINT UNSIGNED NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_breaks_day_time` (`day_of_week`,`start_time`,`end_time`),
  KEY `idx_breaks_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (Throwable $e) {
        // Ignore schema bootstrap failures so the app can continue running.
    }
}

function ensure_appointment_schema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $hasNotes = (bool) $db->query("SHOW COLUMNS FROM appointments LIKE 'notes'")->fetch();
        if (!$hasNotes) {
            $db->exec("ALTER TABLE appointments ADD COLUMN notes TEXT NULL AFTER status");
        }

        $hasHashKey = (bool) $db->query("SHOW COLUMNS FROM appointments LIKE 'hash_key'")->fetch();
        if (!$hasHashKey) {
            $db->exec("ALTER TABLE appointments ADD COLUMN hash_key CHAR(64) NOT NULL DEFAULT '' AFTER notes");

            $stmt = $db->query("SELECT id FROM appointments WHERE hash_key = '' OR hash_key IS NULL");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $update = $db->prepare('UPDATE appointments SET hash_key = ? WHERE id = ?');
            foreach ($rows as $row) {
                $update->execute([bin2hex(random_bytes(32)), (int) ($row['id'] ?? 0)]);
            }
        }
    } catch (Throwable $e) {
        // Ignore schema bootstrap failures so the app can continue running.
    }
}

function normalize_break_day_of_week(?string $value): ?int
{
    $raw = strtolower(trim((string) ($value ?? '')));
    if ($raw === '') {
        return null;
    }

    $dayMap = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    if (isset($dayMap[$raw])) {
        return $dayMap[$raw];
    }

    if (ctype_digit($raw)) {
        $day = (int) $raw;
        if ($day >= 1 && $day <= 7) {
            return $day;
        }
    }

    return null;
}

function format_break_day_label(?int $dayOfWeek): string
{
    return match ($dayOfWeek) {
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
        default => 'Every day',
    };
}

function normalize_break_time_value(?string $value): ?string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return null;
    }

    $formats = ['H:i', 'H:i:s'];
    foreach ($formats as $format) {
        $dateTime = DateTimeImmutable::createFromFormat($format, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dateTime && ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
            return $dateTime->format('H:i:s');
        }
    }

    return null;
}

function get_standard_breaks(?int $dayOfWeek = null, bool $includeInactive = false): array
{
    try {
        $db = get_db();
        $sql = 'SELECT id, day_of_week, start_time, end_time, is_active, created_at, updated_at FROM breaks';
        $params = [];

        if ($dayOfWeek === null) {
            $sql .= ' WHERE day_of_week IS NULL';
        } else {
            $sql .= ' WHERE (day_of_week IS NULL OR day_of_week = ?)';
            $params[] = $dayOfWeek;
        }

        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY (day_of_week IS NULL) DESC, start_time ASC, id ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}


function get_all_standard_breaks(bool $includeInactive = false): array
{
    static $cache = [];
    $key = $includeInactive ? 'all' : 'active';
    if (isset($cache[$key])) return $cache[$key];

    try {
        $db = get_db();
        $sql = 'SELECT id, day_of_week, start_time, end_time, is_active FROM breaks';
        if (!$includeInactive) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY (day_of_week IS NULL) DESC, day_of_week ASC, start_time ASC, id ASC';
        $stmt = $db->query($sql);
        $cache[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = [];
    }
    return $cache[$key];
}

function create_standard_break(?int $dayOfWeek, string $startTime, string $endTime, ?bool $isActive = true): ?int
{
    try {
        $normalizedStart = normalize_break_time_value($startTime);
        $normalizedEnd = normalize_break_time_value($endTime);
        if ($normalizedStart === null || $normalizedEnd === null) {
            return null;
        }

        $referenceDate = new DateTimeImmutable('2000-01-01');
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $referenceDate->format('Y-m-d') . ' ' . $normalizedStart);
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $referenceDate->format('Y-m-d') . ' ' . $normalizedEnd);
        if (!$startDate || !$endDate || $endDate <= $startDate) {
            return null;
        }

        $db = get_db();
        $stmt = $db->prepare('INSERT INTO breaks (day_of_week, start_time, end_time, is_active) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $dayOfWeek,
            $normalizedStart,
            $normalizedEnd,
            $isActive ? 1 : 0,
        ]);

        return (int) $db->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

function delete_standard_break(int $breakId): bool
{
    try {
        $db = get_db();
        $stmt = $db->prepare('DELETE FROM breaks WHERE id = ?');
        return $stmt->execute([$breakId]);
    } catch (Throwable $e) {
        return false;
    }
}

function find_overlapping_break(DateTimeImmutable $startDate, DateTimeImmutable $endDate): ?array
{
    try {
        $dayOfWeek = (int) $startDate->format('N');
        $breakRows = get_all_standard_breaks(false);

        foreach ($breakRows as $breakRow) {
            $breakDay = $breakRow['day_of_week'] !== null ? (int) $breakRow['day_of_week'] : null;
            if ($breakDay !== null && $breakDay !== $dayOfWeek) {
                continue;
            }

            $breakStartTime = substr((string) $breakRow['start_time'], 0, 8);
            $breakEndTime = substr((string) $breakRow['end_time'], 0, 8);
            $breakStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate->format('Y-m-d') . ' ' . $breakStartTime, $startDate->getTimezone());
            $breakEnd = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate->format('Y-m-d') . ' ' . $breakEndTime, $startDate->getTimezone());

            if (!$breakStart || !$breakEnd) {
                continue;
            }

            if ($endDate > $breakStart && $startDate < $breakEnd) {
                return [
                    'id' => (int) $breakRow['id'],
                    'day_of_week' => $breakDay,
                    'start_time' => $breakStartTime,
                    'end_time' => $breakEndTime,
                    'is_active' => (int) $breakRow['is_active'],
                    'break_start' => $breakStart->format('Y-m-d H:i:s'),
                    'break_end' => $breakEnd->format('Y-m-d H:i:s'),
                    'break_label' => format_break_day_label($breakDay),
                ];
            }
        }

        return null;
    } catch (Throwable $e) {
        return null;
    }
}

function get_standard_break_events(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
{
    try {
        $timezone = $rangeStart->getTimezone();
        $breakRows = get_all_standard_breaks(false);
        if (!$breakRows) {
            return [];
        }

        $events = [];
        $cursor = $rangeStart->setTime(0, 0, 0);
        $rangeEndDate = $rangeEnd->setTime(0, 0, 0);

        while ($cursor < $rangeEndDate) {
            $dayOfWeek = (int) $cursor->format('N');
            foreach ($breakRows as $breakRow) {
                $breakDay = $breakRow['day_of_week'] !== null ? (int) $breakRow['day_of_week'] : null;
                if ($breakDay !== null && $breakDay !== $dayOfWeek) {
                    continue;
                }

                $startTime = substr((string) $breakRow['start_time'], 0, 8);
                $endTime = substr((string) $breakRow['end_time'], 0, 8);
                $startDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $cursor->format('Y-m-d') . ' ' . $startTime, $timezone);
                $endDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $cursor->format('Y-m-d') . ' ' . $endTime, $timezone);
                if (!$startDate || !$endDate) {
                    continue;
                }

                if ($endDate <= $rangeStart || $startDate >= $rangeEnd) {
                    continue;
                }

                $events[] = [
                    'id' => 'break-' . $breakRow['id'] . '-' . $cursor->format('Ymd'),
                    'title' => 'Break',
                    'start' => $startDate->format('Y-m-d H:i:s'),
                    'end' => $endDate->format('Y-m-d H:i:s'),
                    'display' => 'background',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.22)',
                    'classNames' => ['standard-break-event'],
                    'extendedProps' => [
                        'break_id' => (int) $breakRow['id'],
                        'day_of_week' => $breakDay,
                    ],
                ];
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $events;
    } catch (Throwable $e) {
        return [];
    }
}

// User helpers
function get_user_by_email(string $email): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT id, email, password_hash, full_name, first_name, last_name, phone, role, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_user(string $email, string $password, string $full_name, string $role = 'admin', ?string $first_name = null, ?string $last_name = null, ?string $phone = null): int
{
    $db = get_db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (email, password_hash, full_name, first_name, last_name, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$email, $hash, $full_name, $first_name, $last_name, $phone, $role, 1]);
    return (int)$db->lastInsertId();
}

// Create user with explicit is_active flag
function create_user_with_active(string $email, string $password, string $full_name, string $role = 'admin', int $is_active = 1, ?string $first_name = null, ?string $last_name = null, ?string $phone = null): int
{
    $db = get_db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (email, password_hash, full_name, first_name, last_name, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$email, $hash, $full_name, $first_name, $last_name, $phone, $role, $is_active]);
    return (int)$db->lastInsertId();
}

function verify_user_credentials(string $email, string $password): ?array
{
    $user = get_user_by_email($email);
    if (!$user) return null;
    if (!password_verify($password, $user['password_hash'])) return null;
    if ((int)$user['is_active'] !== 1) return null;
    return $user;
}

function count_admin_users(): int
{
    $db = get_db();
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    return (int)$stmt->fetchColumn();
}

function get_max_admin_users(): int
{
    if (defined('MAX_ADMIN_USERS')) {
        return max(1, (int) MAX_ADMIN_USERS);
    }

    return 1;
}

function can_register_admin(): bool
{
    return count_admin_users() < get_max_admin_users();
}

function check_if_admin_exists(): bool
{
    return count_admin_users() > 0;
}

function admin_exists(): bool
{
    return check_if_admin_exists();
}

function is_logged_in(): bool
{
    init_session();
    return !empty($_SESSION['user'] ?? null);
}

function require_login(): void
{
    init_session();
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

// Clear session and cookies to log the user out
function logout_user(): void
{
    init_session();
    // Clear session array
    $_SESSION = [];
    // Remove session cookie if used
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'] ?? false, $params['httponly'] ?? true);
    }
    // Destroy session
    @session_destroy();
}

// CSRF helpers
function csrf_token(): string
{
    init_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    init_session();
    if (empty($token) || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

// Settings helpers
function get_setting(string $key, $default = null)
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && isset($row['setting_value'])) return $row['setting_value'];
    } catch (Throwable $e) {
        // ignore and return default
    }
    return $default;
}

function set_setting(string $key, $value): bool
{
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    return (bool)$stmt->execute([$key, $value]);
}

function get_site_name(): string
{
    $appName = APP_NAME ?? 'Boomy Appointments';
    $siteName = trim((string) get_setting('site_name', $appName));
    if ($siteName === '') {
        $siteName = $appName;
    }

    return $siteName;
}

function get_support_contact_message(?string $siteName = null): string
{
    $resolvedSiteName = trim((string) ($siteName ?? ''));
    if ($resolvedSiteName === '') {
        $resolvedSiteName = get_site_name();
    }

    $supportEmail = trim((string) get_setting('organization_email', ''));
    $supportNumber = trim((string) get_setting('organization_number', ''));

    if ($supportEmail !== '' && $supportNumber !== '') {
        return 'Please contact ' . $resolvedSiteName . ' via ' . $supportEmail . ' or ' . $supportNumber . ' for further help.';
    }

    if ($supportEmail !== '') {
        return 'Please contact ' . $resolvedSiteName . ' via ' . $supportEmail . ' for further help.';
    }

    if ($supportNumber !== '') {
        return 'Please contact ' . $resolvedSiteName . ' via ' . $supportNumber . ' for further help.';
    }

    return 'Please contact ' . $resolvedSiteName . ' for extra help.';
}

// Return server time formatted. Attempts to use server time; if unavailable uses timezone from config
function get_time(?string $format = null): string
{
    // Prefer using PHP's DateTime with the server timezone
    try {
        $tz = @date_default_timezone_get() ?: 'UTC';
        $dt = new DateTimeImmutable('now', new DateTimeZone($tz));
    } catch (Throwable $e) {
        // Fallback to config timezone if set
        $fallback = 'UTC';
        if (defined('TZ') && TZ) {
            $fallback = TZ;
        } elseif (defined('APP_TIMEZONE')) {
            $fallback = APP_TIMEZONE;
        } else {
            // try to parse configured timezone from config.php by re-reading, but keep UTC otherwise
            $fallback = 'UTC';
        }
        $dt = new DateTimeImmutable('now', new DateTimeZone($fallback));
    }

    if ($format) {
        // support both PHP date formats and common tokens
        return $dt->format($format);
    }
    return $dt->format(DateTime::ATOM);
}

function get_app_timezone_name(): string
{
    $timezone = @date_default_timezone_get();
    if ($timezone) {
        return $timezone;
    }

    if (defined('TZ') && TZ) {
        return TZ;
    }

    if (defined('APP_TIMEZONE') && APP_TIMEZONE) {
        return APP_TIMEZONE;
    }

    return 'UTC';
}

function get_app_root_url(): string
{
    $configured = trim((string) get_setting('root_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $scheme = 'http';
    $forwarded_proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwarded_proto === 'https') {
        $scheme = 'https';
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''), '/');
    if ($host === '') {
        $host = 'localhost';
    }

    $script_dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $script_dir = trim($script_dir, '/');

    $root_url = $scheme . '://' . $host;
    if ($script_dir !== '' && $script_dir !== '.') {
        $root_url .= '/' . $script_dir;
    }

    return rtrim($root_url, '/');
}

function build_app_url(string $path, array $query = []): string
{
    $url = get_app_root_url() . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

// Email validation
function validate_email_format(string $email): bool
{
    // Basic x@y.z format check
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function get_email_domain_whitelist(): array
{
    $setting = get_setting('email_domain_whitelist', '');
    if (!$setting) return [];
    return array_filter(array_map('trim', explode("\n", $setting)));
}

// Get the client's IP address (tries common proxy headers)
function get_client_ip(): string
{
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // may contain comma-separated list of IPs; take the first public one
        $list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $list = array_map('trim', $list);
        // return the last (closest to client) or first? use first non-private
        foreach ($list as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
                break;
            }
        }
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return (string)$ip;
}

// Insert a login attempt record
function insert_login_attempt(?string $username, bool $success): void
{
    try {
        $db = get_db();
        $stmt = $db->prepare('INSERT INTO login_attempts (ip_address, username, success, user_agent) VALUES (?, ?, ?, ?)');
        $ip = get_client_ip();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt->execute([$ip ?: null, $username ?: null, $success ? 1 : 0, $ua]);
    } catch (Throwable $e) {
        // swallow errors; logging should not break auth
    }
}

function get_email_domain_blacklist(): array
{
    $setting = get_setting('email_domain_blacklist', '');
    if (!$setting) return [];
    return array_filter(array_map('trim', explode("\n", $setting)));
}

function is_email_whitelist_enabled(): bool
{
    $setting = get_setting('email_whitelist_enabled', '0');
    return (bool) (int) $setting;
}

function validate_email(string $email, bool $enforce_domain_rules = true): string|bool
{
    $email = trim($email);
    if (!validate_email_format($email)) {
        return 'Invalid email format';
    }

    if (!$enforce_domain_rules) {
        return true;
    }

    list($local, $domain) = explode('@', $email);

    $whitelist = get_email_domain_whitelist();
    $blacklist = get_email_domain_blacklist();

    if (is_email_whitelist_enabled()) {
        if (!in_array($domain, $whitelist, true)) {
            return 'Email domain not allowed';
        }
    } else {
        if (in_array($domain, $blacklist, true)) {
            return 'Email domain is blocked';
        }
    }

    return true;
}

// Password validation (registration only)
function get_password_requirements(): array
{
    return [
        'min_length' => (int) get_setting('password_min_length', '8'),
        'max_length' => (int) get_setting('password_max_length', '128'),
        'require_lowercase' => (bool) (int) get_setting('password_require_lowercase', '1'),
        'require_uppercase' => (bool) (int) get_setting('password_require_uppercase', '1'),
        'require_number' => (bool) (int) get_setting('password_require_number', '1'),
        'require_symbol' => (bool) (int) get_setting('password_require_symbol', '1'),
    ];
}

function validate_password(string $password): string|bool
{
    $req = get_password_requirements();
    $len = strlen($password);

    if ($len < $req['min_length']) {
        return "Password must be at least {$req['min_length']} characters";
    }
    if ($len > $req['max_length']) {
        return "Password must not exceed {$req['max_length']} characters";
    }

    if ($req['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter';
    }
    if ($req['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter';
    }
    if ($req['require_number'] && !preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number';
    }
    if ($req['require_symbol'] && !preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
        return 'Password must contain at least one symbol';
    }

    return true;
}

// Verification tokens (password reset, appointment confirmation, etc.)
function create_verification_token(int $user_id, string $task = 'password_reset', int $expiry_minutes = 60): string
{
    $db = get_db();
    $code = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + ($expiry_minutes * 60));
    
    // Delete old tokens for this user+task
    $stmt = $db->prepare('DELETE FROM verification WHERE user_id = ? AND task = ?');
    $stmt->execute([$user_id, $task]);
    
    // Create new token
    $stmt = $db->prepare('INSERT INTO verification (user_id, code, task, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user_id, $code, $task, $expires_at]);
    
    return $code;
}

function validate_verification_token(string $code, string $task = 'password_reset'): ?int
{
    $db = get_db();
    $stmt = $db->prepare('SELECT user_id FROM verification WHERE code = ? AND task = ? AND (expires_at IS NULL OR expires_at > NOW())');
    $stmt->execute([$code, $task]);
    $row = $stmt->fetch();
    return $row ? (int)$row['user_id'] : null;
}

function get_user_by_verification_code(string $code): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT u.id, u.email, u.password_hash, u.full_name, u.first_name, u.last_name, u.phone, u.role, u.is_active FROM users u JOIN verification v ON u.id = v.user_id WHERE v.code = ? AND v.task = ? AND (v.expires_at IS NULL OR v.expires_at > NOW())');
    $stmt->execute([$code, 'password_reset']);
    $row = $stmt->fetch();
    return $row ?: null;
}

function delete_verification_token(string $code): void
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM verification WHERE code = ?');
    $stmt->execute([$code]);
}

// Collect email / SMTP diagnostics without attempting to send
function email_diagnostics(string $to_email, string $subject): array
{
    $smtp_host = get_setting('smtp_host');
    $smtp_port = (int) get_setting('smtp_port', '587');
    $smtp_encryption = get_setting('smtp_encryption', 'tls');
    $smtp_username = get_setting('smtp_username');
    $mail_from_address = get_setting('mail_from_address');
    $mail_from_name = get_setting('mail_from_name', APP_NAME ?? 'Boomy Appointments');

    $debug = [];
    $debug[] = "SMTP Host: " . ($smtp_host ?: '(not set)');
    $debug[] = "SMTP Port: $smtp_port";
    $debug[] = "SMTP Encryption: $smtp_encryption";
    $debug[] = "From Address: " . ($mail_from_address ?: '(not set)');
    $debug[] = "From Name: $mail_from_name";
    $debug[] = "To: $to_email";
    $debug[] = "Subject: $subject";

    $debug[] = "PHP Version: " . PHP_VERSION;
    $debug[] = "OpenSSL extension: " . (extension_loaded('openssl') ? 'loaded' : 'NOT loaded');
    $debug[] = "cURL extension: " . (extension_loaded('curl') ? 'loaded' : 'NOT loaded');
    $debug[] = "Filesystem writable check (phpmailer/): " . (is_writable(__DIR__ . '/phpmailer') ? 'writable' : 'not writable or not present');

    if ($smtp_username) {
        $len = strlen($smtp_username);
        $masked = $len > 3 ? substr($smtp_username, 0, 2) . str_repeat('*', max(0, $len - 4)) . substr($smtp_username, -2) : str_repeat('*', $len);
        $debug[] = "SMTP Username: $masked (length=$len)";
    } else {
        $debug[] = "SMTP Username: (not set)";
    }

    if ($smtp_host) {
        $resolved = gethostbyname($smtp_host);
        $debug[] = "DNS lookup for $smtp_host -> $resolved";
    }

    if ($smtp_host && $smtp_port) {
        $errno = 0; $errstr = '';
        $conn = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 5);
        if ($conn) {
            $debug[] = "TCP connect to $smtp_host:$smtp_port succeeded";
            fclose($conn);
        } else {
            $debug[] = "TCP connect to $smtp_host:$smtp_port failed: " . ($errstr ?: 'unknown error') . " (errno=$errno)";
        }
    }

    return $debug;
}

function send_password_reset_email(string $email, string $code): bool
{
    $user = get_user_by_email($email);
    if (!$user) return false;
    
    $app_name = APP_NAME ?? 'Boomy Appointments';
    $reset_url = build_app_url('reset_password.php', ['code' => $code]);
    
    $subject = "Password Reset - $app_name";
    $body_html = "
        <h2>Password Reset Request</h2>
        <p>Hi {$user['full_name']},</p>
        <p>You requested to reset your password. Click the link below to create a new password:</p>
        <p><a href=\"{$reset_url}\" style=\"background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;\">Reset Password</a></p>
        <p>Or copy this link: {$reset_url}</p>
        <p>This link expires in 60 minutes.</p>
        <p>If you didn't request this, you can ignore this email.</p>
        <p>Best regards,<br>$app_name</p>
    ";
    
    $body_plain = "
Password Reset Request

Hi {$user['full_name']},

You requested to reset your password. Visit this link to create a new password:
{$reset_url}

This link expires in 60 minutes.

If you didn't request this, you can ignore this email.

Best regards,
$app_name
    ";
    
    return send_email($email, $subject, $body_html, $body_plain);
}

function send_account_confirmation_email(string $email, string $code): bool
{
    $user = get_user_by_email($email);
    if (!$user) return false;
    $app_name = APP_NAME ?? 'Boomy Appointments';
    $confirm_url = build_app_url('confirm_account.php', ['code' => $code]);
    $subject = "Confirm your account - $app_name";
    $body_html = "
        <h2>Confirm Your Account</h2>
        <p>Hi {$user['full_name']},</p>
        <p>Please confirm your account by clicking the button below:</p>
        <p><a href=\"{$confirm_url}\" style=\"background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;\">Confirm Account</a></p>
        <p>Or copy this link: {$confirm_url}</p>
        <p>If you didn't sign up, ignore this email.</p>
        <p>Best regards,<br>$app_name</p>
    ";
    $body_plain = "Confirm Your Account\n\nHi {$user['full_name']},\n\nPlease confirm your account by visiting this link: {$confirm_url}\n\nIf you didn't sign up, ignore this email.\n\nBest regards,\n$app_name";
    return send_email($email, $subject, $body_html, $body_plain);
}

// Return whether SMTP / PHPMailer configuration appears complete (basic check)
function email_is_configured(): bool
{
    $smtp_host = get_setting('smtp_host');
    $smtp_username = get_setting('smtp_username');
    $smtp_password = get_setting('smtp_password');
    $mail_from = get_setting('mail_from_address');

    if (!$smtp_host || !$smtp_username || !$smtp_password || !$mail_from) return false;
    return true;
}

function send_email(string $to_email, string $subject, string $body_html, string $body_plain = ''): array|bool
{
    // If basic email configuration isn't present, silently skip sending
    if (!email_is_configured()) {
        return false;
    }
    // Get SMTP settings from database
    $smtp_host = get_setting('smtp_host');
    $smtp_port = (int) get_setting('smtp_port', '587');
    $smtp_encryption = get_setting('smtp_encryption', 'tls');
    $smtp_username = get_setting('smtp_username');
    $smtp_password = get_setting('smtp_password');
    $mail_from_address = get_setting('mail_from_address');
    $mail_from_name = get_setting('mail_from_name', APP_NAME ?? 'Boomy Appointments');
    
    $debug = [];
    $debug[] = "SMTP Host: " . ($smtp_host ?: '(not set)');
    $debug[] = "SMTP Port: $smtp_port";
    $debug[] = "SMTP Encryption: $smtp_encryption";
    $debug[] = "From Address: " . ($mail_from_address ?: '(not set)');
    $debug[] = "From Name: $mail_from_name";
    $debug[] = "To: $to_email";
    $debug[] = "Subject: $subject";

    // Additional diagnostics
    $debug[] = "PHP Version: " . PHP_VERSION;
    $debug[] = "OpenSSL extension: " . (extension_loaded('openssl') ? 'loaded' : 'NOT loaded');
    $debug[] = "cURL extension: " . (extension_loaded('curl') ? 'loaded' : 'NOT loaded');
    $debug[] = "Filesystem writable check (phpmailer/): " . (is_writable(__DIR__ . '/phpmailer') ? 'writable' : 'not writable or not present');

    // Mask SMTP username for safety (show length and first/last chars)
    if ($smtp_username) {
        $len = strlen($smtp_username);
        $masked = $len > 3 ? substr($smtp_username, 0, 2) . str_repeat('*', max(0, $len - 4)) . substr($smtp_username, -2) : str_repeat('*', $len);
        $debug[] = "SMTP Username: $masked (length=$len)";
    } else {
        $debug[] = "SMTP Username: (not set)";
    }

    // Quick DNS resolution check
    if ($smtp_host) {
        $resolved = gethostbyname($smtp_host);
        $debug[] = "DNS lookup for $smtp_host -> $resolved";
    }

    // Quick TCP connectivity check (non-blocking short timeout)
    if ($smtp_host && $smtp_port) {
        $errno = 0; $errstr = '';
        $conn = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 5);
        if ($conn) {
            $debug[] = "TCP connect to $smtp_host:$smtp_port succeeded";
            fclose($conn);
        } else {
            $debug[] = "TCP connect to $smtp_host:$smtp_port failed: " . ($errstr ?: 'unknown error') . " (errno=$errno)";
        }
    }
    
    // Check if PHPMailer is available
    if (!file_exists(__DIR__ . '/phpmailer/PHPMailer.php')) {
        $debug[] = "❌ ERROR: PHPMailer library not found at /phpmailer/PHPMailer.php";
        $debug[] = "Download PHPMailer files:";
        $debug[] = "  - wget https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/PHPMailer.php -O phpmailer/PHPMailer.php";
        $debug[] = "  - wget https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/SMTP.php -O phpmailer/SMTP.php";
        $debug[] = "  - wget https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/Exception.php -O phpmailer/Exception.php";
        return $debug;
    }
    
    // Validate SMTP settings
    if (!$smtp_host) {
        $debug[] = "❌ ERROR: SMTP Host not configured";
        return $debug;
    }
    if (!$mail_from_address) {
        $debug[] = "❌ ERROR: From email address not configured";
        return $debug;
    }
    
    try {
        $debug[] = "✓ PHPMailer library found";
        
        require_once __DIR__ . '/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/SMTP.php';
        require_once __DIR__ . '/phpmailer/Exception.php';
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $debug[] = "✓ PHPMailer instantiated";
        
        // Enable debug output
        $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function($str, $level) use (&$debug) {
            $debug[] = "🔍 SMTP Debug: $str";
        };
        
        $debug[] = "Connecting to $smtp_host:$smtp_port (encryption: $smtp_encryption)...";
        
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->Port = $smtp_port;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = $smtp_encryption === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        
        $mail->setFrom($mail_from_address, $mail_from_name);
        $mail->addAddress($to_email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body_html;
        if ($body_plain) {
            $mail->AltBody = $body_plain;
        }
        
        $debug[] = "Sending email...";
        if ($mail->send()) {
            $debug[] = "✓ Email sent successfully";
            return true;
        } else {
            $debug[] = "❌ ERROR: " . $mail->ErrorInfo;
            return $debug;
        }
    } catch (\Exception $e) {
        $debug[] = "❌ EXCEPTION: " . $e->getMessage();
        return $debug;
    }
}

function email_transport_is_ready(): bool
{
    return email_is_configured()
        && file_exists(__DIR__ . '/phpmailer/PHPMailer.php')
        && file_exists(__DIR__ . '/phpmailer/SMTP.php')
        && file_exists(__DIR__ . '/phpmailer/Exception.php');
}

function format_app_datetime(string $value, ?string $format = null): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }

    $timezone = new DateTimeZone(get_app_timezone_name());
    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, $timezone);
    if (!$dateTime) {
        try {
            $dateTime = new DateTimeImmutable($raw, $timezone);
        } catch (Throwable $e) {
            return $raw;
        }
    }

    $dateFormat = $format ?: get_setting('date_format', 'd/m/Y H:i');
    return $dateTime->format($dateFormat);
}

function format_appointment_person_name(?array $person): string
{
    if (!$person) {
        return '';
    }

    $firstName = trim((string) ($person['first_name'] ?? ''));
    $lastName = trim((string) ($person['last_name'] ?? ''));
    $preferred = trim($firstName . ' ' . $lastName);
    if ($preferred !== '') {
        return $preferred;
    }

    $fullName = trim((string) ($person['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    return trim((string) ($person['email'] ?? ''));
}

function send_appointment_confirmation_email(int $appointmentId): array
{
    if (!email_transport_is_ready()) {
        return ['ok' => false, 'error' => 'Mail settings not configured'];
    }

    $appointment = get_appointment_by_id($appointmentId);
    if (!$appointment) {
        return ['ok' => false, 'error' => 'Appointment not found'];
    }

    $customer = get_customer_by_id((int) ($appointment['customer_id'] ?? 0));
    if (!$customer) {
        return ['ok' => false, 'error' => 'Customer not found'];
    }

    $customerEmail = trim((string) ($customer['email'] ?? ''));
    if ($customerEmail === '') {
        return ['ok' => false, 'error' => 'Email not recorded for this customer'];
    }

    $provider = get_user_by_id((int) ($appointment['provider_id'] ?? 0));
    $appointmentType = get_appointment_type_by_id((int) ($appointment['appointment_type_id'] ?? 0));
    $hashKey = trim((string) ($appointment['hash_key'] ?? ''));

    if ($hashKey === '') {
        return ['ok' => false, 'error' => 'Appointment link is missing'];
    }

    $siteName = trim((string) get_setting('site_name', APP_NAME ?? 'Boomy Appointments'));
    if ($siteName === '') {
        $siteName = APP_NAME ?? 'Boomy Appointments';
    }

    $appointmentUrl = build_app_url('confirm_appointment.php', ['code' => $hashKey]);
    $appointmentTypeName = trim((string) ($appointmentType['name'] ?? 'Appointment'));
    $providerName = format_appointment_person_name($provider);
    $startTime = format_app_datetime((string) ($appointment['start_time'] ?? ''));
    $endTime = format_app_datetime((string) ($appointment['end_time'] ?? ''));
    $customerName = trim((string) ($customer['full_name'] ?? ''));
    $customerMobile = trim((string) ($customer['mobile'] ?? ''));
    $providerDisplay = $providerName !== '' ? $providerName : 'Not recorded';
    $customerMobileDisplay = $customerMobile !== '' ? $customerMobile : 'Not recorded';

    $subject = 'Your appointment has been successfully booked for ' . $siteName;
    $body_html = '<h2>Your appointment has been successfully booked for ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p>Thank you for arranging an appointment with us. Below you can see the appointment details. Make changes by clicking the appointment link.</p>'
        . '<h3>Appointment Details</h3>'
        . '<ul>'
        . '<li><strong>Appointment type:</strong> ' . htmlspecialchars($appointmentTypeName, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Provider:</strong> ' . htmlspecialchars($providerDisplay, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Start time:</strong> ' . htmlspecialchars($startTime, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>End time:</strong> ' . htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>'
        . '<h3>Customer details</h3>'
        . '<ul>'
        . '<li><strong>Customer full name:</strong> ' . htmlspecialchars($customerName !== '' ? $customerName : 'Not recorded', ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Mobile number:</strong> ' . htmlspecialchars($customerMobileDisplay, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>'
        . '<p><a href="' . htmlspecialchars($appointmentUrl, ENT_QUOTES, 'UTF-8') . '" style="background-color:#2457ff;color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:999px;display:inline-block;font-weight:700;">Confirm or modify your appointment</a></p>'
        . '<p>Or copy this link: ' . htmlspecialchars($appointmentUrl, ENT_QUOTES, 'UTF-8') . '</p>';

    $body_plain = "Your appointment has been successfully booked for {$siteName}\n\n"
        . "Thank you for arranging an appointment with us. Below you can see the appointment details. Make changes by clicking the appointment link.\n\n"
        . "Appointment Details\n"
        . "Appointment type: {$appointmentTypeName}\n"
        . "Provider: {$providerDisplay}\n"
        . "Start time: {$startTime}\n"
        . "End time: {$endTime}\n\n"
        . "Customer details:\n"
        . "Customer full name: " . ($customerName !== '' ? $customerName : 'Not recorded') . "\n"
        . "Mobile number: {$customerMobileDisplay}\n\n"
        . "Confirm or modify your appointment: {$appointmentUrl}\n";

    $sendResult = send_email($customerEmail, $subject, $body_html, $body_plain);
    if ($sendResult === true) {
        return ['ok' => true, 'sent' => true];
    }

    return ['ok' => false, 'error' => 'Failed to send confirmation email'];
}

// Customer settings and validation helpers
function get_customer_field_settings(): array
{
    return [
        'first_name' => [
            'display' => (bool) (int) get_setting('booking_firstname_display', '1'),
            'required' => (bool) (int) get_setting('booking_firstname_required', '1'),
            'letters_only' => (bool) (int) get_setting('booking_firstname_letters_only', '0'),
            'min_length' => (int) get_setting('booking_firstname_min', '2'),
            'max_length' => (int) get_setting('booking_firstname_max', '50'),
        ],
        'last_name' => [
            'display' => (bool) (int) get_setting('booking_lastname_display', '1'),
            'required' => (bool) (int) get_setting('booking_lastname_required', '1'),
            'letters_only' => (bool) (int) get_setting('booking_lastname_letters_only', '0'),
            'min_length' => (int) get_setting('booking_lastname_min', '2'),
            'max_length' => (int) get_setting('booking_lastname_max', '50'),
        ],
        'middle_name' => [
            'display' => (bool) (int) get_setting('booking_middlename_display', '0'),
            'required' => (bool) (int) get_setting('booking_middlename_required', '0'),
            'letters_only' => (bool) (int) get_setting('booking_middlename_letters_only', '0'),
            'min_length' => (int) get_setting('booking_middlename_min', '2'),
            'max_length' => (int) get_setting('booking_middlename_max', '50'),
        ],
        'email' => [
            'display' => (bool) (int) get_setting('booking_email_display', '1'),
            'required' => (bool) (int) get_setting('booking_email_required', '1'),
        ],
        'mobile' => [
            'display' => (bool) (int) get_setting('booking_phone_display', '1'),
            'required' => (bool) (int) get_setting('booking_phone_required', '0'),
            'numbers_only' => (bool) (int) get_setting('booking_phone_numbers_only', '1'),
            'min_length' => (int) get_setting('booking_phone_min', '10'),
            'max_length' => (int) get_setting('booking_phone_max', '20'),
        ],
        'date_of_birth' => [
            'display' => (bool) (int) get_setting('booking_dob_display', '1'),
            'required' => (bool) (int) get_setting('booking_dob_required', '0'),
        ],
        'gender' => [
            'display' => (bool) (int) get_setting('booking_gender_display', '1'),
            'required' => (bool) (int) get_setting('booking_gender_required', '0'),
        ],
    ];
}

function get_booking_calendar_settings(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [
        'deadline_hours' => (int) get_setting('booking_deadline_hours', '24'),
        'calendar_height' => normalize_booking_calendar_height_setting(get_setting('booking_calendar_height', '1800')),
        'week_start_day' => normalize_booking_week_start_day_setting(get_setting('booking_week_start_day', '0')),
        'show_monday' => (bool) (int) get_setting('booking_show_monday', '1'),
        'show_tuesday' => (bool) (int) get_setting('booking_show_tuesday', '1'),
        'show_wednesday' => (bool) (int) get_setting('booking_show_wednesday', '1'),
        'show_thursday' => (bool) (int) get_setting('booking_show_thursday', '1'),
        'show_friday' => (bool) (int) get_setting('booking_show_friday', '1'),
        'show_saturday' => (bool) (int) get_setting('booking_show_saturday', '0'),
        'show_sunday' => (bool) (int) get_setting('booking_show_sunday', '0'),
        'start_time' => (string) get_setting('booking_start_time', '09:00'),
        'end_time' => (string) get_setting('booking_end_time', '21:00'),
        'show_now_indicator' => (bool) (int) get_setting('booking_show_now_indicator', '1'),
        'snap_minutes' => (int) get_setting('booking_snap_minutes', '15'),
    ];
    return $cache;
}
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

function normalize_booking_week_start_day_setting($value): int
{
    $day = (int) trim((string) ($value ?? '0'));
    if ($day < 0 || $day > 6) {
        return 0;
    }

    return $day;
}

function get_appointment_types(bool $activeOnly = false): array
{
    try {
        $db = get_db();
        $sql = 'SELECT id, name, duration_minutes, color_hex, is_active FROM appointment_types';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function validate_customer_field(string $fieldName, ?string $value): ?string
{
    $settings = get_customer_field_settings();
    if (!isset($settings[$fieldName])) {
        return 'Unknown field: ' . $fieldName;
    }

    $cfg = $settings[$fieldName];
    $value = trim((string) ($value ?? ''));

    if (empty($value) && $cfg['required']) {
        return ucwords(str_replace('_', ' ', $fieldName)) . ' is required';
    }

    if (empty($value)) {
        return null;
    }

    if (in_array($fieldName, ['first_name', 'last_name', 'middle_name'], true)) {
        if ($cfg['letters_only'] && !preg_match('/^[a-zA-Z\s-\']+$/', $value)) {
            return ucwords(str_replace('_', ' ', $fieldName)) . ' must contain only letters, spaces, hyphens, and apostrophes';
        }
        if (strlen($value) < $cfg['min_length']) {
            return ucwords(str_replace('_', ' ', $fieldName)) . ' must be at least ' . $cfg['min_length'] . ' characters';
        }
        if (strlen($value) > $cfg['max_length']) {
            return ucwords(str_replace('_', ' ', $fieldName)) . ' must not exceed ' . $cfg['max_length'] . ' characters';
        }
    }

    if ($fieldName === 'email') {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'Email address is invalid';
        }
    }

    if ($fieldName === 'mobile') {
        if ($cfg['numbers_only'] && !preg_match('/^[0-9\s\-\+\(\)]+$/', $value)) {
            return 'Phone number can only contain numbers, spaces, hyphens, plus sign, and parentheses';
        }
        if (strlen($value) < $cfg['min_length']) {
            return 'Phone number must be at least ' . $cfg['min_length'] . ' characters';
        }
        if (strlen($value) > $cfg['max_length']) {
            return 'Phone number must not exceed ' . $cfg['max_length'] . ' characters';
        }
    }

    if ($fieldName === 'date_of_birth') {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return 'Date of birth must be a valid date';
        }
    }

    if ($fieldName === 'gender') {
        $allowedGenders = ['male', 'female', 'other', 'unknown'];
        if (!in_array(strtolower($value), $allowedGenders, true)) {
            return 'Gender must be one of male, female, other, or unknown';
        }
    }

    return null;
}

function get_customer_missing_required_fields(array $data): array
{
    $settings = get_customer_field_settings();
    $missing = [];

    foreach ($settings as $fieldName => $cfg) {
        if ($cfg['required']) {
            $value = trim((string) ($data[$fieldName] ?? ''));
            if (empty($value)) {
                $missing[] = $fieldName;
            }
        }
    }

    return $missing;
}

function normalize_customer_date_of_birth(?string $value): ?string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
        return null;
    }

    return $date->format('Y-m-d');
}

function normalize_customer_gender(?string $value): ?string
{
    $raw = strtolower(trim((string) ($value ?? '')));
    if ($raw === '') {
        return null;
    }

    $allowed = ['male', 'female', 'other', 'unknown'];
    return in_array($raw, $allowed, true) ? $raw : null;
}


function create_customer(string $fullName, ?string $email, ?string $mobile, ?string $dateOfBirth = null, ?string $gender = null): ?int
{
    try {
        $db = get_db();
        $normalizedDateOfBirth = normalize_customer_date_of_birth($dateOfBirth);
        $normalizedGender = normalize_customer_gender($gender);

        $columns = ['full_name', 'email', 'mobile'];
        $values = [$fullName, $email ?: '', $mobile ?: ''];

        if ($normalizedDateOfBirth !== null) {
            $columns[] = 'date_of_birth';
            $values[] = $normalizedDateOfBirth;
        }

        if ($normalizedGender !== null) {
            $columns[] = 'gender';
            $values[] = $normalizedGender;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $db->prepare('INSERT INTO customers (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
        $stmt->execute($values);

        return (int) $db->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

function update_customer(int $customerId, string $fullName, ?string $email, ?string $mobile, ?string $dateOfBirth = null, ?string $gender = null): bool
{
    try {
        $db = get_db();
        if (!get_customer_by_id($customerId)) {
            return false;
        }

        $normalizedDateOfBirth = normalize_customer_date_of_birth($dateOfBirth);
        $normalizedGender = normalize_customer_gender($gender);

        $stmt = $db->prepare('UPDATE customers SET full_name = ?, email = ?, mobile = ?, date_of_birth = ?, gender = ? WHERE id = ?');
        return $stmt->execute([
            $fullName,
            $email ?: '',
            $mobile ?: '',
            $normalizedDateOfBirth,
            $normalizedGender ?? 'unknown',
            $customerId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function get_customer_by_email(string $email): ?array
{
    try {
        $db = get_db();
        try {
            $stmt = $db->prepare('SELECT id, email, mobile, full_name, date_of_birth, gender, date_added FROM customers WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            $stmt = $db->prepare('SELECT id, email, mobile, full_name FROM customers WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            return $stmt->fetch() ?: null;
        }
    } catch (Throwable $e) {
        return null;
    }
}

function get_user_by_id(int $id): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, email, password_hash, full_name, first_name, last_name, phone, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function get_customer_by_id(int $id): ?array
{
    try {
        $db = get_db();
        try {
            $stmt = $db->prepare('SELECT id, email, mobile, full_name, date_of_birth, gender, date_added FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            $stmt = $db->prepare('SELECT id, email, mobile, full_name FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        }
    } catch (Throwable $e) {
        return null;
    }
}

function get_appointment_type_by_id(int $id): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, name, duration_minutes, color_hex, is_active FROM appointment_types WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function get_appointment_by_id(int $id): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, customer_id, provider_id, appointment_type_id, start_time, end_time, status, notes, hash_key, created_at, updated_at FROM appointments WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function get_appointment_by_hash_key(string $hashKey): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, customer_id, provider_id, appointment_type_id, start_time, end_time, status, notes, hash_key, created_at, updated_at FROM appointments WHERE hash_key = ? LIMIT 1');
        $stmt->execute([$hashKey]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function time_string_to_seconds(string $timeValue): ?int
{
    $parts = explode(':', trim($timeValue));
    if (count($parts) < 2) {
        return null;
    }

    $hours = is_numeric($parts[0]) ? (int) $parts[0] : null;
    $minutes = is_numeric($parts[1]) ? (int) $parts[1] : null;
    $seconds = isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : 0;

    if ($hours === null || $minutes === null) {
        return null;
    }

    return ($hours * 3600) + ($minutes * 60) + $seconds;
}

function is_booking_day_enabled(DateTimeImmutable $date, array $bookingSettings): bool
{
    $map = [
        1 => 'show_monday',
        2 => 'show_tuesday',
        3 => 'show_wednesday',
        4 => 'show_thursday',
        5 => 'show_friday',
        6 => 'show_saturday',
        7 => 'show_sunday',
    ];

    $dayOfWeek = (int) $date->format('N');
    $settingKey = $map[$dayOfWeek] ?? null;
    if ($settingKey === null) {
        return false;
    }

    return !empty($bookingSettings[$settingKey]);
}

function validate_appointment_window(int $providerId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, ?int $excludeAppointmentId = null): ?array
{
    $bookingSettings = get_booking_calendar_settings();
    if (!is_booking_day_enabled($startDate, $bookingSettings)) {
        return ['error' => 'outside_booking_hours', 'message' => 'Selected day is not enabled for bookings'];
    }

    $startSeconds = time_string_to_seconds($startDate->format('H:i:s'));
    $endSeconds = time_string_to_seconds($endDate->format('H:i:s'));
    $visibleStartSeconds = time_string_to_seconds((string) $bookingSettings['start_time']);
    $visibleEndSeconds = time_string_to_seconds((string) $bookingSettings['end_time']);

    if ($startSeconds === null || $endSeconds === null || $visibleStartSeconds === null || $visibleEndSeconds === null) {
        return ['error' => 'outside_booking_hours', 'message' => 'Unable to validate booking time window'];
    }
    if ($startSeconds < $visibleStartSeconds || $endSeconds > $visibleEndSeconds) {
        return ['error' => 'outside_booking_hours', 'message' => 'Selected time is outside the configured booking hours'];
    }

    if ($conflict = find_overlapping_appointment($providerId, $startDate, $endDate, $excludeAppointmentId)) {
        return ['error' => 'overlap', 'conflict' => $conflict];
    }
    
    if ($breakConflict = find_overlapping_break($startDate, $endDate)) {
        return ['error' => 'break_overlap', 'conflict' => $breakConflict];
    }

    // --- ADD THIS UNAVAILABILITY CHECK INTERCEPTION TRACK ---
    if ($unavailConflict = check_provider_unavailability($providerId, $startDate, $endDate)) {
        return [
            'error' => 'provider_unavailable',
            'message' => '🚨 ' . htmlspecialchars($unavailConflict['reason'])
        ];
    }
    // ---------------------------------------------------------

    return null;
}

function find_overlapping_appointment(int $providerId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, ?int $excludeAppointmentId = null): ?array
{
    try {
        $db = get_db();
        $sql = 'SELECT a.id, a.customer_id, a.provider_id, a.appointment_type_id, a.start_time, a.end_time, a.status, c.full_name AS customer_name
                FROM appointments a
                LEFT JOIN customers c ON c.id = a.customer_id
                WHERE a.provider_id = ?
                  AND a.start_time < ?
                  AND a.end_time > ?';
        $params = [
            $providerId,
            $endDate->format('Y-m-d H:i:s'),
            $startDate->format('Y-m-d H:i:s'),
        ];

        if ($excludeAppointmentId !== null && $excludeAppointmentId > 0) {
            $sql .= ' AND a.id <> ?';
            $params[] = $excludeAppointmentId;
        }

        $sql .= ' ORDER BY a.start_time ASC LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function create_appointment(int $customerId, int $providerId, int $appointmentTypeId, string $startTime, ?string $status = null, ?string $endTime = null, ?string $notes = null): ?array
{
    try {
        $appointmentType = get_appointment_type_by_id($appointmentTypeId);
        if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
            return null;
        }

        $timezone = new DateTimeZone(get_app_timezone_name());
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startTime, $timezone);
        if (!$startDate) {
            $startDate = new DateTimeImmutable($startTime, $timezone);
        }
        $durationMinutes = max(1, (int) ($appointmentType['duration_minutes'] ?? 30));
        $resolvedEndDate = null;

        $endTimeValue = trim((string) ($endTime ?? ''));
        if ($endTimeValue !== '') {
            $resolvedEndDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endTimeValue, $timezone);
            if (!$resolvedEndDate) {
                $resolvedEndDate = new DateTimeImmutable($endTimeValue, $timezone);
            }
        }

        $endDate = $resolvedEndDate ?: $startDate->modify('+' . $durationMinutes . ' minutes');
        $windowError = validate_appointment_window($providerId, $startDate, $endDate);
        if ($windowError) {
            return $windowError;
        }

        $finalStatus = $status ?: 'booked';
        $hashKey = hash('sha256', implode('|', [
            (string) $customerId,
            (string) $providerId,
            (string) $appointmentTypeId,
            $startDate->format(DateTimeInterface::ATOM),
            $endDate->format(DateTimeInterface::ATOM),
            bin2hex(random_bytes(8)),
        ]));

        $db = get_db();
        $stmt = $db->prepare('INSERT INTO appointments (customer_id, provider_id, appointment_type_id, start_time, end_time, status, notes, hash_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $customerId,
            $providerId,
            $appointmentTypeId,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s'),
            $finalStatus,
            $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            $hashKey,
        ]);

        return [
            'id' => (int) $db->lastInsertId(),
            'customer_id' => $customerId,
            'provider_id' => $providerId,
            'appointment_type_id' => $appointmentTypeId,
            'start_time' => $startDate->format('Y-m-d H:i:s'),
            'end_time' => $endDate->format('Y-m-d H:i:s'),
            'status' => $finalStatus,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'hash_key' => $hashKey,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function update_appointment(int $appointmentId, int $customerId, int $providerId, int $appointmentTypeId, string $startTime, ?string $endTime = null, ?string $status = null, ?string $notes = null): ?array
{
    try {
        $existingAppointment = get_appointment_by_id($appointmentId);
        if (!$existingAppointment) {
            return null;
        }

        $appointmentType = get_appointment_type_by_id($appointmentTypeId);
        if (!$appointmentType || (int) ($appointmentType['is_active'] ?? 0) !== 1) {
            return null;
        }

        $timezone = new DateTimeZone(get_app_timezone_name());
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startTime, $timezone);
        if (!$startDate) {
            $startDate = new DateTimeImmutable($startTime, $timezone);
        }

        $durationMinutes = max(1, (int) ($appointmentType['duration_minutes'] ?? 30));
        $resolvedEndDate = null;
        $endTimeValue = trim((string) ($endTime ?? ''));
        if ($endTimeValue !== '') {
            $resolvedEndDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endTimeValue, $timezone);
            if (!$resolvedEndDate) {
                $resolvedEndDate = new DateTimeImmutable($endTimeValue, $timezone);
            }
        }

        $endDate = $resolvedEndDate ?: $startDate->modify('+' . $durationMinutes . ' minutes');
        $windowError = validate_appointment_window($providerId, $startDate, $endDate, $appointmentId);
        if ($windowError) {
            return $windowError;
        }

        $finalStatus = $status ?: (string) ($existingAppointment['status'] ?? 'booked');
        $db = get_db();
        $stmt = $db->prepare('UPDATE appointments SET customer_id = ?, provider_id = ?, appointment_type_id = ?, start_time = ?, end_time = ?, status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([
            $customerId,
            $providerId,
            $appointmentTypeId,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s'),
            $finalStatus,
            $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            $appointmentId,
        ]);

        return [
            'id' => $appointmentId,
            'customer_id' => $customerId,
            'provider_id' => $providerId,
            'appointment_type_id' => $appointmentTypeId,
            'start_time' => $startDate->format('Y-m-d H:i:s'),
            'end_time' => $endDate->format('Y-m-d H:i:s'),
            'status' => $finalStatus,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function get_appointment_status_values(): array
{
    return ['booked', 'confirmed', 'present', 'completed', 'no show'];
}

function normalize_appointment_status(?string $status): ?string
{
    $value = strtolower(trim((string) $status));
    if ($value === '') {
        return null;
    }

    return in_array($value, get_appointment_status_values(), true) ? $value : null;
}

function delete_appointment(int $appointmentId): bool
{
    try {
        $db = get_db();
        $stmt = $db->prepare('DELETE FROM appointments WHERE id = ?');
        return (bool) $stmt->execute([$appointmentId]);
    } catch (Throwable $e) {
        return false;
    }
}

// User search helpers
function search_users(string $query = '', int $limit = 50, int $offset = 0): array
{
    try {
        $db = get_db();

        $query = trim($query);

        if (empty($query)) {
            $stmt = $db->prepare('SELECT id, email, full_name, first_name, last_name, phone, role, is_active, created_at FROM users ORDER BY full_name ASC, created_at DESC LIMIT ? OFFSET ?');
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $searchPattern = '%' . $query . '%';
        $stmt = $db->prepare("
            SELECT id, email, full_name, first_name, last_name, phone, role, is_active, created_at
            FROM users
            WHERE full_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?
            ORDER BY full_name ASC, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function count_users(string $query = ''): int
{
    try {
        $db = get_db();
        $query = trim($query);

        if (empty($query)) {
            $stmt = $db->query('SELECT COUNT(*) as cnt FROM users');
            return (int) ($stmt->fetch()['cnt'] ?? 0);
        }

        $searchPattern = '%' . $query . '%';
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM users
            WHERE full_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?
        ");
        $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern]);
        return (int) ($stmt->fetch()['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Checks if a requested booking window overlaps with any provider unavailabilities
 * (Supports both single one-off blocks and recurring rolling constraints)
 */
function check_provider_unavailability(int $providerId, DateTimeImmutable $startDate, DateTimeImmutable $endDate): ?array
{
    try {
        $db = get_db();
        
        // 1. Check Single Layout Footprints (One-off blocks)
        $stmt = $db->prepare("
            SELECT id, reason, start_datetime, end_datetime 
            FROM unavailabilities 
            WHERE provider_id = ? AND type = 'single'
              AND start_datetime < ? 
              AND end_datetime > ?
            LIMIT 1
        ");
        $stmt->execute([
            $providerId, 
            $endDate->format('Y-m-d H:i:s'), 
            $startDate->format('Y-m-d H:i:s')
        ]);
        $singleConflict = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($singleConflict) {
            return [
                'type' => 'single',
                'reason' => $singleConflict['reason'] ?: 'Provider unavailable (One-off Block)'
            ];
        }

        // 2. Check Rolling Calendar Constraints (Recurring rules)
        $dayOfWeek = (int) $startDate->format('N'); // 1 (Monday) to 7 (Sunday)
        $bookingDateStr = $startDate->format('Y-m-d');
        
        $stmt = $db->prepare("
            SELECT id, start_time, end_time, reason 
            FROM unavailabilities 
            WHERE provider_id = ? AND type = 'recurring'
              AND day_of_week = ?
              AND rolling_start_date <= ?
              AND (rolling_end_date IS NULL OR rolling_end_date >= ?)
        ");
        $stmt->execute([$providerId, $dayOfWeek, $bookingDateStr, $bookingDateStr]);
        $recurringRules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recurringRules)) {
            // Convert booking times to standalone operational seconds for comparison
            $reqStartSec = ((int)$startDate->format('H') * 3600) + ((int)$startDate->format('i') * 60) + (int)$startDate->format('s');
            $reqEndSec = ((int)$endDate->format('H') * 3600) + ((int)$endDate->format('i') * 60) + (int)$endDate->format('s');

            foreach ($recurringRules as $rule) {
                // Parse rule bound sequences cleanly into seconds
                $ruleStartParts = explode(':', $rule['start_time']);
                $ruleEndParts = explode(':', $rule['end_time']);
                
                $ruleStartSec = ((int)$ruleStartParts[0] * 3600) + ((int)$ruleStartParts[1] * 60);
                $ruleEndSec = ((int)$ruleEndParts[0] * 3600) + ((int)$ruleEndParts[1] * 60);

                // Overlap formula: rule start < booking end AND rule end > booking start
                if ($ruleStartSec < $reqEndSec && $ruleEndSec > $reqStartSec) {
                    return [
                        'type' => 'recurring',
                        'reason' => $rule['reason'] ?: 'Provider unavailable (Recurring Rule Pattern)'
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        // Safe execution fallback inside critical routing tracks
    }
    return null;
}


/**
 * Fetches all bookable users who are providers or admins
 */
function get_active_booking_providers(): array
{
    try {
        $db = get_db();
        // Include bookable users who are providers or admins so admins can be selectable as providers.
        $stmt = $db->query("SELECT id, email, full_name, role, is_active, 1 as bookable FROM users WHERE role IN ('provider','admin') ORDER BY full_name ASC, id ASC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Fetches existing customers matching exactly an email and mobile number combo.
 * This satisfies the customer validation and duplicate lookup routines.
 */
function get_customers_by_exact_contact(string $email, string $mobile): array
{
    try {
        $db = get_db();
        
        // We trim values to guarantee space padding variations do not break index lookups
        $cleanEmail  = trim($email);
        $cleanMobile = trim($mobile);

        // First attempt a robust look up with the comprehensive column scheme
        try {
            $stmt = $db->prepare('
                SELECT id, email, mobile, full_name, date_of_birth, gender, date_added 
                FROM customers 
                WHERE email = ? AND mobile = ? 
                ORDER BY id ASC
            ');
            $stmt->execute([$cleanEmail, $cleanMobile]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Fallback gracefully to standard minimal columns if the database schema is un-migrated
            $stmt = $db->prepare('
                SELECT id, email, mobile, full_name 
                FROM customers 
                WHERE email = ? AND mobile = ? 
                ORDER BY id ASC
            ');
            $stmt->execute([$cleanEmail, $cleanMobile]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        return [];
    }
}


function create_appointment_verification_token(int $appointmentId, int $expiry_minutes = 60): string
{
    $db = get_db();
    $code = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + (max(1, $expiry_minutes) * 60));

    $stmt = $db->prepare('DELETE FROM verification WHERE appointment_id = ? AND task = ?');
    $stmt->execute([$appointmentId, 'appointment_booking']);

    $stmt = $db->prepare('INSERT INTO verification (appointment_id, code, task, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$appointmentId, $code, 'appointment_booking', $expiresAt]);
    return $code;
}

function get_appointment_verification_by_code(string $code): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, user_id, appointment_id, code, task, created_at, expires_at FROM verification WHERE code = ? AND task = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
        $stmt->execute([$code, 'appointment_booking']);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function get_appointment_verification_by_appointment_id(int $appointmentId): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, user_id, appointment_id, code, task, created_at, expires_at FROM verification WHERE appointment_id = ? AND task = ? LIMIT 1');
        $stmt->execute([$appointmentId, 'appointment_booking']);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function ensure_verification_schema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExists = (bool) $db->query("SHOW TABLES LIKE 'verification'")->fetch();
        if (!$tableExists) {
            $db->exec("CREATE TABLE `verification` (
              `id` INT UNSIGNED AUTO_INCREMENT,
              `user_id` INT UNSIGNED NULL,
              `appointment_id` INT UNSIGNED NULL,
              `code` VARCHAR(64) NOT NULL,
              `task` VARCHAR(32) NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `expires_at` TIMESTAMP NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `idx_verification_code` (`code`),
              UNIQUE KEY `idx_verification_appointment_task` (`appointment_id`, `task`),
              KEY `idx_verification_user_task` (`user_id`, `task`),
              CONSTRAINT `fk_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
              CONSTRAINT `fk_verification_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $hasAppointmentId = (bool) $db->query("SHOW COLUMNS FROM verification LIKE 'appointment_id'")->fetch();
        if (!$hasAppointmentId) {
            $db->exec("ALTER TABLE verification ADD COLUMN appointment_id INT UNSIGNED NULL AFTER user_id");
        }

        $userIdNullable = (bool) $db->query("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'verification' AND COLUMN_NAME = 'user_id' AND IS_NULLABLE = 'YES'")->fetch();
        if (!$userIdNullable) {
            $db->exec("ALTER TABLE verification MODIFY user_id INT UNSIGNED NULL");
        }

        $hasAppointmentIndex = (bool) $db->query("SHOW INDEX FROM verification WHERE Key_name = 'idx_verification_appointment_task'")->fetch();
        if (!$hasAppointmentIndex) {
            $db->exec("CREATE UNIQUE INDEX idx_verification_appointment_task ON verification (appointment_id, task)");
        }

        $hasAppointmentFk = (bool) $db->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'verification' AND COLUMN_NAME = 'appointment_id' AND REFERENCED_TABLE_NAME = 'appointments' LIMIT 1")->fetch();
        if (!$hasAppointmentFk) {
            $db->exec("ALTER TABLE verification ADD CONSTRAINT fk_verification_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE ON UPDATE CASCADE");
        }
    } catch (Throwable $e) {
        // Ignore fallback
    }
}

function update_customer_meta(int $customerId, array $meta): void
{
    try {
        $db = get_db();
        $db->prepare("
            UPDATE customers SET
                last_ip         = :last_ip,
                last_user_agent = :last_user_agent,
                last_timezone   = :last_timezone,
                last_screen     = :last_screen,
                last_locale     = :last_locale,
                last_seen_at    = :last_seen_at
            WHERE id = :id
        ")->execute([
            ':last_ip'         => $meta['last_ip'],
            ':last_user_agent' => $meta['last_user_agent'],
            ':last_timezone'   => $meta['last_timezone'],
            ':last_screen'     => $meta['last_screen'],
            ':last_locale'     => $meta['last_locale'],
            ':last_seen_at'    => $meta['last_seen_at'],
            ':id'              => $customerId,
        ]);
    } catch (Throwable $e) {
        // silently fail - tracking should never break bookings
    }
}

/**
 * Automatically processes stale unconfirmed, confirmed, or booked appointments
 * and flips their status to 'no_show' if their end_time has drifted behind server NOW.
 */
function process_automatic_no_shows(): void
{
    try {
        // Evaluate configuration toggle preference dynamically from database
        if ((string) get_setting('auto_no_show', '0') === '1') {
            $db = get_db();
            
            // Get the true localized application time matching your appointments timezone
            $appTimezone = get_app_timezone_name();
            $tz = new DateTimeZone($appTimezone);
            $dt = new DateTimeImmutable('now', $tz);
            $now = $dt->format('Y-m-d H:i:s');

            // Explicit target checklist matching your schema strings
            $targetStatuses = ['unconfirmed', 'confirmed', 'booked'];
            
            // Build absolute named placeholders map array dynamically
            $namedPlaceholders = [];
            $bindValues = [':now' => $now];
            
            foreach ($targetStatuses as $index => $status) {
                $key = ':status' . $index;
                $namedPlaceholders[] = $key;
                $bindValues[$key] = $status;
            }

            // Construct definitive target query — SET status explicitly to 'no_show' with an underscore!
            $query = "UPDATE `appointments` 
                      SET `status` = 'no_show' 
                      WHERE `end_time` < :now 
                      AND `status` IN (" . implode(', ', $namedPlaceholders) . ")";
                      
            $stmt = $db->prepare($query);
            $stmt->execute($bindValues);
        }
    } catch (Throwable $e) {
        // Log to server errors in dev to give visibility without blocking clients
        if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
            error_log('Auto No-Show Routine Error Trace: ' . $e->getMessage());
        }
    }
}