<?php
require_once __DIR__ . '/functions.php';
init_session();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="https://unpkg.com/@picocss/pico@1.*/css/pico.min.css">
</head>
<body>
<main class="container">
  <article>
    <h1>Sign in</h1>
    <?php if (can_register_admin()): ?>
      <p><a href="register.php">Create an admin account</a></p>
    <?php endif; ?>
    <form id="loginForm" action="auth.php?action=login" method="post" data-api>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required>

      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>

      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

      <button type="submit">Sign in</button>
    </form>
  </article>
</main>
<script src="assets/js/global.js"></script>
</body>
</html>
