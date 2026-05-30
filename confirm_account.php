<?php
require_once __DIR__ . '/functions.php';
init_session();

$site_name = get_site_name();
$support_message = get_support_contact_message($site_name);

$code = trim($_GET['code'] ?? '');
$valid = false;
$user_id = null;

if ($code) {
    $user_id = validate_verification_token($code, 'account_confirm');
    $valid = $user_id !== null;
}

if ($valid) {
    $db = get_db();
    $stmt = $db->prepare('UPDATE users SET is_active = 1 WHERE id = ?');
    $stmt->execute([$user_id]);
    delete_verification_token($code);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Confirm Account - <?php echo htmlspecialchars($site_name); ?></title>
  <link rel="stylesheet" href="https://unpkg.com/@picocss/pico@1.*/css/pico.min.css">
</head>
<body>
<main class="container">
  <article>
    <h1>Account Confirmation</h1>
    <?php if (!$valid): ?>
      <p style="color: #dc3545;"><strong>Invalid or expired confirmation link.</strong></p>
      <p><?php echo htmlspecialchars($support_message); ?></p>
    <?php else: ?>
      <p style="color: #155724;"><strong>✓ Your account has been confirmed.</strong></p>
      <p>You may now <a href="login.php">sign in</a>.</p>
    <?php endif; ?>
  </article>
</main>
</body>
</html>
