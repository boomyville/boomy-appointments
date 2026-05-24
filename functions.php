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
    }
}

// PDO database connection (singleton)
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
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
    $stmt = $db->prepare('INSERT INTO users (email, password_hash, full_name, first_name, last_name, phone, role) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$email, $hash, $full_name, $first_name, $last_name, $phone, $role]);
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

function validate_email(string $email): string|bool
{
    $email = trim($email);
    if (!validate_email_format($email)) {
        return 'Invalid email format';
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

