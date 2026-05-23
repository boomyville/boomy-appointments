<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

require_login();
$pdo = get_pdo();
$errors = [];
$saved = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteName = trim((string) ($_POST['site_name'] ?? ''));
    $organizationEmail = trim((string) ($_POST['organization_email'] ?? ''));
    $dateFormat = (string) ($_POST['date_format'] ?? 'd/m/Y');

    $allowedFormats = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y'];

    if ($siteName === '') {
        $errors[] = 'Site name is required.';
    }

    if (!filter_var($organizationEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid organization email.';
    }

    if (!in_array($dateFormat, $allowedFormats, true)) {
        $errors[] = 'Select a valid date format.';
    }

    if (!$errors) {
        save_setting($pdo, 'site_name', $siteName);
        save_setting($pdo, 'organization_email', $organizationEmail);
        save_setting($pdo, 'date_format', $dateFormat);
        flash('success', 'Settings saved.');

        header('Location: settings.php');
        exit;
    }
}

$siteName = get_setting($pdo, 'site_name', APP_NAME);
$organizationEmail = get_setting($pdo, 'organization_email', 'hello@example.com');
$dateFormat = get_date_format($pdo);

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
    <title>Settings · <?php echo e(APP_NAME); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <nav class="container auth-topbar">
        <ul>
            <li><strong><?php echo e(APP_NAME); ?></strong></li>
        </ul>
        <ul>
            <li><span><?php echo e((string) ($_SESSION['user_email'] ?? '')); ?></span></li>
            <li><a href="logout.php" role="button" class="secondary outline">Logout</a></li>
        </ul>
    </nav>

    <main class="container settings-shell">
        <article>
            <header>
                <p class="eyebrow">Admin dashboard</p>
                <h1>Application settings</h1>
                <p>Update the site name, organization email, and preferred date format.</p>
            </header>

            <?php if ($saved !== null): ?>
                <p class="success-message"><?php echo e($saved); ?></p>
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

            <form method="post" action="settings.php" class="settings-form">
                <label>
                    Site / website name
                    <input type="text" name="site_name" required value="<?php echo e((string) ($errors ? ($_POST['site_name'] ?? $siteName) : $siteName)); ?>">
                </label>

                <label>
                    Organization email
                    <input type="email" name="organization_email" required value="<?php echo e((string) ($errors ? ($_POST['organization_email'] ?? $organizationEmail) : $organizationEmail)); ?>">
                </label>

                <label>
                    Date format
                    <select name="date_format" required>
                        <?php
                        $formats = [
                            'd/m/Y' => 'DD/MM/YYYY',
                            'm/d/Y' => 'MM/DD/YYYY',
                            'Y-m-d' => 'YYYY-MM-DD',
                            'd-m-Y' => 'DD-MM-YYYY',
                        ];
                                            $postedFormat = (string) ($_POST['date_format'] ?? $dateFormat);
                                            $selectedFormat = in_array($postedFormat, $allowedFormats, true) ? $postedFormat : $dateFormat;
                        foreach ($formats as $value => $label):
                        ?>
                            <option value="<?php echo e($value); ?>" <?php echo $selectedFormat === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <footer>
                    <button type="submit">Save settings</button>
                </footer>
            </form>
        </article>
    </main>
</body>
</html>