<?php
require_once __DIR__ . '/functions.php';
init_session();
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}
$user = $_SESSION['user'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="https://unpkg.com/@picocss/pico@1.*/css/pico.min.css">
  <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>
<main class="container">
  <header>
    <h1>Admin</h1>
    <p>Welcome, <?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></p>
  </header>

  <nav>
    <ul>
      <li><a href="settings.php">Settings</a></li>
    </ul>
    <ul>
      <li>
        <form id="logoutForm" action="auth.php?action=logout" method="post" data-api style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <button type="submit">Sign out</button>
        </form>
      </li>
    </ul>
  </nav>
</main>
<script src="assets/js/global.js"></script>
</body>
</html>
