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
    ];
}

function get_booking_calendar_settings(): array
{
    return [
        'show_monday' => (bool) (int) get_setting('booking_show_monday', '1'),
        'show_tuesday' => (bool) (int) get_setting('booking_show_tuesday', '1'),
        'show_wednesday' => (bool) (int) get_setting('booking_show_wednesday', '1'),
        'show_thursday' => (bool) (int) get_setting('booking_show_thursday', '1'),
        'show_friday' => (bool) (int) get_setting('booking_show_friday', '1'),
        'show_saturday' => (bool) (int) get_setting('booking_show_saturday', '0'),
        'show_sunday' => (bool) (int) get_setting('booking_show_sunday', '0'),
        'start_time' => (string) get_setting('booking_start_time', '09:00'),
        'end_time' => (string) get_setting('booking_end_time', '17:00'),
        'show_now_indicator' => (bool) (int) get_setting('booking_show_now_indicator', '1'),
    ];
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

    return null;
}

function get_customer_missing_required_fields(array $data): array
{
    $settings = get_customer_field_settings();
    $missing = [];

    foreach ($settings as $fieldName => $cfg) {
        if ($cfg['required'] && $cfg['display']) {
            $value = trim((string) ($data[$fieldName] ?? ''));
            if (empty($value)) {
                $missing[] = $fieldName;
            }
        }
    }

    return $missing;
}


function create_customer(string $fullName, ?string $email, ?string $mobile): ?int
{
    try {
        $db = get_db();
        $stmt = $db->prepare('INSERT INTO customers (full_name, email, mobile) VALUES (?, ?, ?)');
        $stmt->execute([$fullName, $email ?: '', $mobile ?: '']);
        return (int) $db->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

function get_customer_by_email(string $email): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, email, mobile, full_name FROM customers WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function get_customer_by_id(int $id): ?array
{
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, email, mobile, full_name FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
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


