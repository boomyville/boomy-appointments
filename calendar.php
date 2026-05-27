<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();
require_login();

$user = $_SESSION['user'] ?? [];
$role = (string) ($user['role'] ?? '');
$isStaff = in_array($role, ['admin', 'provider'], true);
$providerId = $isStaff && isset($user['id']) ? (int) $user['id'] : null;

$providers = [];
$appointmentTypes = [];

try {
  $db = get_db();

  try {
    $stmt = $db->query("SELECT id, full_name, email, role FROM users WHERE role IS NOT NULL AND role <> '' AND role <> 'customer' ORDER BY full_name ASC, id ASC");
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $providers = [];
  }

  try {
    $stmt = $db->query('SELECT id, name, duration_minutes, color_hex FROM appointment_types WHERE is_active = 1 ORDER BY id ASC');
    $appointmentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $appointmentTypes = [];
  }
} catch (Throwable $e) {
  $providers = [];
  $appointmentTypes = [];
}

$defaultProviderId = !empty($providers) ? (int) $providers[0]['id'] : null;
$defaultAppointmentTypeId = !empty($appointmentTypes) ? (int) $appointmentTypes[0]['id'] : null;
$bookingCalendarSettings = get_booking_calendar_settings();
$customerFieldSettings = get_customer_field_settings();
$serverTimezoneName = get_app_timezone_name();
$serverNowIso = (new DateTimeImmutable('now', new DateTimeZone($serverTimezoneName)))->format(DateTimeInterface::ATOM);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Calendar - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/admin-shared.css">
  <style>
    :root {
      --bg: #f4f7fb;
      --panel: rgba(255, 255, 255, 0.88);
      --panel-border: rgba(15, 23, 42, 0.08);
      --text: #0f172a;
      --muted: #5b6477;
      --accent: #2457ff;
      --accent-2: #10b981;
      --shadow: 0 24px 70px rgba(15, 23, 42, 0.12);
    }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(36, 87, 255, 0.14), transparent 35%),
        radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 28%),
        linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
    }

    .page-shell {
      max-width: 1280px;
      margin: 0 auto;
      padding: 28px 18px 42px;
    }

    .hero {
      display: flex;
      flex-wrap: wrap;
      align-items: end;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 20px;
    }

    .hero h1 {
      margin: 0;
      font-size: clamp(2rem, 3vw, 3rem);
      line-height: 1.05;
      letter-spacing: -0.03em;
    }

    .hero p {
      margin: 10px 0 0;
      color: var(--muted);
      max-width: 62ch;
    }

    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: flex-end;
    }

    .toolbar a,
    .toolbar button {
      border: 1px solid var(--panel-border);
      background: rgba(255, 255, 255, 0.8);
      color: var(--text);
      text-decoration: none;
      border-radius: 999px;
      padding: 10px 14px;
      font: inherit;
      cursor: pointer;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
    }

    .layout {
      display: grid;
      grid-template-columns: 1fr;
      gap: 18px;
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 28px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
      overflow: hidden;
    }

    .panel-header {
      padding: 18px 20px 0;
    }

    .panel-header-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .panel-header-row h2 {
      margin: 0;
    }

    .server-clock {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      border: 1px solid var(--panel-border);
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.55);
      color: var(--muted);
      font-weight: 700;
      white-space: nowrap;
    }

    html[data-theme="dark"] .server-clock {
      background: rgba(15, 23, 42, 0.45);
    }

    .server-clock strong {
      color: var(--text);
      font-variant-numeric: tabular-nums;
    }

    .panel-header small {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    #calendar {
      padding: 18px;
    }

    .fc {
      --fc-border-color: rgba(15, 23, 42, 0.08);
      --fc-page-bg-color: transparent;
      --fc-neutral-bg-color: #fff;
      --fc-today-bg-color: rgba(36, 87, 255, 0.06);
      --fc-event-text-color: #fff;
      --fc-button-bg-color: #f8fafc;
      --fc-button-border-color: rgba(15, 23, 42, 0.10);
      --fc-button-hover-bg-color: #e2e8f0;
      --fc-button-hover-border-color: rgba(15, 23, 42, 0.14);
      --fc-button-active-bg-color: #2457ff;
      --fc-button-active-border-color: #2457ff;
      --fc-button-text-color: #0f172a;
      --fc-event-border-color: transparent;
      --fc-list-event-hover-bg-color: rgba(36, 87, 255, 0.06);
      font-size: 0.95rem;
    }

    .fc .fc-daygrid-day.fc-day-today,
    .fc .fc-timegrid-col.fc-day-today {
      background: rgba(36, 87, 255, 0.10) !important;
    }

    .fc .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 1.6rem;
      min-height: 1.6rem;
      padding: 0 0.4rem;
      border-radius: 999px;
      background: #2457ff;
      color: #fff;
      font-weight: 800;
    }

    .fc .fc-timegrid-now-indicator-container {
      z-index: 6;
      pointer-events: none;
    }

    .fc .fc-timegrid-now-indicator-line {
      border-top: 3px solid #ef4444 !important;
      height: 0 !important;
      opacity: 1 !important;
      box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.12);
    }

    .fc .fc-timegrid-now-indicator-arrow {
      border-top-color: #ef4444 !important;
      border-bottom-color: #ef4444 !important;
      color: #ef4444 !important;
    }

    .booking-event {
      display: grid;
      gap: 2px;
      line-height: 1.15;
      font-size: 0.76rem;
      font-weight: 700;
      text-wrap: balance;
    }

    .booking-event__status {
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      opacity: 0.86;
    }

    .fc .server-today {
      background: rgba(36, 87, 255, 0.12) !important;
    }

    .fc .server-today .fc-col-header-cell-cushion,
    .fc .server-today .fc-daygrid-day-number {
      background: rgba(36, 87, 255, 0.18);
      border-radius: 999px;
      color: var(--text);
      font-weight: 800;
      padding: 0.15rem 0.55rem;
    }

    .fc .fc-toolbar-title {
      font-size: clamp(1.3rem, 2vw, 1.8rem);
      font-weight: 800;
      letter-spacing: -0.02em;
    }

    .fc .fc-button {
      border-radius: 999px !important;
      padding: 0.55rem 0.9rem;
      box-shadow: none;
    }

    .booking-modal {
      width: min(640px, calc(100vw - 24px));
      border: 0;
      padding: 0;
      border-radius: 28px;
      box-shadow: 0 28px 80px rgba(15, 23, 42, 0.18);
      background: transparent;
      margin: auto;
      position: fixed;
      inset: 0;
    }

    .booking-modal::backdrop {
      background: rgba(15, 23, 42, 0.5);
      backdrop-filter: blur(4px);
    }

    .booking-card {
      position: relative;
      background: var(--panel);
      border: 1px solid var(--panel-border);
      color: var(--text);
      border-radius: 28px;
      overflow: hidden;
    }

    .booking-card__head {
      padding: 16px 18px;
      background: transparent;
      color: var(--text);
      border-bottom: 1px solid var(--panel-border);
    }

    .booking-card__head h2 {
      margin: 0;
      font-size: 1.15rem;
      letter-spacing: -0.02em;
    }

    .booking-card__body {
      padding: 16px 18px 18px;
      display: grid;
      gap: 12px;
    }

    .booking-modal-alert {
      display: none;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid var(--panel-border);
      background: rgba(36, 87, 255, 0.06);
      color: var(--text);
      font-size: 0.88rem;
      line-height: 1.45;
    }

    .booking-modal-alert.is-visible {
      display: block;
    }

    .booking-modal-alert.is-error {
      background: rgba(239, 68, 68, 0.08);
      border-color: rgba(239, 68, 68, 0.18);
      color: #7f1d1d;
    }

    html[data-theme="dark"] .booking-modal-alert {
      background: rgba(30, 50, 80, 0.45);
    }

    html[data-theme="dark"] .booking-modal-alert.is-error {
      background: rgba(127, 29, 29, 0.24);
      color: #fecaca;
    }

    .booking-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .booking-grid .span-2 {
      grid-column: span 2;
    }

    .field label {
      display: block;
      font-size: 0.8rem;
      font-weight: 700;
      margin-bottom: 4px;
      color: var(--label);
    }

    .field input,
    .field select,
    .field textarea {
      width: 100%;
      border: 1px solid rgba(15, 23, 42, 0.15);
      border-radius: 14px;
      padding: 10px 12px;
      font: inherit;
      color: var(--text);
      background: #fff;
      box-sizing: border-box;
      font-size: 0.92rem;
    }

    html[data-theme="dark"] .field input,
    html[data-theme="dark"] .field select,
    html[data-theme="dark"] .field textarea,
    html[data-theme="dark"] #existingCustomerSelect {
      background: rgba(15, 23, 42, 0.5) !important;
      border-color: rgba(226, 232, 240, 0.12) !important;
      color: var(--text) !important;
    }

    .field input:focus,
    .field select:focus,
    .field textarea:focus {
      outline: 3px solid rgba(36, 87, 255, 0.14);
      border-color: rgba(36, 87, 255, 0.5);
    }

    .booking-note {
      margin: 0;
      color: var(--muted);
      font-size: 0.94rem;
    }

    .booking-meta {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      padding: 12px 14px;
      border: 1px dashed var(--panel-border);
      border-radius: 18px;
      background: var(--panel);
    }

    .meta-item {
      display: grid;
      gap: 4px;
    }

    .meta-item span {
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      font-weight: 700;
    }

    .meta-item strong {
      font-size: 0.9rem;
      font-weight: 700;
      color: var(--text);
    }

    .booking-card__footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 0 18px 18px;
    }

    .booking-card__footer .secondary {
      color: var(--muted);
      font-size: 0.92rem;
    }

    .booking-card__footer button {
      border: 1px solid var(--panel-border);
      border-radius: 999px;
      padding: 9px 14px;
      background: rgba(255, 255, 255, 0.06);
      color: var(--text);
      font: inherit;
      font-weight: 700;
      cursor: pointer;
    }

    .booking-card__footer .primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    .booking-card__footer .primary:hover {
      opacity: 0.95;
    }

    .booking-card__close {
      position: absolute;
      top: 14px;
      right: 14px;
      width: 34px;
      height: 34px;
      border: 1px solid rgba(239, 68, 68, 0.25);
      border-radius: 999px;
      background: rgba(239, 68, 68, 0.12);
      color: #dc2626;
      font-size: 1.2rem;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      z-index: 2;
    }

    .booking-card__close:hover {
      background: rgba(239, 68, 68, 0.18);
      border-color: rgba(239, 68, 68, 0.4);
    }

    .booking-warning-modal {
      width: min(520px, calc(100vw - 24px));
      border: 0;
      padding: 0;
      border-radius: 28px;
      box-shadow: 0 28px 80px rgba(15, 23, 42, 0.22);
      background: transparent;
      margin: auto;
      position: fixed;
      inset: 0;
    }

    .booking-warning-modal::backdrop {
      background: rgba(15, 23, 42, 0.5);
      backdrop-filter: blur(4px);
    }

    .booking-warning-card {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      color: var(--text);
      border-radius: 28px;
      overflow: hidden;
    }

    .booking-warning-card__head {
      padding: 16px 18px;
      border-bottom: 1px solid var(--panel-border);
    }

    .booking-warning-card__head h2 {
      margin: 0;
      font-size: 1.15rem;
      letter-spacing: -0.02em;
    }

    .booking-warning-card__body {
      padding: 16px 18px 18px;
    }

    .booking-warning-card__body p {
      margin: 0;
      color: var(--text);
      line-height: 1.5;
    }

    .booking-warning-card__footer {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      padding: 0 18px 18px;
    }

    .booking-warning-card__footer button {
      border: 1px solid var(--panel-border);
      border-radius: 999px;
      padding: 9px 14px;
      background: rgba(255, 255, 255, 0.06);
      color: var(--text);
      font: inherit;
      font-weight: 700;
      cursor: pointer;
    }

    .booking-warning-card__footer .primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    html[data-theme="dark"] .booking-card__footer button {
      background: rgba(255, 255, 255, 0.04);
    }

    html[data-theme="dark"] .booking-warning-card__footer button {
      background: rgba(255, 255, 255, 0.04);
    }

    html[data-theme="dark"] .booking-warning-card__footer .primary {
      background: var(--accent);
      color: #fff;
    }

    .booking-card__footer button:hover {
      background: rgba(36, 87, 255, 0.08);
    }

    .booking-mode-toggle,
    .booking-mode-toggle-secondary {
      border: 1px solid var(--panel-border);
      color: var(--text);
      padding: 8px 12px;
      border-radius: 8px;
      font-size: 0.9rem;
      cursor: pointer;
      font-weight: 600;
      background: rgba(255, 255, 255, 0.18);
    }

    html[data-theme="dark"] .booking-mode-toggle,
    html[data-theme="dark"] .booking-mode-toggle-secondary {
      background: rgba(255, 255, 255, 0.05);
    }

    .booking-mode-toggle.is-active,
    .booking-mode-toggle-secondary.is-active {
      background: rgba(36, 87, 255, 0.10);
    }

    .booking-time-summary {
      display: none;
    }

    .booking-modal[data-mode="appointment"] .booking-draft-only {
      display: none;
    }

    .booking-modal[data-mode="appointment"] .booking-booking-action {
      display: none;
    }

    .booking-modal[data-mode="appointment"] .booking-card__footer .secondary {
      display: none;
    }

    .booking-modal[data-mode="appointment"] .booking-card__footer {
      justify-content: flex-end;
    }

    @media (max-width: 760px) {
      .booking-grid,
      .booking-meta {
        grid-template-columns: 1fr;
      }

      .booking-grid .span-2 {
        grid-column: auto;
      }

      .booking-card__footer {
        flex-direction: column-reverse;
        align-items: stretch;
      }

      .booking-card__footer button {
        width: 100%;
      }

      .booking-card__close {
        top: 12px;
        right: 12px;
      }
    }
  </style>
</head>
<body>
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
  <?php
    $current_page = 'calendar.php';
    $navAssets = __DIR__ . '/assets/nav.html';
    $navIncludes = __DIR__ . '/includes/nav.html';

    if (is_file($navAssets)) {
        include $navAssets;
    } elseif (is_file($navIncludes)) {
        include $navIncludes;
    }
  ?>
  
  <div class="admin-container">
    <div class="admin-header">
      <div>
        <h1>Appointments Calendar</h1>
        <p>Click a time slot to start a booking draft. Provider and appointment type options are loaded from the database.</p>
      </div>
    </div>

    <div class="admin-panel">
      <div class="panel-header-row">
        <h2>Schedule Board</h2>
        <div class="server-clock" aria-live="polite">
          <span>Server time</span>
          <strong id="serverClockValue"></strong>
        </div>
      </div>
      <div id="calendar"></div>
    </div>
  </div>

  <dialog id="bookingModal" class="booking-modal">
    <div class="booking-card">
      <button type="button" id="closeBookingModalTop" class="booking-card__close" aria-label="Close booking modal">&times;</button>
      <div class="booking-card__head">
        <h2 id="bookingModalTitle">New booking draft</h2>
      </div>
      <div class="booking-card__body">
        <div id="bookingModalAlert" class="booking-modal-alert"></div>

        <div class="booking-meta">
          <div class="meta-item">
            <span>Start</span>
            <strong id="selectedStartLabel">-</strong>
          </div>
          <div class="meta-item">
            <span>End</span>
            <strong id="selectedEndLabel">-</strong>
          </div>
        </div>

        <div class="booking-grid booking-draft-only">
          <div class="field span-2">
            <label>Who do I want to book?</label>
            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
              <button type="button" id="toggleCreateMode" class="booking-mode-toggle is-active">+ Create new customer</button>
              <button type="button" id="toggleSelectMode" class="booking-mode-toggle-secondary" style="display:none;">or Select existing</button>
            </div>

            <div id="createCustomerSection">
              <div class="field">
                <label for="newCustomerName">Customer Name</label>
                <input id="newCustomerName" name="new_customer_name" type="text" placeholder="Full name">
              </div>
              <div class="field">
                <label for="newCustomerDob">Date of Birth</label>
                <input id="newCustomerDob" name="date_of_birth" type="date">
              </div>
              <div class="field">
                <label for="newCustomerEmail">Email (optional)</label>
                <input id="newCustomerEmail" name="new_customer_email" type="email" placeholder="customer@example.com">
              </div>
              <div class="field">
                <label for="newCustomerMobile">Mobile (optional)</label>
                <input id="newCustomerMobile" name="new_customer_mobile" type="text" placeholder="1234567890">
              </div>
            </div>

            <div id="selectCustomerSection" style="display:none;">
              <select id="existingCustomerSelect" style="width: 100%; border: 1px solid var(--panel-border); border-radius: 16px; padding: 13px 14px; font: inherit; color: var(--text); background: var(--panel); box-sizing: border-box;">
                <option value="">-- Search and select customer --</option>
              </select>
            </div>
            
            <input type="hidden" id="selectedCustomerId" value="">
          </div>

          <div class="field">
            <label for="providerSelect">Which provider?</label>
            <select id="providerSelect" name="provider_id">
              <?php foreach ($providers as $index => $provider): ?>
                <option value="<?php echo (int) $provider['id']; ?>" <?php echo $index === 0 ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($provider['full_name'] ?: $provider['email']); ?>
                  <?php if (!empty($provider['role'])): ?>
                    (<?php echo htmlspecialchars($provider['role']); ?>)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="appointmentTypeSelect">Type of appointment</label>
            <select id="appointmentTypeSelect" name="appointment_type_id">
              <?php foreach ($appointmentTypes as $index => $appointmentType): ?>
                <option value="<?php echo (int) $appointmentType['id']; ?>" data-duration="<?php echo (int) $appointmentType['duration_minutes']; ?>" <?php echo $index === 0 ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($appointmentType['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field span-2">
            <label for="bookingNotes">Notes</label>
            <textarea id="bookingNotes" name="notes" rows="3" placeholder="Optional notes for the booking draft"></textarea>
          </div>
        </div>

        <input type="hidden" id="bookingStart" value="">
        <input type="hidden" id="bookingEnd" value="">
      </div>
      <div class="booking-card__footer">
        <button type="button" id="makeBookingBtn" class="primary booking-booking-action">Make booking</button>
        <button type="button" id="closeBookingModal">Close</button>
      </div>
    </div>
  </dialog>

  <dialog id="bookingWarningModal" class="booking-warning-modal">
    <div class="booking-warning-card">
      <div class="booking-warning-card__head">
        <h2>Confirm booking</h2>
      </div>
      <div class="booking-warning-card__body">
        <p id="bookingWarningMessage"></p>
      </div>
      <div class="booking-warning-card__footer">
        <button type="button" id="bookingWarningCancel">No</button>
        <button type="button" id="bookingWarningConfirm" class="primary">Yes, continue</button>
      </div>
    </div>
  </dialog>

  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
  <script>
    const isStaff = <?php echo json_encode($isStaff); ?>;
    const providerId = <?php echo json_encode($providerId); ?>;
    const providers = <?php echo json_encode($providers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const appointmentTypes = <?php echo json_encode($appointmentTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const defaultProviderId = <?php echo json_encode($defaultProviderId); ?>;
    const defaultAppointmentTypeId = <?php echo json_encode($defaultAppointmentTypeId); ?>;
    const bookingCalendarSettings = <?php echo json_encode($bookingCalendarSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const customerFieldSettings = <?php echo json_encode($customerFieldSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const serverTimezoneName = <?php echo json_encode($serverTimezoneName); ?>;
    let serverNowIso = <?php echo json_encode($serverNowIso); ?>;

    const calendarEl = document.getElementById('calendar');
    const serverClockValue = document.getElementById('serverClockValue');
    const bookingModal = document.getElementById('bookingModal');
    const closeBookingModal = document.getElementById('closeBookingModal');
    const selectedStartLabel = document.getElementById('selectedStartLabel');
    const selectedEndLabel = document.getElementById('selectedEndLabel');
    const bookingModalTitle = document.getElementById('bookingModalTitle');
    const bookingModalAlert = document.getElementById('bookingModalAlert');
    const bookingWarningModal = document.getElementById('bookingWarningModal');
    const bookingWarningMessage = document.getElementById('bookingWarningMessage');
    const bookingWarningCancel = document.getElementById('bookingWarningCancel');
    const bookingWarningConfirm = document.getElementById('bookingWarningConfirm');
    const bookingStart = document.getElementById('bookingStart');
    const bookingEnd = document.getElementById('bookingEnd');
    const closeBookingModalTop = document.getElementById('closeBookingModalTop');
    const makeBookingBtn = document.getElementById('makeBookingBtn');
    const providerSelect = document.getElementById('providerSelect');
    const appointmentTypeSelect = document.getElementById('appointmentTypeSelect');
    let currentBookingStartDate = null;
    let currentBookingEndDate = null;

    const fallbackDurationMinutes = appointmentTypes.length > 0 ? Number(appointmentTypes[0].duration_minutes || 30) : 30;

    const visibleDaysOfWeek = [];
    if (bookingCalendarSettings.show_sunday) visibleDaysOfWeek.push(0);
    if (bookingCalendarSettings.show_monday) visibleDaysOfWeek.push(1);
    if (bookingCalendarSettings.show_tuesday) visibleDaysOfWeek.push(2);
    if (bookingCalendarSettings.show_wednesday) visibleDaysOfWeek.push(3);
    if (bookingCalendarSettings.show_thursday) visibleDaysOfWeek.push(4);
    if (bookingCalendarSettings.show_friday) visibleDaysOfWeek.push(5);
    if (bookingCalendarSettings.show_saturday) visibleDaysOfWeek.push(6);
    if (!visibleDaysOfWeek.length) {
      visibleDaysOfWeek.push(1, 2, 3, 4, 5);
    }

    function normalizeTimeValue(value, fallback) {
      const raw = String(value || fallback || '09:00').trim();
      if (/^\d{2}:\d{2}$/.test(raw)) {
        return raw + ':00';
      }
      if (/^\d{2}:\d{2}:\d{2}$/.test(raw)) {
        return raw;
      }
      return fallback;
    }

    const visibleStartTime = normalizeTimeValue(bookingCalendarSettings.start_time, '09:00:00');
    const visibleEndTime = normalizeTimeValue(bookingCalendarSettings.end_time, '21:00:00');
    let serverNowBaseMs = Date.parse(serverNowIso);
    let clientLoadMs = Date.now();
    const serverNowFormatter = new Intl.DateTimeFormat(undefined, {
      weekday: 'short',
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit',
      timeZone: serverTimezoneName
    });

    function getTimeZoneDateParts(date, timeZone) {
      const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23'
      }).formatToParts(date);

      const values = {};
      parts.forEach(function(part) {
        if (part.type !== 'literal') {
          values[part.type] = part.value;
        }
      });

      return {
        year: Number(values.year),
        month: Number(values.month),
        day: Number(values.day),
        hour: Number(values.hour),
        minute: Number(values.minute),
        second: Number(values.second),
        millisecond: date.getUTCMilliseconds()
      };
    }

    function parseDateValue(value) {
      if (value instanceof Date) {
        return new Date(value.getTime());
      }

      const raw = String(value || '').trim();
      if (!raw) {
        return new Date(NaN);
      }

      if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(raw)) {
        return new Date(raw);
      }

      const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
      return new Date(normalized + 'Z');
    }

    function getServerClockParts(date) {
      return getTimeZoneDateParts(date, serverTimezoneName);
    }

    function getServerClockDate(date) {
      const parts = getServerClockParts(date);
      return new Date(Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second, parts.millisecond));
    }

    function getTimeZoneOffsetMs(date, timeZone) {
      const parts = getTimeZoneDateParts(date, timeZone);
      const utcGuess = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second, parts.millisecond);
      return utcGuess - date.getTime();
    }

    function serverClockDateToInstant(date) {
      const utcGuess = Date.UTC(
        date.getUTCFullYear(),
        date.getUTCMonth(),
        date.getUTCDate(),
        date.getUTCHours(),
        date.getUTCMinutes(),
        date.getUTCSeconds(),
        date.getUTCMilliseconds()
      );

      let adjusted = new Date(utcGuess - getTimeZoneOffsetMs(new Date(utcGuess), serverTimezoneName));
      const refinedOffset = getTimeZoneOffsetMs(adjusted, serverTimezoneName);
      if (refinedOffset !== getTimeZoneOffsetMs(new Date(utcGuess), serverTimezoneName)) {
        adjusted = new Date(utcGuess - refinedOffset);
      }

      return adjusted;
    }

    function formatServerClockDateTime(date) {
      const parts = {
        year: date.getUTCFullYear(),
        month: date.getUTCMonth() + 1,
        day: date.getUTCDate(),
        hour: date.getUTCHours(),
        minute: date.getUTCMinutes(),
        second: date.getUTCSeconds()
      };
      const year = String(parts.year).padStart(4, '0');
      const month = String(parts.month).padStart(2, '0');
      const day = String(parts.day).padStart(2, '0');
      const hour = String(parts.hour).padStart(2, '0');
      const minute = String(parts.minute).padStart(2, '0');
      const second = String(parts.second).padStart(2, '0');
      return year + '-' + month + '-' + day + ' ' + hour + ':' + minute + ':' + second;
    }

    function toServerClockIso(value) {
      const instant = parseDateValue(value);
      if (Number.isNaN(instant.getTime())) {
        return value;
      }

      return getServerClockDate(instant).toISOString();
    }

    function isSameServerCalendarDay(dateA, dateB) {
      const partsA = getServerClockParts(dateA);
      const partsB = getServerClockParts(dateB);
      return partsA.year === partsB.year && partsA.month === partsB.month && partsA.day === partsB.day;
    }

    function getServerNowDate() {
      return new Date(serverNowBaseMs + (Date.now() - clientLoadMs));
    }

    function timeToSeconds(timeValue) {
      const parts = String(timeValue || '00:00:00').split(':').map(Number);
      const hours = Number.isFinite(parts[0]) ? parts[0] : 0;
      const minutes = Number.isFinite(parts[1]) ? parts[1] : 0;
      const seconds = Number.isFinite(parts[2]) ? parts[2] : 0;
      return (hours * 3600) + (minutes * 60) + seconds;
    }

    function secondsToTime(totalSeconds) {
      const safeSeconds = Math.max(0, Math.floor(totalSeconds));
      const hours = String(Math.floor(safeSeconds / 3600)).padStart(2, '0');
      const minutes = String(Math.floor((safeSeconds % 3600) / 60)).padStart(2, '0');
      const seconds = String(safeSeconds % 60).padStart(2, '0');
      return hours + ':' + minutes + ':' + seconds;
    }

    function secondsToHourTime(totalSeconds) {
      const safeSeconds = Math.max(0, Math.floor(totalSeconds));
      const hours = String(Math.floor(safeSeconds / 3600)).padStart(2, '0');
      return hours + ':00:00';
    }

    function getEffectiveSlotRange() {
      const nowParts = getServerClockParts(getServerNowDate());
      const nowSeconds = (nowParts.hour * 3600) + (nowParts.minute * 60) + nowParts.second;
      const startSeconds = timeToSeconds(visibleStartTime);
      const endSeconds = timeToSeconds(visibleEndTime);
      let slotMinTime = visibleStartTime;
      let slotMaxTime = secondsToHourTime(endSeconds + 3600);

      if (nowSeconds < startSeconds) {
        slotMinTime = secondsToHourTime(Math.max(0, nowSeconds - 3600));
      }

      if (nowSeconds > endSeconds) {
        slotMaxTime = secondsToHourTime(nowSeconds + 3600);
      }

      return { slotMinTime, slotMaxTime };
    }

    function updateServerClock() {
      if (!serverClockValue) return;
      serverClockValue.textContent = serverNowFormatter.format(getServerNowDate());
    }

    async function syncServerClock() {
      try {
        const response = await fetch('time.php?format=c', { credentials: 'same-origin' });
        if (!response.ok) return;
        const data = await response.json();
        if (!data || !data.ok || !data.iso) return;
        serverNowIso = data.iso;
        serverNowBaseMs = Date.parse(serverNowIso);
        clientLoadMs = Date.now();
        updateServerClock();
        if (window.calendarInstance) {
          window.calendarInstance.setOption('now', getServerClockDate(getServerNowDate()));
          const effectiveRange = getEffectiveSlotRange();
          window.calendarInstance.setOption('slotMinTime', effectiveRange.slotMinTime);
          window.calendarInstance.setOption('slotMaxTime', effectiveRange.slotMaxTime);
          window.calendarInstance.setOption('scrollTime', effectiveRange.slotMinTime);
        }
      } catch (err) {
        console.warn('Unable to sync server clock', err);
      }
    }

    updateServerClock();
    syncServerClock();
    setInterval(updateServerClock, 1000);
    setInterval(syncServerClock, 60000);

    function getSelectedDurationMinutes() {
      const option = appointmentTypeSelect.options[appointmentTypeSelect.selectedIndex];
      const parsed = Number(option?.dataset?.duration || fallbackDurationMinutes);
      return Number.isFinite(parsed) && parsed > 0 ? parsed : fallbackDurationMinutes;
    }

    function addMinutes(date, minutes) {
      return new Date(date.getTime() + minutes * 60000);
    }

    function formatCalendarDate(calendar, date) {
      return calendar.formatDate(date, {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
      });
    }

    function formatCompactTime(date) {
      const parts = {
        hour: date.getUTCHours(),
        minute: date.getUTCMinutes()
      };
      const hours24 = parts.hour;
      const minutes = String(parts.minute).padStart(2, '0');
      const isPm = hours24 >= 12;
      const hours12 = hours24 % 12 || 12;
      return hours12 + ':' + minutes + (isPm ? 'pm' : 'am');
    }

    function formatTimeRange(startDate, endDate) {
      const endLabel = endDate ? formatCompactTime(endDate) : 'TBA';
      return formatCompactTime(startDate) + ' to ' + endLabel;
    }

    function syncBookingDraftTimes() {
      if (!currentBookingStartDate || bookingModal.getAttribute('data-mode') !== 'draft') {
        return;
      }

      const calendar = window.calendarInstance;
      currentBookingEndDate = addMinutes(currentBookingStartDate, getSelectedDurationMinutes());
      selectedStartLabel.textContent = formatCalendarDate(calendar, currentBookingStartDate);
      selectedEndLabel.textContent = formatCalendarDate(calendar, currentBookingEndDate);
      bookingStart.value = formatServerClockDateTime(currentBookingStartDate);
      bookingEnd.value = formatServerClockDateTime(currentBookingEndDate);
    }

    function openBookingModal(startDate, endDate, options = {}) {
      const calendar = window.calendarInstance;
      const mode = options.mode || 'draft';
      const derivedEndDate = mode === 'draft'
        ? addMinutes(startDate, getSelectedDurationMinutes())
        : (endDate || addMinutes(startDate, getSelectedDurationMinutes()));

      currentBookingStartDate = startDate;
      currentBookingEndDate = derivedEndDate;

      bookingModal.setAttribute('data-mode', mode);
      bookingModalTitle.textContent = options.title || (mode === 'appointment' ? 'Appointment details' : 'New booking draft');

      if (mode === 'draft') {
        syncBookingDraftTimes();
      } else {
        selectedStartLabel.textContent = formatCalendarDate(calendar, startDate);
        selectedEndLabel.textContent = formatCalendarDate(calendar, derivedEndDate);
        bookingStart.value = formatServerClockDateTime(startDate);
        bookingEnd.value = formatServerClockDateTime(derivedEndDate);
      }

      if (defaultProviderId && !providerSelect.value) {
        providerSelect.value = String(defaultProviderId);
      } else if (providerId && providerSelect.querySelector(`option[value="${providerId}"]`)) {
        providerSelect.value = String(providerId);
      }

      if (defaultAppointmentTypeId && !appointmentTypeSelect.value) {
        appointmentTypeSelect.value = String(defaultAppointmentTypeId);
        if (mode === 'draft') {
          syncBookingDraftTimes();
        }
      }

      if (typeof bookingModal.showModal === 'function') {
        bookingModal.showModal();
      } else {
        bookingModal.setAttribute('open', 'open');
      }
    }

    const calendar = new FullCalendar.Calendar(calendarEl, {
      now: getServerClockDate(getServerNowDate()),
      initialView: isStaff ? 'timeGridWeek' : 'timeGridDay',
      height: 'auto',
      expandRows: true,
      nowIndicator: Boolean(bookingCalendarSettings.show_now_indicator),
      selectable: true,
      timeZone: 'UTC',
      selectMirror: true,
      hiddenDays: [0, 1, 2, 3, 4, 5, 6].filter(function(day) {
        return visibleDaysOfWeek.indexOf(day) === -1;
      }),
      ...getEffectiveSlotRange(),
      scrollTime: getEffectiveSlotRange().slotMinTime,
      slotEventOverlap: true,
      eventContent: function(arg) {
        const wrapper = document.createElement('div');
        wrapper.className = 'booking-event';

        const title = document.createElement('div');
        title.className = 'booking-event__title';
        title.textContent = arg.event.extendedProps.customer_name || arg.event.title || 'Appointment';

        const status = document.createElement('div');
        status.className = 'booking-event__status';
        status.textContent = String(arg.event.extendedProps.status || 'booked');

        wrapper.appendChild(title);
        wrapper.appendChild(status);
        return { domNodes: [wrapper] };
      },
      businessHours: {
        daysOfWeek: visibleDaysOfWeek,
        startTime: visibleStartTime,
        endTime: visibleEndTime
      },
      dayCellDidMount: function(arg) {
        const serverNow = getServerNowDate();
        if (isSameServerCalendarDay(arg.date, serverNow)) {
          arg.el.classList.add('server-today');
        }
      },
      dayHeaderDidMount: function(arg) {
        const serverNow = getServerNowDate();
        if (isSameServerCalendarDay(arg.date, serverNow)) {
          arg.el.classList.add('server-today');
        }
      },
      selectConstraint: 'businessHours',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay'
      },
      events: {
        url: 'calendar_api.php',
        method: 'GET',
        extraParams: function() {
          const params = {};
          if (providerId) {
            params.provider_id = providerId;
          }
          return params;
        },
        failure: () => alert('Could not load appointments')
      },
      dateClick: function(info) {
        openBookingModal(info.date);
      },
      select: function(info) {
        openBookingModal(info.start);
      },
      editable: isStaff,
      eventClick: function(info) {
        const startDate = info.event.start;
        const endDate = info.event.end || addMinutes(startDate, getSelectedDurationMinutes());
        const status = info.event.extendedProps.status || 'unknown';
        const detailText = formatTimeRange(startDate, endDate) + ' • Status: ' + status;
        openBookingModal(startDate, endDate, {
          mode: 'appointment',
          title: (info.event.extendedProps.customer_name || info.event.title || 'Appointment') + ' • ' + status,
          description: detailText
        });
      },
      eventDrop: function(info) {
        alert('Drag & drop is not yet implemented on the server.');
        info.revert();
      }
    });

    window.calendarInstance = calendar;
    calendar.render();

    // Customer mode toggle
    const createCustomerSection = document.getElementById('createCustomerSection');
    const selectCustomerSection = document.getElementById('selectCustomerSection');
    const toggleCreateMode = document.getElementById('toggleCreateMode');
    const toggleSelectMode = document.getElementById('toggleSelectMode');
    const existingCustomerSelect = document.getElementById('existingCustomerSelect');
    const newCustomerName = document.getElementById('newCustomerName');
    const newCustomerDob = document.getElementById('newCustomerDob');
    const newCustomerEmail = document.getElementById('newCustomerEmail');
    const newCustomerMobile = document.getElementById('newCustomerMobile');
    const selectedCustomerId = document.getElementById('selectedCustomerId');

    function setBookingAlert(message, isError = true) {
      if (!bookingModalAlert) {
        return;
      }

      if (!message) {
        bookingModalAlert.textContent = '';
        bookingModalAlert.classList.remove('is-visible', 'is-error');
        return;
      }

      bookingModalAlert.textContent = message;
      bookingModalAlert.classList.add('is-visible');
      bookingModalAlert.classList.toggle('is-error', Boolean(isError));
    }

    function getServerTimePartFromDateTime(value) {
      const raw = String(value || '').trim();
      if (!raw) {
        return '';
      }

      const parts = raw.includes('T') ? raw.split('T') : raw.split(' ');
      return (parts[1] || '').slice(0, 8);
    }

    function isBookingOutsideDisplayTime() {
      const startTimePart = getServerTimePartFromDateTime(bookingStart.value);
      const endTimePart = getServerTimePartFromDateTime(bookingEnd.value);

      if (!startTimePart || !endTimePart) {
        return false;
      }

      const bookingStartSeconds = timeToSeconds(startTimePart);
      const bookingEndSeconds = timeToSeconds(endTimePart);
      const displayStartSeconds = timeToSeconds(visibleStartTime);
      const displayEndSeconds = timeToSeconds(visibleEndTime);

      return bookingStartSeconds < displayStartSeconds || bookingEndSeconds > displayEndSeconds;
    }

    function getCustomerMissingRequiredFields() {
      if (selectedCustomerId.value.trim()) {
        return [];
      }

      const missing = [];
      const fullName = newCustomerName.value.trim();
      const nameParts = fullName.split(/\s+/).filter(Boolean);
      const emailValue = newCustomerEmail.value.trim();
      const mobileValue = newCustomerMobile.value.trim();
      const dobValue = newCustomerDob.value.trim();

      const firstNameRequired = Boolean(customerFieldSettings.first_name && customerFieldSettings.first_name.required);
      const lastNameRequired = Boolean(customerFieldSettings.last_name && customerFieldSettings.last_name.required);
      const middleNameRequired = Boolean(customerFieldSettings.middle_name && customerFieldSettings.middle_name.required);
      const emailRequired = Boolean(customerFieldSettings.email && customerFieldSettings.email.required);
      const mobileRequired = Boolean(customerFieldSettings.mobile && customerFieldSettings.mobile.required);
      const dobRequired = Boolean(customerFieldSettings.date_of_birth && customerFieldSettings.date_of_birth.required);

      if (firstNameRequired && nameParts.length < 1) {
        missing.push('first name');
      }
      if (lastNameRequired && nameParts.length < 2) {
        missing.push('last name');
      }
      if (middleNameRequired && nameParts.length < 3) {
        missing.push('middle name');
      }
      if (emailRequired && !emailValue) {
        missing.push('email');
      }
      if (mobileRequired && !mobileValue) {
        missing.push('mobile number');
      }
      if (dobRequired && !dobValue) {
        missing.push('date of birth');
      }

      return missing;
    }

    function showBookingWarning(message) {
      return new Promise((resolve) => {
        if (!bookingWarningModal) {
          resolve(false);
          return;
        }

        bookingWarningMessage.textContent = message;

        const handleConfirm = () => {
          cleanup();
          resolve(true);
        };

        const handleCancel = () => {
          cleanup();
          resolve(false);
        };

        const cleanup = () => {
          bookingWarningConfirm.removeEventListener('click', handleConfirm);
          bookingWarningCancel.removeEventListener('click', handleCancel);
          if (typeof bookingWarningModal.close === 'function') {
            bookingWarningModal.close();
          } else {
            bookingWarningModal.removeAttribute('open');
          }
        };

        bookingWarningConfirm.addEventListener('click', handleConfirm);
        bookingWarningCancel.addEventListener('click', handleCancel);
        bookingWarningModal.addEventListener('cancel', function(event) {
          event.preventDefault();
          handleCancel();
        }, { once: true });

        if (typeof bookingWarningModal.showModal === 'function') {
          bookingWarningModal.showModal();
        } else {
          bookingWarningModal.setAttribute('open', 'open');
        }
      });
    }

    // Sync customer selection
    existingCustomerSelect.addEventListener('change', function() {
      selectedCustomerId.value = this.value;
    });

    function setCreateMode() {
      createCustomerSection.style.display = 'block';
      selectCustomerSection.style.display = 'none';
      toggleCreateMode.classList.add('is-active');
      toggleSelectMode.classList.remove('is-active');
      toggleCreateMode.style.display = 'block';
      toggleSelectMode.style.display = 'none';
      selectedCustomerId.value = '';
    }

    function setSelectMode() {
      createCustomerSection.style.display = 'none';
      selectCustomerSection.style.display = 'block';
      toggleCreateMode.style.display = 'none';
      toggleCreateMode.classList.remove('is-active');
      toggleSelectMode.classList.add('is-active');
      toggleSelectMode.style.display = 'block';
      newCustomerName.value = '';
      newCustomerDob.value = '';
      newCustomerEmail.value = '';
      newCustomerMobile.value = '';
      loadExistingCustomers();
    }

    async function submitBooking() {
      const selectedCustomerValue = selectedCustomerId.value.trim();
      const customerName = newCustomerName.value.trim();
      const customerDob = newCustomerDob.value.trim();
      const customerEmail = newCustomerEmail.value.trim();
      const customerMobile = newCustomerMobile.value.trim();
      const providerId = providerSelect.value.trim();
      const appointmentTypeId = appointmentTypeSelect.value.trim();
      const startTime = bookingStart.value.trim();

      if (!providerId) {
        setBookingAlert('Choose a provider before making the booking.');
        return;
      }

      if (!appointmentTypeId) {
        setBookingAlert('Choose an appointment type before making the booking.');
        return;
      }

      if (!selectedCustomerValue && !customerName) {
        setBookingAlert('Select an existing customer or enter a new customer name.');
        return;
      }

      const warningMessages = [];
      if (isBookingOutsideDisplayTime()) {
        warningMessages.push('This booking is outside the configured display time (' + visibleStartTime.slice(0, 5) + ' to ' + visibleEndTime.slice(0, 5) + ').');
      }

      const missingFields = getCustomerMissingRequiredFields();
      if (missingFields.length) {
        warningMessages.push('The new customer is missing ' + missingFields.length + ' required field' + (missingFields.length === 1 ? '' : 's') + ': ' + missingFields.join(', ') + '.');
      }

      if (warningMessages.length) {
        const proceed = await showBookingWarning(warningMessages.join(' Do you want to continue? '));
        if (!proceed) {
          return;
        }
      }

      makeBookingBtn.disabled = true;
      makeBookingBtn.textContent = 'Saving...';
      setBookingAlert('');

      try {
        const resp = await fetch('appointments_api.php?action=create', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: document.querySelector('input[name="csrf_token"]').value,
            selected_customer_id: selectedCustomerValue,
            customer_name: customerName,
            customer_email: customerEmail,
            customer_mobile: customerMobile,
            date_of_birth: customerDob,
            provider_id: providerId,
            appointment_type_id: appointmentTypeId,
            start_time: startTime
          })
        });

        const json = await resp.json();
        if (!resp.ok) {
          throw new Error(json.error || 'Failed to create booking');
        }

        await calendar.refetchEvents();
        if (typeof bookingModal.close === 'function') {
          bookingModal.close();
        } else {
          bookingModal.removeAttribute('open');
        }
        setDraftMode();
      } catch (err) {
        setBookingAlert(err.message || 'Failed to create booking.');
      } finally {
        makeBookingBtn.disabled = false;
        makeBookingBtn.textContent = 'Make booking';
      }
    }

    async function loadExistingCustomers() {
      try {
        const resp = await fetch('customers_api.php?action=list');
        if (!resp.ok) throw new Error('Failed to load customers');
        const json = await resp.json();
        const customers = json.customers || [];

        existingCustomerSelect.innerHTML = '<option value="">-- Search and select customer --</option>';
        customers.forEach(customer => {
          const opt = document.createElement('option');
          opt.value = customer.id;
          opt.textContent = customer.full_name + ' (' + (customer.email || 'no email') + ')';
          existingCustomerSelect.appendChild(opt);
        });
      } catch (err) {
        console.error('Error loading customers:', err);
      }
    }

    toggleCreateMode.addEventListener('click', setCreateMode);
    toggleSelectMode.addEventListener('click', setSelectMode);

    setCreateMode();

    function setDraftMode() {
      bookingModal.setAttribute('data-mode', 'draft');
      bookingModalTitle.textContent = 'New booking draft';
      currentBookingStartDate = null;
      currentBookingEndDate = null;
      setBookingAlert('');
      selectedCustomerId.value = '';
      existingCustomerSelect.value = '';
      newCustomerName.value = '';
      newCustomerDob.value = '';
      newCustomerEmail.value = '';
      newCustomerMobile.value = '';
    }

    // Close modal
    if (closeBookingModalTop) {
      closeBookingModalTop.addEventListener('click', () => {
        if (typeof bookingModal.close === 'function') {
          bookingModal.close();
        } else {
          bookingModal.removeAttribute('open');
        }
        setDraftMode();
      });
    }

    closeBookingModal.addEventListener('click', () => {
      if (typeof bookingModal.close === 'function') {
        bookingModal.close();
      } else {
        bookingModal.removeAttribute('open');
      }
      setDraftMode();
    });

    if (makeBookingBtn) {
      makeBookingBtn.addEventListener('click', submitBooking);
    }

    // Appointment type change updates duration
    appointmentTypeSelect.addEventListener('change', function() {
      syncBookingDraftTimes();
    });
  </script>

  <script src="assets/js/global.js"></script>
</body>
</html>
