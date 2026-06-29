<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();

$siteName = get_site_name(); // already exists in functions.php

$token  = trim((string) ($_GET['token'] ?? ''));
$result = null;   // 'confirm' | 'success' | 'already_cancelled' | 'too_late' | 'invalid'
$appt   = null;
$message = '';

if ($token === '') {
    $result  = 'invalid';
    $message = 'No cancellation token was provided.';
} else {
    $verification = get_verification_by_code_and_task($token, 'cancel_appointment');

    if (!$verification) {
        $result  = 'invalid';
        $message = 'This cancellation link is invalid or has already been used.';
    } else {
        $appt = get_appointment_by_id((int) $verification['appointment_id']);

        if (!$appt) {
            $result  = 'invalid';
            $message = 'The appointment associated with this link could not be found.';
        } elseif (in_array($appt['status'] ?? '', ['cancelled', 'no_show', 'completed'], true)) {
            $result  = 'already_cancelled';
            $message = 'This appointment has already been cancelled or completed.';
        }
        // Token expiry is already enforced by get_verification_by_code_and_task (expires_at > NOW())
        // If we land here with no $result set yet, token was valid and appt is active.
    }
}

// Handle POST confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $result === null && $appt) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $result  = 'invalid';
        $message = 'Security token mismatch. Please use the original link from your email.';
    } else {
        try {
            $db = get_db();
            $stmt = $db->prepare(
                "UPDATE appointments SET status = 'cancelled', updated_at = NOW()
                 WHERE id = ? AND status NOT IN ('cancelled','completed','no_show')"
            );
            $stmt->execute([(int) $appt['id']]);

            delete_verification_token($token); // reuse existing function

            $result  = 'success';
            $message = 'Your appointment has been cancelled successfully.';
            $appt    = get_appointment_by_id((int) $appt['id']);
        } catch (Throwable $e) {
            $result  = 'invalid';
            $message = 'An error occurred while cancelling. Please contact us directly.';
        }
    }
}

if ($result === null) {
    $result = 'confirm';
}

$csrfToken = csrf_token();

// Build display data from existing helpers
$apptDisplay = [];
if ($appt) {
    $provider = get_user_by_id((int) ($appt['provider_id'] ?? 0));
    $apptType = get_appointment_type_by_id((int) ($appt['appointment_type_id'] ?? 0));
    $customer = get_customer_by_id((int) ($appt['customer_id'] ?? 0));
    $apptDisplay = [
        'type'     => $apptType['name']       ?? 'Appointment',
        'provider' => format_appointment_person_name($provider ?? []),
        'start'    => format_app_datetime((string) ($appt['start_time'] ?? '')),
        'customer' => $customer['full_name']   ?? '',
    ];
}

$orgEmail = get_setting('organization_email', '');
$orgPhone = get_setting('organization_number', '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cancel Appointment – <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    /* Matches the existing booking UI variables */
    :root {
      --bg: #f4f7fb;
      --panel: rgba(255,255,255,0.92);
      --panel-strong: #ffffff;
      --text: #1d2433;
      --muted: #667085;
      --border: rgba(18,27,43,.12);
      --accent: #2457ff;
      --accent-strong: #1639b5;
      --danger: #d92d20;
      --success: #067647;
      --shadow: 0 24px 60px rgba(15,23,42,.12);
      --shadow-soft: 0 12px 32px rgba(15,23,42,.08);
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #0b1220; --panel: rgba(15,23,42,.88); --panel-strong: #111827;
        --text: #f3f6fb; --muted: #a8b3c7; --border: rgba(255,255,255,.12);
        --shadow: 0 24px 60px rgba(0,0,0,.35);
      }
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
      background:
        radial-gradient(circle at top left, rgba(36,87,255,.18), transparent 28%),
        radial-gradient(circle at 85% 18%, rgba(245,158,11,.14), transparent 24%),
        var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .card {
      width: min(520px, 100%);
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 28px;
      box-shadow: var(--shadow);
      padding: 36px 32px;
      backdrop-filter: blur(18px);
    }
    .logo {
      font-size: .88rem; color: var(--muted); margin-bottom: 28px;
      font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
    }
    h1 { margin: 0 0 10px; font-size: 1.6rem; letter-spacing: -.03em; }
    p  { margin: 0 0 18px; color: var(--muted); line-height: 1.65; }
    p a { color: var(--accent); }
    .icon { font-size: 2.4rem; margin-bottom: 12px; display: block; }

    /* Summary block — same pattern as booking.php .summary-item */
    .appt-summary {
      background: color-mix(in srgb, var(--accent) 8%, var(--panel-strong));
      border: 1px solid color-mix(in srgb, var(--accent) 18%, var(--border));
      border-radius: 18px;
      padding: 18px;
      margin: 20px 0 24px;
      display: grid;
      gap: 10px;
    }
    .appt-row { display: flex; gap: 10px; align-items: flex-start; }
    .appt-label {
      font-size: .78rem; text-transform: uppercase; letter-spacing: .07em;
      color: var(--muted); min-width: 80px; padding-top: 1px;
    }
    .appt-value { font-weight: 650; line-height: 1.4; }

    .warning-box {
      background: color-mix(in srgb, #f59e0b 16%, var(--panel-strong));
      border: 1px solid #f59e0b;
      border-radius: 14px;
      padding: 14px 16px;
      margin-bottom: 22px;
      font-size: .93rem;
      line-height: 1.55;
    }
    .btn {
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--border);
      background: var(--panel-strong);
      color: var(--text);
      border-radius: 999px;
      padding: 13px 22px;
      font: inherit; font-weight: 650;
      cursor: pointer; text-decoration: none;
      transition: transform .16s, box-shadow .16s;
    }
    .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-soft); }
    .btn.danger {
      background: linear-gradient(135deg, #ef4444, var(--danger));
      color: #fff; border-color: transparent;
    }
    .btn.ghost { background: transparent; }
    .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 6px; }
    .success-text { color: var(--success); }
    .danger-text   { color: var(--danger); }
  </style>
</head>
<body>
<div class="card">
  <div class="logo"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></div>

  <?php if ($result === 'confirm'): ?>
    <span class="icon">📅</span>
    <h1>Cancel your appointment?</h1>
    <p>You are about to cancel the following appointment. This action cannot be undone.</p>

    <?php if ($apptDisplay): ?>
    <div class="appt-summary">
      <?php if ($apptDisplay['customer']): ?>
      <div class="appt-row">
        <span class="appt-label">Name</span>
        <span class="appt-value"><?php echo htmlspecialchars($apptDisplay['customer'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <?php endif; ?>
      <div class="appt-row">
        <span class="appt-label">Type</span>
        <span class="appt-value"><?php echo htmlspecialchars($apptDisplay['type'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <div class="appt-row">
        <span class="appt-label">Provider</span>
        <span class="appt-value"><?php echo htmlspecialchars($apptDisplay['provider'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <div class="appt-row">
        <span class="appt-label">When</span>
        <span class="appt-value"><?php echo htmlspecialchars($apptDisplay['start'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    </div>
    <?php endif; ?>

    <div class="warning-box">
      ⚠️ Once cancelled, you will need to book a new appointment if you change your mind.
    </div>

    <form method="post" action="cancel_appointment.php?token=<?php echo urlencode($token); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <div class="actions">
        <a href="book.php" class="btn ghost">Keep my appointment</a>
        <button type="submit" class="btn danger">Yes, cancel this appointment</button>
      </div>
    </form>

  <?php elseif ($result === 'success'): ?>
    <span class="icon">✅</span>
    <h1 class="success-text">Appointment cancelled</h1>
    <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($apptDisplay): ?>
    <div class="appt-summary">
      <div class="appt-row">
        <span class="appt-label">Type</span>
        <span class="appt-value"><?php echo htmlspecialchars($apptDisplay['type'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <div class="appt-row">
        <span class="appt-label">Was scheduled</span>
        <span class="appt-value"><?php echo htmlspecialchars($apptDisplay['start'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    </div>
    <?php endif; ?>
    <p>Would you like to book a new appointment?</p>
    <a href="book.php" class="btn">Book a new appointment</a>

  <?php elseif ($result === 'already_cancelled'): ?>
    <span class="icon">ℹ️</span>
    <h1>Already cancelled</h1>
    <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <a href="book.php" class="btn">Book a new appointment</a>

  <?php else: /* invalid or too_late — token expired means get_verification_by_code_and_task returned null */ ?>
    <span class="icon">❌</span>
    <h1 class="danger-text">Invalid or expired link</h1>
    <p>This cancellation link is invalid, has expired, or has already been used.</p>
    <?php if ($orgEmail || $orgPhone): ?>
    <p>Please contact us directly:
      <?php if ($orgEmail): ?><a href="mailto:<?php echo htmlspecialchars($orgEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($orgEmail, ENT_QUOTES, 'UTF-8'); ?></a><?php endif; ?>
      <?php if ($orgEmail && $orgPhone): ?> · <?php endif; ?>
      <?php if ($orgPhone): ?><?php echo htmlspecialchars($orgPhone, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    </p>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>