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
    <p style="margin-bottom: 16px;">
      <a href="booking.php" role="button" class="secondary" style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.92rem; padding:0.45rem 0.8rem;">
        🗓️ Book an appointment
      </a>
    </p>
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
    <form id="forgotPasswordForm" action="auth.php?action=forgot_password" method="post" data-api>
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
  forgotForm.addEventListener('submit', function(e) {
    const msgDiv = document.getElementById('forgotPasswordMsg');
    
    window.addEventListener('api:success', (evt) => {
      if (evt.detail.form === forgotForm) {
        const json = evt.detail.json || {};
        if (json.bypass && json.reset_link) {
          msgDiv.innerHTML = '<p style="color: orange; margin-top: 8px;">⚠️ Email not configured — use this link to reset your password:</p>' +
            '<p><a href="' + json.reset_link + '" target="_blank">Open reset link</a></p>';
        } else {
          msgDiv.innerHTML = '<p style="color: green; margin-top: 8px;">✓ Check your email for the reset link</p>';
          setTimeout(() => {
            closeForgotPasswordModal();
            document.getElementById('forgotEmail').value = '';
            msgDiv.innerHTML = '';
          }, 3000);
        }
      }
    });

    window.addEventListener('api:error', (evt) => {
      if (evt.detail.form === forgotForm) {
        msgDiv.innerHTML = '<p style="color: red; margin-top: 8px;">✗ ' + (evt.detail.json.error || 'Error sending reset link') + '</p>';
      }
    });
  });
}
</script>
</body>
</html>
