<?php
declare(strict_types=1);

/*
 * PHPUnit bootstrap.
 *
 * - Loads Composer's autoloader when present (PHPUnit itself).
 * - Registers a tiny PSR-4 autoloader for the Tests\ namespace.
 * - Loads functions.php, which needs config.php. If config.php does not exist (CI / fresh checkout) a temporary
 *   one is generated from the TEST_DB_* environment variables and removed again when the run ends.
 * - SAFETY: refuses to continue unless the configured database name ends in "_test", because the integration
 *   tests wipe tables. This check happens before anything connects to a database.
 */

$root = dirname(__DIR__);

if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $root . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$configPath = $root . '/config.php';
if (!is_file($configPath)) {
    $q = static fn(string $v): string => var_export($v, true);
    $php = "<?php\n"
        . "declare(strict_types=1);\n"
        . "date_default_timezone_set('Australia/Melbourne');\n"
        . "define('DB_HOST', " . $q((string) (getenv('TEST_DB_HOST') ?: '127.0.0.1')) . ");\n"
        . "define('DB_NAME', " . $q((string) (getenv('TEST_DB_NAME') ?: 'boomy_test')) . ");\n"
        . "define('DB_USER', " . $q((string) (getenv('TEST_DB_USER') ?: 'root')) . ");\n"
        . "define('DB_PASS', " . $q((string) (getenv('TEST_DB_PASS') ?: '')) . ");\n"
        . "define('APP_NAME', 'Boomy Appointments (tests)');\n";
    file_put_contents($configPath, $php);
    register_shutdown_function(static function () use ($configPath): void {
        @unlink($configPath);
    });
}

require_once $root . '/functions.php';

if (!defined('DB_NAME') || !str_ends_with((string) DB_NAME, '_test')) {
    fwrite(STDERR, "\nRefusing to run: DB_NAME is '" . (defined('DB_NAME') ? DB_NAME : '(undefined)') . "'.\n"
        . "The tests delete data, so the database name must end in '_test'.\n"
        . "Point config.php at a separate test database, or delete config.php and set TEST_DB_* variables (see tests/README.md).\n\n");
    exit(1);
}
