<?php
require_once __DIR__ . '/functions.php';
init_session();

$adminCount = count_admin_users();
$maxAdmins = get_max_admin_users();
$blocked = !can_register_admin();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Register - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="https://unpkg.com/@picocss/pico@1.*/css/pico.min.css">
  <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>
<main class="container">
  <article>
    <h1>Create Admin Account</h1>

    <?php if ($blocked): ?>
      <p><strong>Registrations blocked.</strong> The admin limit has been reached.</p>
      <p>Allowed admins: <?php echo (int) $maxAdmins; ?>. Current admins: <?php echo (int) $adminCount; ?>.</p>
      <p>You can still sign in on the <a href="login.php">login page</a>.</p>
    <?php else: ?>
      <p>
        <?php if ($adminCount === 0): ?>
          No admin user exists yet. Create the first one below.
        <?php else: ?>
          An admin already exists. You can create another admin up to the configured limit.
        <?php endif; ?>
      </p>

      <form id="registerForm" action="auth.php?action=register" method="post" data-api>
        <p style="font-size: 0.9em; color: #999;"><em>First name, last name, and phone are optional</em></p>
        
        <label for="first_name">First name</label>
        <input type="text" id="first_name" name="first_name">

        <label for="last_name">Last name</label>
        <input type="text" id="last_name" name="last_name">

        <label for="phone">Phone</label>
        <input type="tel" id="phone" name="phone">

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" minlength="8" required>

        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

        <button type="submit">Create account</button>
      </form>
    <?php endif; ?>
  </article>
</main>
<script src="assets/js/global.js"></script>
</body>
</html>
