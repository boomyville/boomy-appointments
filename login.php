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
      <p style="text-align: center; margin-top: 12px;"><a href="#" onclick="openForgotPasswordModal(event)">Forgot password?</a></p>
    </form>
  </article>
</main>

<!-- Forgot Password Modal -->
<dialog id="forgotPasswordModal">
  <article>
    <h2>Reset Password</h2>
    <p>Enter your email address and we'll send you a link to reset your password.</p>
    <form id="forgotPasswordForm" action="auth.php?action=forgot_password" method="post" data-api data-no-reload="true">
      <label for="forgotEmail">Email</label>
      <input type="email" id="forgotEmail" name="email" required>
      
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      
      <button type="submit">Send Reset Link</button>
      <button type="button" onclick="closeForgotPasswordModal()">Cancel</button>
      <div id="forgotPasswordMsg"></div>
    </form>
  </article>
</dialog>

<script src="assets/js/global.js"></script>
<script>
function openForgotPasswordModal(e) {
  e.preventDefault();
  document.getElementById('forgotPasswordModal').showModal();
}

function closeForgotPasswordModal() {
  document.getElementById('forgotPasswordModal').close();
}

// Handle forgot password form submission
const forgotForm = document.getElementById('forgotPasswordForm');
if (forgotForm) {
  let forgotErrorTimer = null;

  function showForgotMessage(html, type, keepVisibleMs) {
    const msgDiv = document.getElementById('forgotPasswordMsg');
    if (!msgDiv) return;

    if (forgotErrorTimer) {
      clearTimeout(forgotErrorTimer);
      forgotErrorTimer = null;
    }

    msgDiv.innerHTML = '<p style="margin-top: 8px; color: ' + type + ';">' + html + '</p>';

    if (keepVisibleMs && keepVisibleMs > 0) {
      forgotErrorTimer = setTimeout(() => {
        msgDiv.innerHTML = '';
        forgotErrorTimer = null;
      }, keepVisibleMs);
    }
  }

  forgotForm.addEventListener('submit', function(e) {
    window.addEventListener('api:success', (evt) => {
      if (evt.detail.form === forgotForm) {
        const json = evt.detail.json || {};
        if (json.bypass && json.reset_link) {
          alert('Email is not configured. Use the link below to reset your password.');
          showForgotMessage(
            '⚠️ Email not configured — use this link to reset your password:<br><a href="' + json.reset_link + '" target="_blank">Open reset link</a>',
            '#b26a00',
            0
          );
        } else {
          showForgotMessage('✓ Check your email for the reset link', 'green', 3000);
          setTimeout(() => {
            closeForgotPasswordModal();
            document.getElementById('forgotEmail').value = '';
          }, 3000);
        }
      }
    });

    window.addEventListener('api:error', (evt) => {
      if (evt.detail.form === forgotForm) {
        const errorMessage = evt.detail.json?.error || evt.detail.json?.message || 'Error sending reset link';
        showForgotMessage('✗ ' + errorMessage, 'red', 8000);
      }
    });
  });
}
</script>
</body>
</html>
