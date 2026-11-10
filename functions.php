<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Never show PHP warnings / notices to visitors: they leak file paths and corrupt JSON responses.
// They still go to the error log. Put define('APP_DEBUG', true); in config.php on a development machine to see them.
if (!(defined('APP_DEBUG') && APP_DEBUG)) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
ini_set('log_errors', '1');

/**
 * Decode a JSON request body into an array and make it safe to cast to strings / ints.
 * Clients can send arrays or objects where we expect text ({"customer_email": [1]}); casting those with
 * (string) raises "Array to string conversion". Any array value whose key is not listed in $arrayKeys
 * becomes '' (which the normal "required field" validation then rejects).
 */
function normalize_json_input($raw, array $arrayKeys = []): array
{
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    foreach ($data as $key => $value) {
        if (is_array($value) && !in_array($key, $arrayKeys, true)) {
            $data[$key] = '';
        }
    }
    return $data;
}

/**
 * Is the visitor talking to us over HTTPS? Behind a TLS-terminating reverse proxy (SWAG, nginx, Cloudflare)
 * PHP only sees plain HTTP, so $_SERVER['HTTPS'] is empty and the session cookie would silently lose its
 * Secure flag. X-Forwarded-Proto is honoured only when the direct peer is a trusted proxy.
 */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote !== '' && is_trusted_proxy_ip($remote)) {
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https';
    }
    return false;
}

// Session hardening and initialization
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Harden session cookie params
        $secure = request_is_https();
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
        ensure_appointment_types_schema($pdo);
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
function ensure_appointment_types_schema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExists = (bool) $db->query("SHOW TABLES LIKE 'appointment_types'")->fetch();
        if (!$tableExists) {
            return;
        }

        $hasBookable = (bool) $db->query("SHOW COLUMNS FROM appointment_types LIKE 'is_bookable'")->fetch();
        if (!$hasBookable) {
            $db->exec("ALTER TABLE appointment_types ADD COLUMN is_bookable TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
        }
    } catch (Throwable $e) {
        // Safe execution catch
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

        $hasBookingSource = (bool) $db->query("SHOW COLUMNS FROM appointments LIKE 'booking_source'")->fetch();
        if (!$hasBookingSource) {
            $db->exec("ALTER TABLE appointments ADD COLUMN booking_source ENUM('staff','online','unknown') NOT NULL DEFAULT 'unknown' AFTER hash_key");
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
    // Optional check; block login if user is inactive
    // if ((int)$user['is_active'] !== 1) return null;
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
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }

    // Validate session binding + rolling expiry
    if (!validate_and_refresh_session()) {
        logout_user();
        header('Location: login.php?reason=session_expired');
        exit;
    }
}

// Clear session and cookies to log the user out
function logout_user(): void
{
    $sessionId = session_id();
    if ($sessionId) {
        invalidate_session_record($sessionId);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
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

/**
 * Base URL of the app.
 *
 * $requireConfigured: set for links that carry secrets (password reset, account confirmation). When the
 * "root_url" setting is empty the URL would be built from the Host header, which an attacker controls, so
 * a poisoned reset email could leak the token. In that mode we refuse instead of guessing.
 */
function get_app_root_url(bool $requireConfigured = false): string
{
    $configured = trim((string) get_setting('root_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if ($requireConfigured) {
        throw new RuntimeException('The Root URL setting must be configured before links containing secrets can be generated.');
    }

    $scheme = 'http';
    $forwarded_proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwarded_proto === 'https') {
        $scheme = 'https';
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''), '/');
    // Only accept a plain hostname / IPv4 / [IPv6] with optional port; anything else is header junk.
    if ($host === '' || !preg_match('/^(?:[A-Za-z0-9.\-]+|\[[0-9A-Fa-f:]+\])(?::\d{1,5})?$/', $host)) {
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

function build_app_url(string $path, array $query = [], bool $requireConfiguredRoot = false): string
{
    $url = get_app_root_url($requireConfiguredRoot) . '/' . ltrim($path, '/');
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

/**
 * True if $ip falls inside $cidr (or equals it when no prefix is given). IPv4 and IPv6.
 */
function ip_matches_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if ($cidr === '') {
        return false;
    }
    [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ipBin  = @inet_pton($ip);
    $subBin = @inet_pton((string) $subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
        return false;
    }
    $max   = strlen($ipBin) * 8;
    $bits  = $bits === null ? $max : max(0, min($max, (int) $bits));
    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($subBin[$bytes]) & $mask);
}

/**
 * Is this address a reverse proxy we may believe X-Forwarded-For from?
 * Define TRUSTED_PROXIES in config.php (array of IPs / CIDRs) to be explicit. Without it, loopback and
 * private ranges (Docker networks, LAN reverse proxies such as SWAG) are trusted, public addresses are not.
 */
function is_trusted_proxy_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES)) {
        foreach (TRUSTED_PROXIES as $entry) {
            if (ip_matches_cidr($ip, (string) $entry)) {
                return true;
            }
        }
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * The client's IP address. REMOTE_ADDR is the only value a client cannot forge, so forwarded headers
 * (X-Forwarded-For / X-Real-IP) are only honoured when the direct peer is a trusted proxy, and then the
 * right-most address that is not itself a trusted proxy wins (left-most entries are client-supplied).
 * HTTP_CLIENT_IP is never trusted.
 */
function get_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '' || !is_trusted_proxy_ip($remote)) {
        return substr($remote, 0, 45);
    }

    $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $chain = array_values(array_filter(
            array_map('trim', explode(',', $xff)),
            static fn($c) => filter_var($c, FILTER_VALIDATE_IP) !== false
        ));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!is_trusted_proxy_ip($chain[$i])) {
                return substr($chain[$i], 0, 45);
            }
        }
        if ($chain) {
            return substr($chain[0], 0, 45);
        }
    }

    $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
        return substr($real, 0, 45);
    }

    return substr($remote, 0, 45);
}

/**
 * Generic fixed-window rate limiter backed by the rate_limits table.
 * Returns true when the hit is allowed (and records it), false when the limit is exceeded.
 * Fails open if the database is unavailable so a hiccup cannot lock everybody out.
 */
function rate_limit_hit(string $bucket, string $key, int $maxHits, int $windowSeconds): bool
{
    try {
        $db = get_db();
        static $ensured = false;
        if (!$ensured) {
            $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(40) NOT NULL,
                key_hash CHAR(64) NOT NULL,
                hit_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rate_limits_lookup (bucket, key_hash, hit_at),
                KEY idx_rate_limits_time (hit_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ensured = true;
        }

        $keyHash = hash('sha256', strtolower($key));
        $count = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND key_hash = ? AND hit_at > (NOW() - INTERVAL ? SECOND)');
        $count->execute([$bucket, $keyHash, $windowSeconds]);
        if ((int) $count->fetchColumn() >= $maxHits) {
            return false;
        }

        $db->prepare('INSERT INTO rate_limits (bucket, key_hash) VALUES (?, ?)')->execute([$bucket, $keyHash]);

        if (random_int(1, 50) === 1) {
            $db->exec('DELETE FROM rate_limits WHERE hit_at < (NOW() - INTERVAL 1 DAY)');
        }
        return true;
    } catch (Throwable $e) {
        error_log('rate_limit_hit failed: ' . $e->getMessage());
        return true;
    }
}

/**
 * Too many failed logins? Limits: 5 failures per IP+email and 20 failures per IP in 15 minutes.
 * Keyed on IP so a stranger cannot lock the real admin out of their own account.
 */
function login_attempts_exceeded(string $email): bool
{
    try {
        $db = get_db();
        $ip = get_client_ip();
        $window = '(NOW() - INTERVAL 15 MINUTE)';

        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND ip_address = ? AND username = ? AND attempted_at > $window");
        $stmt->execute([$ip, $email]);
        if ((int) $stmt->fetchColumn() >= 5) {
            return true;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND ip_address = ? AND attempted_at > $window");
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() >= 20;
    } catch (Throwable $e) {
        error_log('login_attempts_exceeded failed: ' . $e->getMessage());
        return false;
    }
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
    try {
        $reset_url = build_app_url('reset_password.php', ['code' => $code], true);
    } catch (RuntimeException $e) {
        error_log('send_password_reset_email: ' . $e->getMessage());
        return false;
    }
    
    $subject = "Password Reset - $app_name";
    $emailRadius = theme_email_radius('s', '4px');
    $body_html = "
        <h2>Password Reset Request</h2>
        <p>Hi {$user['full_name']},</p>
        <p>You requested to reset your password. Click the link below to create a new password:</p>
        <p><a href=\"{$reset_url}\" style=\"background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: {$emailRadius}; display: inline-block;\">Reset Password</a></p>
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
    try {
        $confirm_url = build_app_url('confirm_account.php', ['code' => $code], true);
    } catch (RuntimeException $e) {
        error_log('send_account_confirmation_email: ' . $e->getMessage());
        return false;
    }
    $subject = "Confirm your account - $app_name";
    $emailRadius = theme_email_radius('s', '4px');
    $body_html = "
        <h2>Confirm Your Account</h2>
        <p>Hi {$user['full_name']},</p>
        <p>Please confirm your account by clicking the button below:</p>
        <p><a href=\"{$confirm_url}\" style=\"background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: {$emailRadius}; display: inline-block;\">Confirm Account</a></p>
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

// ============================================================
// Theme: colours (day + night) and font, set in Settings > Theme.
// Only values that differ from the defaults are stored, so an untouched install looks exactly as before.
// ============================================================

function theme_defaults(): array
{
    return [
        // colours: day
        'theme_primary'         => '#2457ff',
        'theme_secondary'       => '#10b981',
        'theme_tertiary'        => '#f59e0b',
        'theme_background'      => '#f4f7fb',
        'theme_surface'         => '#ffffff',
        // colours: night
        'theme_primary_dark'    => '#7ca2ff',
        'theme_secondary_dark'  => '#34d399',
        'theme_tertiary_dark'   => '#fbbf24',
        'theme_background_dark' => '#0b1220',
        'theme_surface_dark'    => '#111827',
        // font
        'theme_font'            => 'default',
        // corners: theme_radius is a percentage (0-50); '' means "keep the app's own corner sizes".
        // min/max are pixel limits so tiny controls never go square-ish and big panels never get huge curves.
        'theme_radius'          => '',
        'theme_radius_min'      => '4',
        'theme_radius_max'      => '32',
    ];
}

// key => label, CSS font stack, and the Google Fonts family spec (null = installed system font)
function theme_font_options(): array
{
    $sys = 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    return [
        'default'     => ['label' => 'App default (Inter if installed, else system)', 'stack' => 'Inter, ' . $sys, 'google' => null],
        'system'      => ['label' => 'System UI',                'stack' => $sys . ', Roboto',                                                         'google' => null],
        'helvetica'   => ['label' => 'Helvetica',                'stack' => '"Helvetica Neue", Helvetica, Arial, sans-serif',                         'google' => null],
        'trebuchet'   => ['label' => 'Trebuchet MS',             'stack' => '"Trebuchet MS", "Lucida Grande", "Lucida Sans Unicode", Tahoma, sans-serif', 'google' => null],
        'verdana'     => ['label' => 'Verdana',                  'stack' => 'Verdana, Geneva, sans-serif',                                            'google' => null],
        'georgia'     => ['label' => 'Georgia (serif)',          'stack' => 'Georgia, "Times New Roman", Times, serif',                               'google' => null],
        'fira_sans'   => ['label' => 'Fira Sans',                'stack' => '"Fira Sans", ' . $sys,                                                   'google' => 'Fira+Sans:wght@400;500;600;700'],
        'kreon'       => ['label' => 'Kreon (slab serif)',       'stack' => 'Kreon, Georgia, "Times New Roman", serif',                               'google' => 'Kreon:wght@400;500;600;700'],
        'inter'       => ['label' => 'Inter',                    'stack' => 'Inter, ' . $sys,                                                         'google' => 'Inter:wght@400;500;600;700'],
        'roboto'      => ['label' => 'Roboto',                   'stack' => 'Roboto, ' . $sys,                                                        'google' => 'Roboto:wght@400;500;700'],
        'open_sans'   => ['label' => 'Open Sans',                'stack' => '"Open Sans", ' . $sys,                                                   'google' => 'Open+Sans:wght@400;600;700'],
        'lato'        => ['label' => 'Lato',                     'stack' => 'Lato, ' . $sys,                                                          'google' => 'Lato:wght@400;700'],
        'montserrat'  => ['label' => 'Montserrat',               'stack' => 'Montserrat, ' . $sys,                                                    'google' => 'Montserrat:wght@400;500;600;700'],
        'poppins'     => ['label' => 'Poppins',                  'stack' => 'Poppins, ' . $sys,                                                       'google' => 'Poppins:wght@400;500;600;700'],
        'nunito'      => ['label' => 'Nunito',                   'stack' => 'Nunito, ' . $sys,                                                        'google' => 'Nunito:wght@400;600;700'],
        'source_sans' => ['label' => 'Source Sans 3',            'stack' => '"Source Sans 3", ' . $sys,                                               'google' => 'Source+Sans+3:wght@400;600;700'],
    ];
}

// '#abc' / '#AABBCC' -> '#aabbcc', anything else -> null
function theme_normalize_hex(?string $value): ?string
{
    $v = strtolower(trim((string) $value));
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $v, $m)) {
        return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    }
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
}

function theme_hex_to_rgb(string $hex): array
{
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

// Moves $hex toward $target by $ratio (0..1)
function theme_mix_hex(string $hex, string $target, float $ratio): string
{
    $a = theme_hex_to_rgb($hex);
    $b = theme_hex_to_rgb($target);
    $out = '#';
    for ($i = 0; $i < 3; $i++) {
        $out .= str_pad(dechex((int) round($a[$i] + ($b[$i] - $a[$i]) * $ratio)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

// Effective theme: ['values' => all 7 settings (saved or default), 'custom' => keys that differ from default, 'defaults' => ...]
// Integer within [min, max], or null when the value is not a plain integer in range
function theme_normalize_int($value, int $min, int $max): ?int
{
    $v = trim((string) $value);
    if ($v === '' || !preg_match('/^\d{1,3}$/', $v)) {
        return null;
    }
    $n = (int) $v;
    return ($n >= $min && $n <= $max) ? $n : null;
}

// Allowed ranges for the corner settings
function theme_radius_limits(): array
{
    return ['theme_radius' => [0, 50], 'theme_radius_min' => [0, 32], 'theme_radius_max' => [4, 80]];
}

// Effective theme: ['values' => all settings (saved or default), 'custom' => keys that differ from default, 'defaults' => ...]
function get_theme_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $defaults = theme_defaults();
    $fonts = theme_font_options();
    $limits = theme_radius_limits();
    $values = $defaults;
    $custom = [];
    $saved = [];
    try {
        $stmt = get_db()->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'theme\\_%'");
        $saved = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    } catch (Throwable $e) {
        $saved = [];
    }

    $radiusOn = false;
    foreach ($defaults as $key => $default) {
        $raw = trim((string) ($saved[$key] ?? ''));
        if ($key === 'theme_font') {
            if ($raw !== '' && isset($fonts[$raw]) && $raw !== $default) {
                $values[$key] = $raw;
                $custom[$key] = true;
            }
        } elseif (isset($limits[$key])) {
            $n = theme_normalize_int($raw, $limits[$key][0], $limits[$key][1]);
            if ($key === 'theme_radius') {
                if ($n !== null) {
                    $values[$key] = (string) $n;
                    $custom[$key] = true;
                    $radiusOn = true;
                }
            } elseif ($n !== null && (string) $n !== $default) {
                $values[$key] = (string) $n;
                $custom[$key] = true;
            }
        } else {
            $hex = theme_normalize_hex($raw);
            if ($hex !== null && $hex !== $default) {
                $values[$key] = $hex;
                $custom[$key] = true;
            }
        }
    }
    if (!$radiusOn) {
        // min/max only mean something while a custom radius is active
        $values['theme_radius_min'] = $defaults['theme_radius_min'];
        $values['theme_radius_max'] = $defaults['theme_radius_max'];
        unset($custom['theme_radius_min'], $custom['theme_radius_max']);
    }
    return $cache = ['values' => $values, 'custom' => $custom, 'defaults' => $defaults];
}

// Settings form POST -> values to store. Anything invalid or equal to the default is stored as '' (= use default).
function sanitize_theme_post(array $data): array
{
    $fonts = theme_font_options();
    $limits = theme_radius_limits();
    $out = [];
    foreach (theme_defaults() as $key => $default) {
        $raw = trim((string) ($data[$key] ?? ''));
        if ($key === 'theme_font') {
            $out[$key] = ($raw !== $default && isset($fonts[$raw])) ? $raw : '';
        } elseif (isset($limits[$key])) {
            $n = theme_normalize_int($raw, $limits[$key][0], $limits[$key][1]);
            $out[$key] = $n === null ? '' : (string) $n;
        } else {
            $hex = theme_normalize_hex($raw);
            $out[$key] = ($hex !== null && $hex !== $default) ? $hex : '';
        }
    }
    // Without a radius the min/max are meaningless, so do not keep them
    if ($out['theme_radius'] === '') {
        $out['theme_radius_min'] = '';
        $out['theme_radius_max'] = '';
    } else {
        $defaults = theme_defaults();
        foreach (['theme_radius_min', 'theme_radius_max'] as $k) {
            if ($out[$k] === $defaults[$k]) {
                $out[$k] = '';
            }
        }
    }
    return $out;
}

function theme_mode_declarations(array $theme, string $mode): string
{
    $v = $theme['values'];
    $c = $theme['custom'];
    $sfx = $mode === 'dark' ? '_dark' : '';
    $out = '';

    if (isset($c['theme_primary' . $sfx])) {
        $p = $v['theme_primary' . $sfx];
        [$r, $g, $b] = theme_hex_to_rgb($p);
        $strong = $mode === 'dark' ? theme_mix_hex($p, '#ffffff', 0.26) : theme_mix_hex($p, '#000000', 0.35);
        $soft = 'rgba(' . $r . ',' . $g . ',' . $b . ',' . ($mode === 'dark' ? '0.16' : '0.12') . ')';
        $out .= '--accent:' . $p . ';--accent-strong:' . $strong . ';--accent-soft:' . $soft . ';--accent-rgb:' . $r . ',' . $g . ',' . $b . ';--selected:' . $p . ';'
            . '--pico-primary:' . $p . ';--pico-primary-background:' . $p . ';--pico-primary-border:' . $p . ';'
            . '--pico-primary-underline:rgba(' . $r . ',' . $g . ',' . $b . ',0.5);'
            . '--pico-primary-hover:' . $strong . ';--pico-primary-hover-background:' . $strong . ';--pico-primary-hover-border:' . $strong . ';--pico-primary-hover-underline:' . $strong . ';'
            . '--pico-primary-focus:rgba(' . $r . ',' . $g . ',' . $b . ',0.25);';
    }
    if (isset($c['theme_secondary' . $sfx])) {
        $out .= '--accent-2:' . $v['theme_secondary' . $sfx] . ';';
    }
    if (isset($c['theme_tertiary' . $sfx])) {
        $t = $v['theme_tertiary' . $sfx];
        $out .= '--accent-3:' . $t . ';--current:' . $t . ';';
    }
    // Page background. --theme-bg is only defined when customised, so rules written as var(--theme-bg, <original>) keep their look otherwise.
    if (isset($c['theme_background' . $sfx])) {
        $bg = $v['theme_background' . $sfx];
        $out .= '--bg:' . $bg . ';--theme-bg:' . $bg . ';';
    }
    // Cards, panels, dialogs and form fields. --panel is the translucent card colour the pages use; --panel-strong the solid one.
    if (isset($c['theme_surface' . $sfx])) {
        $s = $v['theme_surface' . $sfx];
        [$r, $g, $b] = theme_hex_to_rgb($s);
        $out .= '--panel:rgba(' . $r . ',' . $g . ',' . $b . ',0.92);--panel-strong:' . $s . ';--theme-surface:' . $s . ';--input-bg:' . $s . ';';
    }
    return $out;
}

// CSS value for one corner size: a percentage of the element, never below $min px and never above $max px.
// $factor scales the three tiers (small controls 0.5, normal 1, large panels 1.5).
function theme_radius_value(int $pct, int $min, int $max, float $factor): string
{
    $fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    $p = min(50.0, round($pct * $factor, 1));
    $lo = (int) round($min * $factor);
    $hi = max($lo, (int) round($max * $factor));
    return 'min(' . $hi . 'px,max(' . $lo . 'px,' . $fmt($p) . '%))';
}

// The --r-* variables every border-radius in the app falls back from. Empty when corners are not customised.
function theme_radius_declarations(array $theme): string
{
    if (!isset($theme['custom']['theme_radius'])) {
        return '';
    }
    $pct = (int) $theme['values']['theme_radius'];
    $min = (int) $theme['values']['theme_radius_min'];
    $max = (int) $theme['values']['theme_radius_max'];
    return '--r-s:' . theme_radius_value($pct, $min, $max, 0.5) . ';'
        . '--r-m:' . theme_radius_value($pct, $min, $max, 1.0) . ';'
        . '--r-l:' . theme_radius_value($pct, $min, $max, 1.5) . ';'
        . '--r-pill:' . theme_radius_value($pct, $min, $max, 1.0) . ';'
        . '--pico-border-radius:var(--r-m);';
}

// The CSS that overrides each page's own variables. Empty string when nothing is customised.
function build_theme_css(array $theme): string
{
    $light = theme_mode_declarations($theme, 'light');
    $dark = theme_mode_declarations($theme, 'dark');
    $css = '';

    // Admin pages set data-theme via the toggle; public pages follow the visitor's OS setting.
    if ($light !== '') {
        $css .= 'html:root[data-theme="light"]{' . $light . '}';
        $css .= '@media (prefers-color-scheme: light){html:root:not([data-theme]){' . $light . '}}';
    }
    if ($dark !== '') {
        $css .= 'html:root[data-theme="dark"]{' . $dark . '}';
        $css .= '@media (prefers-color-scheme: dark){html:root:not([data-theme]){' . $dark . '}}';
    }

    $radius = theme_radius_declarations($theme);
    if ($radius !== '') {
        $css .= 'html:root{' . $radius . '}';
    }

    if (isset($theme['custom']['theme_font'])) {
        $fonts = theme_font_options();
        $stack = $fonts[$theme['values']['theme_font']]['stack'] ?? '';
        if ($stack !== '') {
            $css .= ':root{--theme-font:' . $stack . ';--font-family:' . $stack . ';--pico-font-family:' . $stack . ';--pico-font-family-sans-serif:' . $stack . ';}';
            $css .= 'html body,html button,html input,html select,html textarea,html option{font-family:var(--theme-font);}';
        }
    }
    return $css;
}

// Call once just before </head> on every page (after the page's own <style>).
function render_theme_head(): void
{
    try {
        echo '<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">' . "\n";
        $theme = get_theme_settings();
        if (isset($theme['custom']['theme_font'])) {
            $google = theme_font_options()[$theme['values']['theme_font']]['google'] ?? null;
            if ($google) {
                echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
                echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
                echo '<link rel="stylesheet" href="' . htmlspecialchars('https://fonts.googleapis.com/css2?family=' . $google . '&display=swap', ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }
        }
        $css = build_theme_css($theme);
        if ($css !== '') {
            echo '<style id="app-theme">' . $css . '</style>' . "\n";
        }
    } catch (Throwable $e) {
        error_log('render_theme_head failed: ' . $e->getMessage());
    }
}

// Emails cannot use CSS variables, so they use the day-mode primary colour directly.
function theme_email_primary(): string
{
    return get_theme_settings()['values']['theme_primary'];
}

// Pixel radius for HTML emails. $tier is 's', 'm', 'l' or 'pill'; $default is what the email used before theming.
// Pure: takes the theme array so it can be tested without a database.
function theme_email_radius_for(array $theme, string $tier, string $default): string
{
    if (!isset($theme['custom']['theme_radius'])) {
        return $default;
    }
    $pct = (int) $theme['values']['theme_radius'];
    $min = (int) $theme['values']['theme_radius_min'];
    $max = (int) $theme['values']['theme_radius_max'];
    $factor = ['s' => 0.5, 'm' => 1.0, 'l' => 1.5, 'pill' => 1.0][$tier] ?? 1.0;
    $reference = ['s' => 40, 'm' => 90, 'l' => 200, 'pill' => 44][$tier] ?? 44;   // typical element height in px
    $lo = (int) round($min * $factor);
    $hi = max($lo, (int) round($max * $factor));
    $px = (int) round($pct * $factor / 100 * $reference);
    return max($lo, min($hi, $px)) . 'px';
}

function theme_email_radius(string $tier, string $default): string
{
    return theme_email_radius_for(get_theme_settings(), $tier, $default);
}

function theme_email_tint(float $alpha): string
{
    [$r, $g, $b] = theme_hex_to_rgb(theme_email_primary());
    return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
}

// ============================================================
// Other appointments on the same day for the same email address
// (listed in the verification and confirmation emails)
// ============================================================

function describe_same_day_appointment(array $row, DateTimeImmutable $now, DateTimeZone $timezone): array
{
    $start = new DateTimeImmutable((string) $row['start_time'], $timezone);
    $end = new DateTimeImmutable((string) ($row['end_time'] ?? $row['start_time']), $timezone);
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $hashKey = trim((string) ($row['hash_key'] ?? ''));
    $upcoming = $start > $now;

    $statusLabels = [
        'unconfirmed' => 'Awaiting email verification',
        'booked'      => 'Booked',
        'confirmed'   => 'Confirmed',
        'present'     => 'Arrived',
        'completed'   => 'Completed',
        'no_show'     => 'Missed',
    ];

    $type = get_appointment_type_by_id((int) ($row['appointment_type_id'] ?? 0));
    $provider = get_user_by_id((int) ($row['provider_id'] ?? 0));
    $customerName = trim((string) ($row['full_name'] ?? ''));

    return [
        'id'            => (int) ($row['id'] ?? 0),
        'hash_key'      => $hashKey,
        'status'        => $status,
        'status_label'  => $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)),
        'start_time'    => (string) $row['start_time'],
        'time_label'    => $start->format('g:i A') . ' - ' . $end->format('g:i A'),
        'customer_name' => $customerName !== '' ? $customerName : 'Customer',
        'type_name'     => trim((string) ($type['name'] ?? 'Appointment')),
        'provider_name' => format_appointment_person_name($provider) ?: 'Not recorded',
        // Appointments awaiting email verification can only be handled from their own verification email
        'can_confirm'   => $upcoming && $hashKey !== '' && $status === 'booked',
        'can_delete'    => $upcoming && $hashKey !== '' && in_array($status, ['booked', 'confirmed'], true),
        'is_current'    => false,
    ];
}

// Other (non-cancelled) appointments on the same day booked under the same email address
function get_same_day_appointments_for_email(array $appointment): array
{
    try {
        $customer = get_customer_by_id((int) ($appointment['customer_id'] ?? 0));
        $email = strtolower(trim((string) ($customer['email'] ?? '')));
        $startRaw = trim((string) ($appointment['start_time'] ?? ''));
        if ($email === '' || $startRaw === '') {
            return [];
        }

        $timezone = new DateTimeZone(get_app_timezone_name());
        $dayStart = (new DateTimeImmutable($startRaw, $timezone))->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $now = new DateTimeImmutable('now', $timezone);

        $stmt = get_db()->prepare("SELECT a.id, a.start_time, a.end_time, a.status, a.hash_key, a.provider_id, a.appointment_type_id, c.full_name
            FROM appointments a
            JOIN customers c ON c.id = a.customer_id
            WHERE LOWER(TRIM(c.email)) = ? AND a.start_time >= ? AND a.start_time < ? AND a.id <> ? AND LOWER(TRIM(a.status)) <> 'cancelled'
            ORDER BY a.start_time ASC, a.id ASC");
        $stmt->execute([$email, $dayStart->format('Y-m-d H:i:s'), $dayEnd->format('Y-m-d H:i:s'), (int) ($appointment['id'] ?? 0)]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = describe_same_day_appointment($row, $now, $timezone);
        }
        return $out;
    } catch (Throwable $e) {
        error_log('get_same_day_appointments_for_email failed: ' . $e->getMessage());
        return [];
    }
}

// Builds the "other appointments today" section for an email. Returns ['html', 'plain', 'count'].
// With $withActions (confirmation email) every actionable appointment gets Confirm / Delete buttons,
// plus "all" buttons that put every code in one link (confirm_appointment.php?code=HASH1,HASH2,...).
function build_same_day_appointments_email_block(array $appointment, bool $withActions): array
{
    $empty = ['html' => '', 'plain' => '', 'count' => 0];
    $others = get_same_day_appointments_for_email($appointment);
    if (!$others) {
        return $empty;
    }

    $esc = fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $rows = $others;

    // Always include the current appointment in the daily summary so it's a complete schedule
    $timezone = new DateTimeZone(get_app_timezone_name());
    $customer = get_customer_by_id((int) ($appointment['customer_id'] ?? 0));
    $current = describe_same_day_appointment(
        array_merge($appointment, ['full_name' => $customer['full_name'] ?? '']),
        new DateTimeImmutable('now', $timezone),
        $timezone
    );
    $current['is_current'] = true;
    $rows[] = $current;
    usort($rows, fn(array $a, array $b): int => strcmp($a['start_time'], $b['start_time']) ?: ($a['id'] <=> $b['id']));

    $dayLabel = format_app_datetime((string) $appointment['start_time'], 'l j F Y');
    $actionable = array_values(array_filter($rows, fn(array $r): bool => $r['can_delete']));
    $allCodes = implode(',', array_column($actionable, 'hash_key'));

    $btn = 'display:inline-block;padding:7px 14px;border-radius:' . theme_email_radius('pill', '999px') . ';text-decoration:none;font-weight:700;font-size:13px;margin:2px 4px 2px 0;';
    $confirmStyle = $btn . 'background:' . theme_email_primary() . ';color:#ffffff;';
    $deleteStyle = $btn . 'background:#ffffff;color:#b91c1c;border:1px solid #b91c1c;';

    $intro = $withActions
        ? 'More than one appointment is booked with this email address on ' . $dayLabel . '. You can confirm or delete them individually or all together.'
        : 'Summary of all appointments associated with this email address on ' . $dayLabel . ':';

    $html = '<h3>' . $esc('Appointments on ' . $dayLabel) . '</h3>'
        . '<p>' . $esc($intro) . '</p>'
        . '<table role="presentation" style="border-collapse:collapse;width:100%;">';
    $plain = $intro . "\n\n";

    foreach ($rows as $r) {
        $title = $r['customer_name'] . ($r['is_current'] ? ' (this booking)' : '');
        $html .= '<tr style="border-top:1px solid #e5e7eb;">'
            . '<td style="padding:10px 12px 10px 0;vertical-align:top;white-space:nowrap;"><strong>' . $esc($r['time_label']) . '</strong></td>'
            . '<td style="padding:10px 12px 10px 0;vertical-align:top;"><strong>' . $esc($title) . '</strong><br>'
            . $esc($r['type_name'] . ' with ' . $r['provider_name']) . '<br>'
            . '<span style="color:#6b7280;">' . $esc($r['status_label']) . '</span></td>';
        $plain .= '- ' . $r['time_label'] . ': ' . $title . ' (' . $r['type_name'] . ' with ' . $r['provider_name'] . ') [' . $r['status_label'] . "]\n";

        if ($withActions) {
            $cells = '';
            if ($r['can_confirm']) {
                $url = build_app_url('confirm_appointment.php', ['code' => $r['hash_key']]);
                $cells .= '<a href="' . $esc($url) . '" style="' . $confirmStyle . '">Confirm</a>';
                $plain .= '    Confirm ' . $r['customer_name'] . ': ' . $url . "\n";
            }
            if ($r['can_delete']) {
                $url = build_app_url('confirm_appointment.php', ['code' => $r['hash_key']]);
                $cells .= '<a href="' . $esc($url) . '" style="' . $deleteStyle . '">Delete</a>';
                $plain .= '    Delete ' . $r['customer_name'] . ': ' . $url . "\n";
            }
            $html .= '<td style="padding:10px 0;vertical-align:top;">' . $cells . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';

    if ($withActions && count($actionable) >= 2) {
        $allUrl = build_app_url('confirm_appointment.php', ['code' => $allCodes]);
        $html .= '<p style="margin-top:14px;"><strong>All ' . count($actionable) . ' appointments:</strong><br>'
            . '<a href="' . $esc($allUrl) . '" style="' . $confirmStyle . '">Confirm all ' . count($actionable) . '</a>'
            . '<a href="' . $esc($allUrl) . '" style="' . $deleteStyle . '">Delete all ' . count($actionable) . '</a></p>'
            . '<p style="font-size:13px;color:#6b7280;">Both buttons open a page where you review the appointments before anything changes.</p>';
        $plain .= "\nAll " . count($actionable) . ' appointments (opens a page where you confirm or delete them): ' . $allUrl . "\n";
    }

    return ['html' => $html, 'plain' => $plain . "\n", 'count' => count($others)];
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

    // CHECK EMAIL VALIDITY
    if ((bool) ($customer['email_invalid'] ?? 0)) {
        return ['ok' => false, 'error' => 'Customer email previously flagged as invalid'];
    }
    if (!email_domain_has_mx($customerEmail)) {
        mark_customer_email_invalid((int) $customer['id'], 'Domain has no mail server (MX/A record)');
        return ['ok' => false, 'error' => 'Email domain has no mail server'];
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
    $customMessage = trim((string) get_setting('confirmation_email_message', ''));
    $sameDay = build_same_day_appointments_email_block($appointment, true);

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
        . $sameDay['html']
        . ($customMessage !== '' ? '<p>' . nl2br(htmlspecialchars($customMessage, ENT_QUOTES, 'UTF-8')) . '</p>' : '')
        . '<p><a href="' . htmlspecialchars($appointmentUrl, ENT_QUOTES, 'UTF-8') . '" style="background-color:' . theme_email_primary() . ';color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:' . theme_email_radius('pill', '999px') . ';display:inline-block;font-weight:700;">Confirm or modify your appointment</a></p>'
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
        . $sameDay['plain']
        . ($customMessage !== '' ? "{$customMessage}\n\n" : '')
        . "Confirm or modify your appointment: {$appointmentUrl}\n";

    $sendResult = send_email($customerEmail, $subject, $body_html, $body_plain);
    if ($sendResult === true) {
        return ['ok' => true, 'sent' => true];
    }

    return ['ok' => false, 'error' => 'Failed to send confirmation email'];
}


function send_admin_booking_notification_email(array $appointment, bool $pending = false): array
{
    if (!(bool) (int) get_setting('notify_admin_on_booking', '0')) {
        return ['ok' => false, 'error' => 'Admin notifications disabled'];
    }

    if (!email_transport_is_ready()) {
        return ['ok' => false, 'error' => 'Mail settings not configured'];
    }

    $adminEmail = trim((string) get_setting('organization_email', ''));
    if ($adminEmail === '') {
        return ['ok' => false, 'error' => 'Organisation email not configured'];
    }

    $customer = get_customer_by_id((int) ($appointment['customer_id'] ?? 0));
    $provider = get_user_by_id((int) ($appointment['provider_id'] ?? 0));
    $appointmentType = get_appointment_type_by_id((int) ($appointment['appointment_type_id'] ?? 0));

    $siteName = trim((string) get_setting('site_name', APP_NAME ?? 'Boomy Appointments'));
    if ($siteName === '') {
        $siteName = APP_NAME ?? 'Boomy Appointments';
    }

    $appointmentTypeName = trim((string) ($appointmentType['name'] ?? 'Appointment'));
    $providerName = format_appointment_person_name($provider) ?: 'Not recorded';
    $startTime = format_app_datetime((string) ($appointment['start_time'] ?? ''));
    $endTime = format_app_datetime((string) ($appointment['end_time'] ?? ''));
    $customerName = trim((string) ($customer['full_name'] ?? ''));
    $customerEmail = trim((string) ($customer['email'] ?? ''));
    $customerMobile = trim((string) ($customer['mobile'] ?? ''));

    $statusLine = $pending
        ? 'This booking is pending customer verification before it is confirmed.'
        : 'This booking has been confirmed.';

    $subject = ($pending ? 'New pending booking request' : 'New appointment booked') . ' - ' . $siteName;

    $body_html = '<h2>' . ($pending ? 'New pending booking request' : 'New appointment booked') . '</h2>'
        . '<p>' . htmlspecialchars($statusLine, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<h3>Appointment Details</h3>'
        . '<ul>'
        . '<li><strong>Appointment type:</strong> ' . htmlspecialchars($appointmentTypeName, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Provider:</strong> ' . htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Start time:</strong> ' . htmlspecialchars($startTime, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>End time:</strong> ' . htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>'
        . '<h3>Customer Details</h3>'
        . '<ul>'
        . '<li><strong>Name:</strong> ' . htmlspecialchars($customerName !== '' ? $customerName : 'Not recorded', ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Email:</strong> ' . htmlspecialchars($customerEmail !== '' ? $customerEmail : 'Not recorded', ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Mobile:</strong> ' . htmlspecialchars($customerMobile !== '' ? $customerMobile : 'Not recorded', ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>';

    $body_plain = ($pending ? "New pending booking request" : "New appointment booked") . " - {$siteName}\n\n"
        . "{$statusLine}\n\n"
        . "Appointment Details\n"
        . "Appointment type: {$appointmentTypeName}\n"
        . "Provider: {$providerName}\n"
        . "Start time: {$startTime}\n"
        . "End time: {$endTime}\n\n"
        . "Customer Details\n"
        . "Name: " . ($customerName !== '' ? $customerName : 'Not recorded') . "\n"
        . "Email: " . ($customerEmail !== '' ? $customerEmail : 'Not recorded') . "\n"
        . "Mobile: " . ($customerMobile !== '' ? $customerMobile : 'Not recorded') . "\n";

    $sendResult = send_email($adminEmail, $subject, $body_html, $body_plain);
    if ($sendResult === true) {
        return ['ok' => true, 'sent' => true];
    }

    return ['ok' => false, 'error' => 'Failed to send admin notification email'];
}

function send_admin_cancellation_notification_email(array $appointment): array
{
    if (!(bool) (int) get_setting('notify_admin_on_cancel', '0')) {
        return ['ok' => false, 'error' => 'Admin notifications disabled'];
    }

    if (!email_transport_is_ready()) {
        return ['ok' => false, 'error' => 'Mail settings not configured'];
    }

    $adminEmail = trim((string) get_setting('organization_email', ''));
    if ($adminEmail === '') {
        return ['ok' => false, 'error' => 'Organisation email not configured'];
    }

    $customer = get_customer_by_id((int) ($appointment['customer_id'] ?? 0));
    $provider = get_user_by_id((int) ($appointment['provider_id'] ?? 0));
    $appointmentType = get_appointment_type_by_id((int) ($appointment['appointment_type_id'] ?? 0));

    $siteName = trim((string) get_setting('site_name', APP_NAME ?? 'Boomy Appointments'));
    if ($siteName === '') {
        $siteName = APP_NAME ?? 'Boomy Appointments';
    }

    $appointmentTypeName = trim((string) ($appointmentType['name'] ?? 'Appointment'));
    $providerName = format_appointment_person_name($provider) ?: 'Not recorded';
    $startTime = format_app_datetime((string) ($appointment['start_time'] ?? ''));
    $endTime = format_app_datetime((string) ($appointment['end_time'] ?? ''));
    $customerName = trim((string) ($customer['full_name'] ?? '')) ?: 'Not recorded';
    $customerEmail = trim((string) ($customer['email'] ?? '')) ?: 'Not recorded';
    $customerMobile = trim((string) ($customer['mobile'] ?? '')) ?: 'Not recorded';
    $cancelledAt = format_app_datetime(date('Y-m-d H:i:s'));

    $h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $subject = 'Appointment cancelled by customer - ' . $siteName;

    $body_html = '<h2>Appointment cancelled by customer</h2>'
        . '<p>The customer cancelled this appointment using the link in their email (cancelled ' . $h($cancelledAt) . ').</p>'
        . '<h3>Appointment Details</h3>'
        . '<ul>'
        . '<li><strong>Appointment type:</strong> ' . $h($appointmentTypeName) . '</li>'
        . '<li><strong>Provider:</strong> ' . $h($providerName) . '</li>'
        . '<li><strong>Start time:</strong> ' . $h($startTime) . '</li>'
        . '<li><strong>End time:</strong> ' . $h($endTime) . '</li>'
        . '</ul>'
        . '<h3>Customer Details</h3>'
        . '<ul>'
        . '<li><strong>Name:</strong> ' . $h($customerName) . '</li>'
        . '<li><strong>Email:</strong> ' . $h($customerEmail) . '</li>'
        . '<li><strong>Mobile:</strong> ' . $h($customerMobile) . '</li>'
        . '</ul>';

    $body_plain = "Appointment cancelled by customer - {$siteName}\n\n"
        . "The customer cancelled this appointment using the link in their email (cancelled {$cancelledAt}).\n\n"
        . "Appointment Details\n"
        . "Appointment type: {$appointmentTypeName}\n"
        . "Provider: {$providerName}\n"
        . "Start time: {$startTime}\n"
        . "End time: {$endTime}\n\n"
        . "Customer Details\n"
        . "Name: {$customerName}\n"
        . "Email: {$customerEmail}\n"
        . "Mobile: {$customerMobile}\n";

    $sendResult = send_email($adminEmail, $subject, $body_html, $body_plain);
    if ($sendResult === true) {
        return ['ok' => true, 'sent' => true];
    }

    return ['ok' => false, 'error' => 'Failed to send admin cancellation email'];
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

/**
 * Whole calendar days between the day an appointment was booked and today, in the clinic's time zone.
 * Booked today = 0, booked yesterday = 1 and so on. $createdTs is a Unix timestamp (use
 * UNIX_TIMESTAMP(created_at) in SQL, which is correct whatever time zone the database runs in).
 * Returns null when the timestamp is missing; a booking "from the future" (clock skew) counts as 0.
 */
function appointment_days_since_created($createdTs, ?DateTimeImmutable $now = null): ?int
{
    if ($createdTs === null || $createdTs === '' || !is_numeric($createdTs)) {
        return null;
    }
    $tz = new DateTimeZone(get_app_timezone_name());
    $createdDay = (new DateTimeImmutable('@' . (int) $createdTs))->setTimezone($tz)->setTime(0, 0);
    $today = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz)->setTime(0, 0);

    return max(0, (int) $createdDay->diff($today)->format('%r%a'));
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
        'show_days_since_booked' => (bool) (int) get_setting('booking_show_days_since_booked', '0'),
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

function get_appointment_types(bool $activeOnly = false, bool $bookableOnly = false): array
{
    try {
        $db = get_db();
        $sql = 'SELECT id, name, duration_minutes, color_hex, is_active, is_bookable FROM appointment_types';
        $conditions = [];
        if ($activeOnly) {
            $conditions[] = 'is_active = 1';
        }
        if ($bookableOnly) {
            $conditions[] = 'is_bookable = 1';
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
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

        // date_added is stamped with app (server) time rather than the database clock, which runs in UTC
        $columns = ['full_name', 'email', 'mobile', 'date_added'];
        $values = [$fullName, $email ?: '', $mobile ?: '', date('Y-m-d H:i:s')];

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

function email_domain_has_mx(string $email): bool
{
    $parts = explode('@', $email);
    if (count($parts) !== 2 || trim($parts[1]) === '') {
        return false;
    }
    $domain = trim($parts[1]);

    // MX is the normal case; fall back to A/AAAA since some domains
    // accept mail via a bare A record with no MX (rare but valid per RFC 5321)
    return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
}

function mark_customer_email_invalid(int $customerId, string $reason): void
{
    try {
        $db = get_db();
        $stmt = $db->prepare(
            'UPDATE customers SET email_invalid = 1, email_invalid_reason = ?, email_invalid_at = NOW() WHERE id = ?'
        );
        $stmt->execute([substr($reason, 0, 255), $customerId]);
    } catch (Throwable $e) {
        // non-fatal
    }
}

function clear_customer_email_invalid(int $customerId): void
{
    try {
        $db = get_db();
        $stmt = $db->prepare(
            'UPDATE customers SET email_invalid = 0, email_invalid_reason = NULL, email_invalid_at = NULL WHERE id = ?'
        );
        $stmt->execute([$customerId]);
    } catch (Throwable $e) {
        // non-fatal
    }
}

function get_appointment_type_by_id(int $id): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, name, duration_minutes, color_hex, is_active, is_bookable FROM appointment_types WHERE id = ? LIMIT 1');
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
        $stmt = $db->prepare('SELECT id, customer_id, provider_id, appointment_type_id, start_time, end_time, status, notes, hash_key, booking_source, created_at, updated_at FROM appointments WHERE id = ? LIMIT 1');
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
        $stmt = $db->prepare('SELECT id, customer_id, provider_id, appointment_type_id, start_time, end_time, status, notes, hash_key, booking_source, created_at, updated_at FROM appointments WHERE hash_key = ? LIMIT 1');
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

function validate_appointment_window(int $providerId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, ?int $excludeAppointmentId = null, bool $allowStaffOverride = false): ?array
{
    if ($allowStaffOverride) {
        return null;
    }

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

    if ($unavailConflict = check_provider_unavailability($providerId, $startDate, $endDate)) {
        return [
            'error' => 'provider_unavailable',
            'message' => '🚨 ' . htmlspecialchars($unavailConflict['reason'])
        ];
    }

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
        $sql .= " AND a.status NOT IN ('cancelled', 'no_show')";
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

function create_appointment(int $customerId, int $providerId, int $appointmentTypeId, string $startTime, ?string $status = null, ?string $endTime = null, ?string $notes = null, bool $allowStaffOverride = false, string $bookingSource = 'unknown'): ?array
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
        
        // Pass override flag here
        $windowError = validate_appointment_window($providerId, $startDate, $endDate, null, $allowStaffOverride);
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
        $bookingSource = in_array($bookingSource, ['staff', 'online', 'unknown'], true) ? $bookingSource : 'unknown';
        $insertValues = [
            $customerId,
            $providerId,
            $appointmentTypeId,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s'),
            $finalStatus,
            $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            $hashKey,
        ];
        try {
            $stmt = $db->prepare('INSERT INTO appointments (customer_id, provider_id, appointment_type_id, start_time, end_time, status, notes, hash_key, booking_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute(array_merge($insertValues, [$bookingSource]));
        } catch (PDOException $e) {
            // Unknown column 1054 = booking_source migration not run yet. Fall back so bookings keep working.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1054) {
                throw $e;
            }
            $stmt = $db->prepare('INSERT INTO appointments (customer_id, provider_id, appointment_type_id, start_time, end_time, status, notes, hash_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute($insertValues);
        }

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
            'booking_source' => $bookingSource,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function update_appointment(int $appointmentId, int $customerId, int $providerId, int $appointmentTypeId, string $startTime, ?string $endTime = null, ?string $status = null, ?string $notes = null, bool $allowStaffOverride = false): ?array
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

        //  FIX 2: Fall back to the existing record's end_time if no new end_time is supplied over the request
        if ($resolvedEndDate === null && !empty($existingAppointment['end_time'])) {
            $endDate = new DateTimeImmutable($existingAppointment['end_time'], $timezone);
        } else {
            $endDate = $resolvedEndDate ?: $startDate->modify('+' . $durationMinutes . ' minutes');
        }

        //  FIX 1: If a staff override is active, skip all boundary checks entirely
        if (!$allowStaffOverride) {
            $windowError = validate_appointment_window($providerId, $startDate, $endDate, $appointmentId, $allowStaffOverride);
            if ($windowError) {
                return $windowError;
            }
        }

        $finalStatus = $status ?: (string) ($existingAppointment['status'] ?? 'booked');
        $db = get_db();
        $existingBookingSource = in_array(($existingAppointment['booking_source'] ?? 'unknown'), ['staff', 'online', 'unknown'], true)
            ? $existingAppointment['booking_source']
            : 'unknown';
        $stmt = $db->prepare('UPDATE appointments SET customer_id = ?, provider_id = ?, appointment_type_id = ?, start_time = ?, end_time = ?, status = ?, notes = ?, booking_source = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([
            $customerId,
            $providerId,
            $appointmentTypeId,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s'), // Will never be null now!
            $finalStatus,
            $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            $existingBookingSource,
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
            'booking_source' => $existingBookingSource,
        ];
    } catch (Throwable $e) {
        // Optional tracking logging trace during local development:
        // error_log("SQL Update Fail Trace: " . $e->getMessage());
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

function search_users(string $query = '', int $limit = 50, int $offset = 0): array
{
    try {
        $db = get_db();
        $query = trim($query);

        if (empty($query)) {
            $stmt = $db->prepare('SELECT id, email, full_name, first_name, last_name, phone, role, is_active, bookable, created_at FROM users ORDER BY full_name ASC, created_at DESC LIMIT ? OFFSET ?');
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $searchPattern = '%' . $query . '%';
        $stmt = $db->prepare("
            SELECT id, email, full_name, first_name, last_name, phone, role, is_active, bookable, created_at
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
 * (Supports both single one-off blocks and recurring rolling constraints, including Everyday rules)
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
        
        // This query contains exactly 4 placeholders (?)
        $stmt = $db->prepare("
            SELECT id, start_time, end_time, reason 
            FROM unavailabilities 
            WHERE provider_id = ? AND type = 'recurring'
              AND (day_of_week = ? OR day_of_week = 0)
              AND rolling_start_date <= ?
              AND (rolling_end_date IS NULL OR rolling_end_date >= ?)
        ");
        
        // FIXED: Passing exactly 4 elements to perfectly align with the query placeholders
        $stmt->execute([
            $providerId, 
            $dayOfWeek, 
            $bookingDateStr, 
            $bookingDateStr
        ]);
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
    $lastRun = (int) ($_SESSION['auto_no_show_last_run'] ?? 0);
    if ((time() - $lastRun) < 60) { // Only run every minutes
        return;
    }
    $_SESSION['auto_no_show_last_run'] = time();

    try {
        if ((string) get_setting('auto_no_show', '0') === '1') {
            $db = get_db();
            $appTimezone = get_app_timezone_name();
            $tz = new DateTimeZone($appTimezone);
            $dt = new DateTimeImmutable('now', $tz);
            $now = $dt->format('Y-m-d H:i:s');

            $targetStatuses = ['unconfirmed', 'booked'];
            $namedPlaceholders = [];
            $bindValues = [':now' => $now];

            foreach ($targetStatuses as $index => $status) {
                $key = ':status' . $index;
                $namedPlaceholders[] = $key;
                $bindValues[$key] = $status;
            }

            // Only sweep appointments not manually touched in the last 5 minutes
            $bindValues[':now2'] = $now;
            $query = "UPDATE appointments 
                      SET status = 'no_show' 
                      WHERE end_time < :now 
                      AND status IN (" . implode(', ', $namedPlaceholders) . ")
                      AND updated_at < DATE_SUB(:now2, INTERVAL 5 MINUTE)";

            $stmt = $db->prepare($query);
            $stmt->execute($bindValues);
        }
    } catch (Throwable $e) {
        if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
            error_log('Auto No-Show Routine Error: ' . $e->getMessage());
        }
    }
}

// ── Session management ────────────────────────────────────────────────────────

function get_session_lifetime_hours(): int
{
    $raw = (int) get_setting('session_lifetime_hours', '18');
    return max(1, $raw);
}

function get_client_ip_for_session(): string
{
    return get_client_ip();
}

function get_client_ua_for_session(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
}

function create_session_record(int $userId): void
{
    $db        = get_db();
    $sessionId = session_id();
    $ip        = get_client_ip_for_session();
    $ua        = get_client_ua_for_session();
    $now       = date('Y-m-d H:i:s');

    // Upsert — handles the edge case where the same session_id is reused
    $stmt = $db->prepare("
        INSERT INTO sessions (session_id, user_id, ip_address, user_agent, created_at, last_activity_at, is_active)
        VALUES (?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            user_id          = VALUES(user_id),
            ip_address       = VALUES(ip_address),
            user_agent       = VALUES(user_agent),
            last_activity_at = VALUES(last_activity_at),
            is_active        = 1
    ");
    $stmt->execute([$sessionId, $userId, $ip, $ua, $now, $now]);
}

function validate_and_refresh_session(): bool
{
    if (empty($_SESSION['user']['id'])) return false;

    $db        = get_db();
    $sessionId = session_id();
    $now       = date('Y-m-d H:i:s');

    $stmt = $db->prepare("
        SELECT * FROM sessions
        WHERE session_id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) return false;

    // Rolling expiry check
    $lifetimeHours   = get_session_lifetime_hours();
    $lastActivity    = new DateTimeImmutable($record['last_activity_at']);
    $expiresAt       = $lastActivity->modify("+{$lifetimeHours} hours");
    $nowDt           = new DateTimeImmutable($now);

    if ($nowDt > $expiresAt) {
        invalidate_session_record($sessionId);
        return false;
    }

    // Binding check — only invalidate if BOTH ip AND ua changed
    $currentIp = get_client_ip_for_session();
    $currentUa = get_client_ua_for_session();
    $ipChanged  = $record['ip_address'] !== $currentIp;
    $uaChanged  = $record['user_agent'] !== $currentUa;

    if ($ipChanged && $uaChanged) {
        invalidate_session_record($sessionId);
        return false;
    }

    // Still valid — refresh last_activity_at
    $stmt = $db->prepare("
        UPDATE sessions SET last_activity_at = ? WHERE session_id = ?
    ");
    $stmt->execute([$now, $sessionId]);

    return true;
}

function invalidate_session_record(string $sessionId): void
{
    $db = get_db();
    $stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE session_id = ?");
    $stmt->execute([$sessionId]);
}

function invalidate_all_user_sessions(int $userId, ?string $exceptSessionId = null): void
{
    $db = get_db();
    if ($exceptSessionId !== null && $exceptSessionId !== '') {
        $stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE user_id = ? AND session_id <> ?");
        $stmt->execute([$userId, $exceptSessionId]);
        return;
    }
    $stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE user_id = ?");
    $stmt->execute([$userId]);
}

/**
 * Gate for JSON API endpoints. Unlike a bare `$_SESSION['user']` check this also confirms the session is
 * still active in the sessions table (so "revoke session", password changes, deactivation and expiry all
 * take effect on API calls) and optionally enforces a role. Sends 401/403 JSON and exits on failure.
 */
function require_api_user(?string $role = null): array
{
    init_session();

    $valid = !empty($_SESSION['user']) && validate_and_refresh_session();
    if (!$valid) {
        unset($_SESSION['user']);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
        exit;
    }

    if ($role !== null && ($_SESSION['user']['role'] ?? '') !== $role) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    return $_SESSION['user'];
}

function get_active_sessions_for_user(int $userId): array
{
    $db              = get_db();
    $lifetimeHours   = get_session_lifetime_hours();
    $currentSessionId = session_id();

    $stmt = $db->prepare("
        SELECT
            id,
            session_id,
            ip_address,
            user_agent,
            created_at,
            last_activity_at,
            DATE_ADD(last_activity_at, INTERVAL ? HOUR) AS expires_at,
            (session_id = ?) AS is_current
        FROM sessions
        WHERE user_id = ?
          AND is_active = 1
          AND DATE_ADD(last_activity_at, INTERVAL ? HOUR) > NOW()
        ORDER BY last_activity_at DESC
    ");
    $stmt->execute([$lifetimeHours, $currentSessionId, $userId, $lifetimeHours]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Return an existing valid cancel token for this appointment,
 * or mint a fresh one. Reuses the verification table with
 * task = 'cancel_appointment', exactly like appointment_booking tokens.
 *
 * Token expires 1 hour before appointment start so customers
 * can't cancel at the last minute via email — they'd need to call.
 */
function get_or_create_cancel_token(int $appointmentId): ?string
{
    $db = get_db();
 
    // Reuse any existing non-expired token
    $stmt = $db->prepare("
        SELECT code FROM verification
        WHERE appointment_id = ?
          AND task = 'cancel_appointment'
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false && $existing !== null) {
        return (string) $existing;
    }
 
    // Get/cancel token - remove duplicate ones (such as when a user/admin moves an appointment)
    $appt = get_appointment_by_id($appointmentId);
    if (!$appt) return null;

    // Expiry is enforced on cancel_appointment.php from the live appointment time,
    // so rescheduling can't strand an old link.
    $expiresAt = null;

    // A stale row would violate the unique (appointment_id, task) index
    $db->prepare("DELETE FROM verification WHERE appointment_id = ? AND task = 'cancel_appointment'")
    ->execute([$appointmentId]);
 
    $code = bin2hex(random_bytes(32)); // 64-char hex, same as other tokens
 
    // user_id is nullable (ensure_verification_schema handles the ALTER if needed)
    $db->prepare("
        INSERT INTO verification (user_id, appointment_id, code, task, expires_at)
        VALUES (NULL, ?, ?, 'cancel_appointment', ?)
    ")->execute([$appointmentId, $code, $expiresAt]);
 
    return $code;
}
 
/**
 * Look up a verification row by code + task.
 * Works for any task type: 'cancel_appointment', 'appointment_booking', etc.
 * Returns the full row array or null. The caller uses $row['appointment_id'].
 */
function get_verification_by_code_and_task(string $code, string $task): ?array
{
    if ($code === '' || $task === '') return null;
    $stmt = get_db()->prepare("
        SELECT * FROM verification
        WHERE code = ? AND task = ?
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute([$code, $task]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ============================================================
// Booking questions (the `user_questions` setting)
// Stored as JSON: [{"id":"glasses","label":"Do you wear glasses?","options":["Yes","No"],"required":false}]
// Answers live in appointment_answers (per appointment) and customer_answers (latest per customer).
// ============================================================

function get_user_questions(): array
{
    $list = json_decode((string) get_setting('user_questions', '[]'), true);
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $q) {
        if (!is_array($q) || empty($q['id']) || empty($q['label']) || empty($q['options']) || !is_array($q['options'])) {
            continue;
        }
        $out[] = [
            'id'       => (string) $q['id'],
            'label'    => (string) $q['label'],
            'options'  => array_values(array_map('strval', $q['options'])),
            'required' => !empty($q['required']),
        ];
    }
    return $out;
}

// Settings textarea -> question list. One question per line:  id | Label | Option A, Option B | required
function parse_user_questions_text(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $p = array_map('trim', explode('|', $line));
        if (count($p) < 3 || $p[0] === '' || $p[1] === '') {
            continue;
        }
        $id = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($p[0])), 0, 32);
        $options = array_values(array_unique(array_filter(array_map('trim', explode(',', $p[2])), 'strlen')));
        if ($id === '' || !$options) {
            continue;
        }
        $out[$id] = [
            'id'       => $id,
            'label'    => $p[1],
            'options'  => $options,
            'required' => isset($p[3]) && strtolower($p[3]) === 'required',
        ];
    }
    return array_values($out);
}

function format_user_questions_text(array $questions): string
{
    $lines = [];
    foreach ($questions as $q) {
        $lines[] = $q['id'] . ' | ' . $q['label'] . ' | ' . implode(', ', $q['options']) . (!empty($q['required']) ? ' | required' : '');
    }
    return implode("\n", $lines);
}

// Keep only answers for configured questions whose value is a current option
function sanitize_answers(array $input): array
{
    $out = [];
    foreach (get_user_questions() as $q) {
        $a = trim((string) ($input[$q['id']] ?? ''));
        if ($a !== '' && in_array($a, $q['options'], true)) {
            $out[$q['id']] = $a;
        }
    }
    return $out;
}

// Returns an error message if a required question has no (valid) answer, otherwise null
function validate_required_answers(array $answers): ?string
{
    foreach (get_user_questions() as $q) {
        if (!empty($q['required']) && !isset($answers[$q['id']])) {
            return 'Please answer: ' . $q['label'];
        }
    }
    return null;
}

function save_customer_answers(int $customerId, array $answers): void
{
    if ($customerId < 1 || !$answers) {
        return;
    }
    // Stamp with app (server) time rather than the database clock, which runs in UTC
    $now = date('Y-m-d H:i:s');
    $stmt = get_db()->prepare('INSERT INTO customer_answers (customer_id, question_id, answer, updated_at) VALUES (?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE answer = VALUES(answer), updated_at = VALUES(updated_at)');
    foreach ($answers as $qid => $a) {
        $stmt->execute([$customerId, $qid, $a, $now]);
    }
}

// Saves answers for an appointment. Only rows for currently configured questions are replaced,
// so history for questions that were later removed from the setting is left alone.
// Never throws: a failure here must not break a booking.
function save_appointment_answers(int $apptId, int $customerId, array $answers, bool $updateCustomer = true): void
{
    if ($apptId < 1) {
        return;
    }
    try {
        $db  = get_db();
        $ids = array_column(get_user_questions(), 'id');
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM appointment_answers WHERE appointment_id = ? AND question_id IN ($in)")
               ->execute(array_merge([$apptId], $ids));
        }
        $ins = $db->prepare('INSERT INTO appointment_answers (appointment_id, question_id, answer) VALUES (?, ?, ?)');
        foreach ($answers as $qid => $a) {
            $ins->execute([$apptId, $qid, $a]);
        }
        if ($updateCustomer) {
            save_customer_answers($customerId, $answers);
        }
    } catch (Throwable $e) {
        error_log('save_appointment_answers failed: ' . $e->getMessage());
    }
}

// Used once an unconfirmed (email-verified) booking is confirmed
function copy_appointment_answers_to_customer(int $apptId, int $customerId): void
{
    try {
        save_customer_answers($customerId, get_appointment_answers($apptId));
    } catch (Throwable $e) {
        error_log('copy_appointment_answers_to_customer failed: ' . $e->getMessage());
    }
}

function get_appointment_answers(int $apptId): array   // [question_id => answer]
{
    try {
        $s = get_db()->prepare('SELECT question_id, answer FROM appointment_answers WHERE appointment_id = ?');
        $s->execute([$apptId]);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        error_log('get_appointment_answers failed: ' . $e->getMessage());
        return [];
    }
}

function get_customer_answers(int $customerId): array  // [question_id => [answer, updated_at]]
{
    try {
        $s = get_db()->prepare('SELECT question_id, answer, updated_at FROM customer_answers WHERE customer_id = ?');
        $s->execute([$customerId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['question_id']] = [$r['answer'], $r['updated_at']];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('get_customer_answers failed: ' . $e->getMessage());
        return [];
    }
}

// Display rule: a stored value that is no longer an option shows as n/a
function answer_display(array $question, ?string $stored): string
{
    return ($stored !== null && in_array($stored, $question['options'], true)) ? $stored : 'n/a';
}

// One entry per configured question for a customer profile. answer is '' when never answered.
function get_customer_answers_display(int $customerId): array
{
    $stored = get_customer_answers($customerId);
    $out = [];
    foreach (get_user_questions() as $q) {
        $row = $stored[$q['id']] ?? null;
        $out[] = [
            'id'         => $q['id'],
            'label'      => $q['label'],
            'answer'     => $row ? answer_display($q, (string) $row[0]) : '',
            'updated_at' => $row ? $row[1] : null,
        ];
    }
    return $out;
}
