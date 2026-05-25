<?php
require_once __DIR__ . '/functions.php';
init_session();

$code = trim($_GET['code'] ?? '');
$valid = false;
$user_id = null;
$user_email = '';

if ($code) {
  $user_id = validate_verification_token($code, 'password_reset');
  if ($user_id !== null) {
    $user = get_user_by_verification_code($code);
    if ($user && isset($user['email'])) {
      $user_email = $user['email'];
      $valid = true;
    }
  }
}

if (!$valid) {
  http_response_code(404);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reset Password - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="https://unpkg.com/@picocss/pico@1.*/css/pico.min.css">
  <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>
<main class="container">
  <article>
    <h1>Reset Password</h1>

    <?php if (!$valid): ?>
      <p style="color: #dc3545;"><strong>Invalid or expired reset link.</strong></p>
      <p>The password reset link has expired or is invalid. Please request a new one:</p>
      <p><a href="login.php">Back to login</a></p>
    <?php else: ?>
      <p>Enter your email and new password below.</p>
      
      <form id="resetForm" action="<?php echo htmlspecialchars(build_app_url('auth.php', ['action' => 'reset_password'])); ?>" method="post" data-api>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($user_email); ?>">

        <label for="password">New Password</label>
        <input type="password" id="password" name="password" minlength="8" required>
        
        <label for="password_confirm">Confirm Password</label>
        <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
        
        <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        
        <button type="submit">Update Password</button>
        <div id="resetMsg"></div>
      </form>
    <?php endif; ?>
    <p><a href="<?php echo htmlspecialchars(build_app_url('login.php')); ?>">Back to login</a></p>
  </article>
</main>
<script src="assets/js/global.js"></script>
<script>
const resetForm = document.getElementById('resetForm');
if (resetForm) {
  resetForm.addEventListener('submit', function(e) {
    const pwd = document.getElementById('password').value;
    const confirm = document.getElementById('password_confirm').value;
    const msgDiv = document.getElementById('resetMsg');
    
    if (pwd !== confirm) {
      e.preventDefault();
      msgDiv.innerHTML = '<p style="color: red; margin-top: 8px;">✗ Passwords do not match</p>';
      return;
    }
    
    window.addEventListener('api:success', (evt) => {
      if (evt.detail.form === resetForm) {
        msgDiv.innerHTML = '<p style="color: green; margin-top: 8px;">✓ Password updated successfully. Redirecting to login...</p>';
        setTimeout(() => {
          window.location.href = 'login.php';
        }, 2000);
      }
    });

    window.addEventListener('api:error', (evt) => {
      if (evt.detail.form === resetForm) {
        msgDiv.innerHTML = '<p style="color: red; margin-top: 8px;">✗ ' + (evt.detail.json.error || 'Error resetting password') + '</p>';
      }
    });
  });
}
</script>
</body>
</html>
