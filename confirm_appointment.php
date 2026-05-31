<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();

$siteName = get_site_name();
$supportMessage = get_support_contact_message($siteName);

$code = trim((string) ($_REQUEST['code'] ?? ''));
$action = trim((string) ($_POST['action'] ?? ''));
$feedbackMessage = '';
$feedbackClass = '';
$appointment = null;
$expired = false;
$alreadyConfirmed = false;

if ($code !== '') {
    $appointment = get_appointment_by_hash_key($code);
    if ($appointment) {
        $timezone = new DateTimeZone(get_app_timezone_name());
        $startDate = new DateTimeImmutable((string) $appointment['start_time'], $timezone);
        $now = new DateTimeImmutable('now', $timezone);
        $expired = $now >= $startDate;
        $alreadyConfirmed = strtolower(trim((string) ($appointment['status'] ?? ''))) === 'confirmed';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$expired) {
            if ($action === 'confirm' && !$alreadyConfirmed) {
                $db = get_db();
                $stmt = $db->prepare("UPDATE appointments SET status = 'confirmed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([(int) $appointment['id']]);
                $appointment = get_appointment_by_id((int) $appointment['id']);
                $alreadyConfirmed = true;
                $feedbackMessage = 'Thanks for confirming your appointment.';
                $feedbackClass = 'success';
            } elseif ($action === 'delete') {
                if (delete_appointment((int) $appointment['id'])) {
                    $feedbackMessage = 'Your appointment has been deleted. Please book a new appointment if you still need one.';
                    $feedbackClass = 'success';
                    $appointment = null;
                } else {
                    $feedbackMessage = 'We could not delete the appointment right now. Please contact the office.';
                    $feedbackClass = 'error';
                }
            }
        }
    }
}

if ($code !== '' && !$appointment && !$feedbackMessage) {
    $feedbackMessage = 'This appointment link is invalid, expired, or the appointment has already been removed.';
    $feedbackClass = 'error';
}

$customer = $appointment ? get_customer_by_id((int) ($appointment['customer_id'] ?? 0)) : null;
$provider = $appointment ? get_user_by_id((int) ($appointment['provider_id'] ?? 0)) : null;
$appointmentType = $appointment ? get_appointment_type_by_id((int) ($appointment['appointment_type_id'] ?? 0)) : null;
$providerName = $provider ? format_appointment_person_name($provider) : '';
$customerName = $customer ? trim((string) ($customer['full_name'] ?? '')) : '';
$customerMobile = $customer ? trim((string) ($customer['mobile'] ?? '')) : '';
$startLabel = $appointment ? format_app_datetime((string) ($appointment['start_time'] ?? '')) : '';
$endLabel = $appointment ? format_app_datetime((string) ($appointment['end_time'] ?? '')) : '';
$statusLabel = $appointment ? str_replace('_', ' ', (string) ($appointment['status'] ?? '')) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Confirm Appointment - <?php echo htmlspecialchars($siteName); ?></title>
  <link rel="stylesheet" href="https://unpkg.com/@picocss/pico@1.*/css/pico.min.css">
  <style>
    /* allow global theme to control page background */
    body {
      background: none;
    }

    main.container {
      max-width: 760px;
      padding-top: 3rem;
      padding-bottom: 3rem;
    }

    article {
      border-radius: 24px;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.10);
      border: 1px solid rgba(15, 23, 42, 0.08);
    }

    .status-banner {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      border-radius: 999px;
      font-weight: 700;
      margin-bottom: 1rem;
    }

    .status-banner.success {
      background: rgba(16, 185, 129, 0.12);
      color: #065f46;
    }

    .status-banner.error {
      background: rgba(220, 38, 38, 0.10);
      color: #991b1b;
    }

    .appointment-summary {
      display: grid;
      gap: 0.9rem;
      margin: 1.25rem 0;
      padding: 1rem;
      border-radius: 18px;
      background: rgba(36, 87, 255, 0.04);
      border: 1px solid rgba(36, 87, 255, 0.10);
    }

    .appointment-summary dl {
      margin: 0;
    }

    .appointment-summary dt {
      font-weight: 700;
      margin-top: 0.35rem;
      color: inherit;
    }

    .appointment-summary dd {
      margin: 0.15rem 0 0;
      color: inherit;
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 1.25rem;
    }

    .actions form {
      margin: 0;
    }

    .actions button,
    .actions a {
      margin: 0;
    }
  </style>
</head>
<body>
<main class="container">
  <article>
    <p class="status-banner <?php echo $feedbackClass === 'success' ? 'success' : ($feedbackClass === 'error' ? 'error' : ''); ?>">
      <?php echo htmlspecialchars($siteName); ?> appointment link
    </p>
    <h1>Confirm or modify your appointment</h1>

    <?php if ($feedbackMessage !== ''): ?>
      <p class="status-banner <?php echo $feedbackClass === 'success' ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($feedbackMessage); ?></p>
    <?php endif; ?>

    <?php if ($appointment): ?>
      <p>Review the details below. If this is correct, confirm the appointment. If you need to change it, delete it and create a new booking.</p>
      <section class="appointment-summary" aria-label="Appointment details">
        <dl>
          <dt>Appointment type</dt>
          <dd><?php echo htmlspecialchars((string) ($appointmentType['name'] ?? 'Appointment')); ?></dd>

          <dt>Provider</dt>
          <dd><?php echo htmlspecialchars($providerName !== '' ? $providerName : 'Not recorded'); ?></dd>

          <dt>Start time</dt>
          <dd><?php echo htmlspecialchars($startLabel !== '' ? $startLabel : 'Not recorded'); ?></dd>

          <dt>End time</dt>
          <dd><?php echo htmlspecialchars($endLabel !== '' ? $endLabel : 'Not recorded'); ?></dd>

          <dt>Customer full name</dt>
          <dd><?php echo htmlspecialchars($customerName !== '' ? $customerName : 'Not recorded'); ?></dd>

          <dt>Mobile number</dt>
          <dd><?php echo htmlspecialchars($customerMobile !== '' ? $customerMobile : 'Not recorded'); ?></dd>

          <dt>Status</dt>
          <dd><?php echo htmlspecialchars($statusLabel !== '' ? $statusLabel : 'booked'); ?></dd>
        </dl>
      </section>

      <?php if ($expired): ?>
        <p>This link has expired because the appointment start time has passed.</p>
      <?php else: ?>
        <div class="actions">
          <?php if (!$alreadyConfirmed): ?>
            <form method="post">
              <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
              <input type="hidden" name="action" value="confirm">
              <button type="submit">Confirm appointment</button>
            </form>
          <?php else: ?>
            <p>The appointment is already confirmed.</p>
          <?php endif; ?>

          <form method="post" onsubmit="return confirm('Delete this appointment? This cannot be undone.');">
            <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="secondary">Delete appointment and book a new one</button>
          </form>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p><?php echo htmlspecialchars($supportMessage); ?></p>
    <?php endif; ?>
  </article>
</main>
</body>
</html>
