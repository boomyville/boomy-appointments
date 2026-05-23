<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

start_session_if_needed();
$pdo = get_pdo();

if (is_logged_in()) {
    header('Location: settings.php');
    exit;
}

$hasUsers = has_users($pdo);
$errors = [];
$successMessage = flash('success');
$pageTitle = $hasUsers ? 'Sign in' : 'Initial Admin Setup';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$hasUsers && isset($_POST['setup_admin'])) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }

        if ($password !== $passwordConfirmation) {
            $errors[] = 'Password confirmation does not match.';
        }

        if (!$errors && has_users($pdo)) {
            $errors[] = 'Initial setup is no longer available.';
        }

        if (!$errors) {
            $statement = $pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
            $statement->execute(['email' => $email]);

            if ($statement->fetchColumn()) {
                $errors[] = 'That email is already registered.';
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO users (email, password_hash, created_at) VALUES (:email, :password_hash, :created_at)'
                );

                $insert->execute([
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'created_at' => get_time(),
                ]);

                $_SESSION['user_id'] = (int) $pdo->lastInsertId();
                $_SESSION['user_email'] = $email;
                flash('success', 'Initial admin account created.');

                header('Location: settings.php');
                exit;
            }
        }
    }

    if ($hasUsers && isset($_POST['login'])) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $errors[] = 'Enter your email and password.';
        } else {
            $statement = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE email = :email LIMIT 1');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if (!$user || !password_verify($password, (string) $user['password_hash'])) {
                $errors[] = 'Invalid email or password.';
            } else {
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_email'] = (string) $user['email'];

                header('Location: settings.php');
                exit;
            }
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?> · <?php echo e(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <main class="container auth-shell">
        <section class="auth-hero">
            <p class="eyebrow"><?php echo $hasUsers ? 'Admin portal' : 'First launch'; ?></p>
            <h1><?php echo e(APP_NAME); ?></h1>
            <p>
                <?php if ($hasUsers): ?>
                    Sign in to manage clinic settings and keep the booking system running.
                <?php else: ?>
                    Create the first administrator account before anyone else can access the portal.
                <?php endif; ?>
            </p>
        </section>

        <article class="auth-panel">
            <header>
                <h2><?php echo e($pageTitle); ?></h2>
                <p><?php echo $hasUsers ? 'Use your admin credentials to continue.' : 'This route will switch to the login form after setup.'; ?></p>
            </header>

            <?php if ($successMessage !== null): ?>
                <p class="success-message"><?php echo e($successMessage); ?></p>
            <?php endif; ?>

            <?php if ($errors): ?>
                <details open class="error-box">
                    <summary>Fix the following issue<?php echo count($errors) > 1 ? 's' : ''; ?></summary>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <form method="post" action="login.php" class="auth-form">
                <?php if (!$hasUsers): ?>
                    <input type="hidden" name="setup_admin" value="1">
                    <label>
                        Admin email
                        <input type="email" name="email" autocomplete="email" required value="<?php echo e((string) ($_POST['email'] ?? '')); ?>">
                    </label>
                    <label>
                        Password
                        <div class="password-field">
                            <input type="password" name="password" id="password" autocomplete="new-password" required minlength="8">
                            <button type="button" class="secondary outline" data-password-toggle data-target="password">Show</button>
                        </div>
                    </label>
                    <label>
                        Confirm password
                        <input type="password" name="password_confirmation" autocomplete="new-password" required minlength="8">
                    </label>
                <?php else: ?>
                    <input type="hidden" name="login" value="1">
                    <label>
                        Email
                        <input type="email" name="email" autocomplete="email" required value="<?php echo e((string) ($_POST['email'] ?? '')); ?>">
                    </label>
                    <label>
                        Password
                        <div class="password-field">
                            <input type="password" name="password" id="password" autocomplete="current-password" required>
                            <button type="button" class="secondary outline" data-password-toggle data-target="password">Show</button>
                        </div>
                    </label>
                <?php endif; ?>

                <footer>
                    <button type="submit"><?php echo $hasUsers ? 'Sign in' : 'Create admin account'; ?></button>
                </footer>
            </form>
        </article>
    </main>

    <script src="assets/js/app.js"></script>
</body>
</html>