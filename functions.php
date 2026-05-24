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
    $reset_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/reset_password.php?code=' . urlencode($code);
    
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

function send_email(string $to_email, string $subject, string $body_html, string $body_plain = ''): array|bool
{
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


