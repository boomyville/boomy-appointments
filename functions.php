<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function start_session_if_needed(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function get_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function get_time(): string
{
    return date('Y-m-d H:i:s');
}

function get_date_format(PDO $db_connection): string
{
    $statement = $db_connection->prepare('SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1');
    $statement->execute(['setting_key' => 'date_format']);
    $value = $statement->fetchColumn();

    return is_string($value) && $value !== '' ? $value : 'd/m/Y';
}

function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $statement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1');
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();

    return is_string($value) && $value !== '' ? $value : $default;
}

function save_setting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    $statement->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
}

function has_users(PDO $pdo): bool
{
    $statement = $pdo->query('SELECT 1 FROM users LIMIT 1');

    return (bool) $statement->fetchColumn();
}

function is_logged_in(): bool
{
    start_session_if_needed();

    return isset($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function flash(string $key, ?string $message = null): ?string
{
    start_session_if_needed();

    if ($message !== null) {
        $_SESSION['flash_messages'][$key] = $message;

        return null;
    }

    if (!isset($_SESSION['flash_messages'][$key])) {
        return null;
    }

    $value = $_SESSION['flash_messages'][$key];
    unset($_SESSION['flash_messages'][$key]);

    return is_string($value) ? $value : null;
}