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
    $stmt = $db->query("SELECT id, full_name, email, role, is_active, bookable FROM users WHERE role IS NOT NULL AND role <> '' AND role <> 'customer' ORDER BY full_name ASC, id ASC");
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

$defaultProviderId = null;
foreach ($providers as $providerRow) {
  if ((int) ($providerRow['is_active'] ?? 0) === 1) {
    $defaultProviderId = (int) $providerRow['id'];
    break;
  }
}
if ($defaultProviderId === null && !empty($providers)) {
  $defaultProviderId = (int) $providers[0]['id'];
}


$calendarDefaultId = $providerId ?? $defaultProviderId;

$defaultAppointmentTypeId = !empty($appointmentTypes) ? (int) $appointmentTypes[0]['id'] : null;
$bookingCalendarSettings = get_booking_calendar_settings();
$customerFieldSettings = get_customer_field_settings();
$serverTimezoneName = get_app_timezone_name();
$serverNowIso = (new DateTimeImmutable('now', new DateTimeZone($serverTimezoneName)))->format(DateTimeInterface::ATOM);
$appointmentEmailDeliveryReady = email_transport_is_ready();

function render_customer_field_label(string $label, array $fieldSettings): string
{
  $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

  if (!empty($fieldSettings['required'])) {
    return $safeLabel . ' <span style="color:red;">*</span>';
  }

  return $safeLabel . ' <span style="color: #6b7280; font-weight: 600;">(optional)</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Calendar - <?php echo htmlspecialchars(APP_NAME); ?></title>
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

    .calendar-provider-filters {
      display: grid;
      gap: 8px;
      min-width: min(560px, 100%);
      max-width: 100%;
    }

    .calendar-provider-filters__row {
      display: grid;
      grid-template-columns: 96px minmax(220px, 1fr);
      align-items: center;
      gap: 10px;
    }

    .calendar-provider-filters__row label {
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
    }

    .calendar-provider-filters__row select {
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

    .calendar-provider-filters__row select:focus {
      outline: 3px solid rgba(36, 87, 255, 0.14);
      border-color: rgba(36, 87, 255, 0.5);
    }

    /* Fix: Use precise class targeting */.fc-bg-event.unavail-event {
  background: repeating-linear-gradient(
    45deg,
    rgba(245, 158, 11, 0.2),
    rgba(245, 158, 11, 0.2) 10px,
    rgba(239, 68, 68, 0.3) 10px,
    rgba(239, 68, 68, 0.3) 20px
  ) !important;
  opacity: 1 !important;
  isolation: isolate;
}

.fc-bg-event.unavail-event::before {
  content: '';
  position: absolute;
  inset: 0;
  background: var(--fc-page-bg-color, #fff);
  z-index: -1;
}

.fc-bg-event.standard-break-event {
  background: repeating-linear-gradient(
    45deg,
    rgba(245, 158, 11, 0.2),
    rgba(245, 158, 11, 0.2) 10px,
    rgba(239, 68, 68, 0.3) 10px,
    rgba(239, 68, 68, 0.3) 20px
  ) !important;
  opacity: 1 !important;
}

    .fc .fc-timegrid-event-harness.provider-split-left {
      left: 0% !important;
      right: 50% !important;
    }

    .fc .fc-timegrid-event-harness.provider-split-right {
      left: 50% !important;
      right: 0% !important;
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

    /* Week/Day Specific Layout (Flex Grid Setup) */
    .booking-event {
      display: flex;
      flex-direction: column;
      gap: 2px;
      padding: 4px 6px; 
      box-sizing: border-box;
      line-height: 1.1;
      font-size: 0.75rem; 
      font-weight: 700;
      height: 100%;
      overflow: hidden; 
    }

    .fc-status-cycler-dot {
      position: absolute;
      bottom: 4px;
      right: 4px;
      width: 14px;
      height: 14px;
      border-radius: 50%;
      border: 1.5px solid #ffffff;
      cursor: pointer;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
      z-index: 10; /* Ensures it sits neatly above text maps */
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .fc-status-cycler-dot:hover {
      transform: scale(1.25);
      box-shadow: 0 3px 8px rgba(0, 0, 0, 0.4);
    }
    
    html[data-theme="dark"] .fc-status-cycler-dot {
      border-color: #1e293b;
    }

    .booking-event__status {
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      opacity: 0.86;
    }

    .fc .fc-timegrid-event,
    .fc .fc-daygrid-event {
      position: relative;
      border-radius: 0;
      height: 100%; 
    }

    /* Month view structural modifications to fix cell heights */
    .fc-daygrid-event-dot {
      border-color: currentColor !important; 
    }

    .fc .fc-daygrid-block-event,
    .fc .fc-daygrid-dot-event {
      background-color: var(--fc-event-bg-color, #2457ff) !important;
      border: none !important;
    }

    /* Target ONLY the month grid elements to turn off forced row stretching */
    .fc-daygrid-body .fc-daygrid-event {
      height: auto !important; 
      margin-top: 2px !important;
      margin-bottom: 2px !important;
    }
    .fc-daygrid-view .fc-timegrid-row,
    .fc-daygrid-view .fc-daygrid-row,
    .fc-daygrid-view .fc-daygrid-row-chunk,
    .fc-daygrid-view .fc-scrollgrid-sync-table tr {
      height: auto !important;
    }
    .fc-daygrid-view .fc-daygrid-day-frame,
    .fc-daygrid-view .fc-daygrid-day-events,
    .fc-daygrid-view .fc-daygrid-event-harness {
      height: auto !important;
      min-height: 0 !important;
    }
    .fc-daygrid-body .booking-event {
      height: auto !important;   
      display: block !important;  
      padding: 4px 6px !important; 
    }

    .fc-daygrid-view .fc-scrollgrid-sync-table {
      height: auto !important;
    }

    /* Status border implementations */
    .fc .fc-timegrid-event.appointment-status-booked,
    .fc .fc-daygrid-event.appointment-status-booked,
    .fc .fc-timegrid-event.appointment-status-unconfirmed,
    .fc .fc-daygrid-event.appointment-status-unconfirmed {
      box-shadow: inset 0 0 0 0 rgba(0, 0, 0, 0) !important;
    }

    .fc .fc-timegrid-event.appointment-status-confirmed,
    .fc .fc-daygrid-event.appointment-status-confirmed {
      box-shadow: inset 0 0 0 3px #d4af37 !important;
    }

    .fc .fc-timegrid-event.appointment-status-completed,
    .fc .fc-daygrid-event.appointment-status-completed {
      box-shadow: inset 0 0 0 3px #9ca3af !important;
    }

    .fc .fc-timegrid-event.appointment-status-no-show,
    .fc .fc-daygrid-event.appointment-status-no-show,
    .fc .fc-timegrid-event.appointment-status-no_show,
    .fc .fc-daygrid-event.appointment-status-no_show {
      box-shadow: inset 0 0 0 3px #dc143c !important;
    }

    .fc .fc-timegrid-event.appointment-status-present,
    .fc .fc-daygrid-event.appointment-status-present {
      box-shadow: inset 0 0 0 0 rgba(0, 0, 0, 0);
    }

    .fc .fc-timegrid-event.appointment-status-present::before,
    .fc .fc-daygrid-event.appointment-status-present::before {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: 0;
      padding: 3px;
      background: linear-gradient(90deg, rgba(0, 255, 102, 0.15), rgba(0, 255, 102, 0.9), rgba(0, 255, 102, 0.15));
      background-size: 200% 100%;
      animation: presentBorderPulse 1.8s ease-in-out infinite;
      -webkit-mask:
        linear-gradient(#fff 0 0) content-box,
        linear-gradient(#fff 0 0);
      -webkit-mask-composite: xor;
      mask-composite: exclude;
      pointer-events: none;
      z-index: 1;
    }

    .fc .fc-timegrid-event .booking-event,
    .fc .fc-daygrid-event .booking-event {
      position: relative;
      z-index: 2;
    }

    @keyframes presentBorderPulse {
      0%, 100% {
        opacity: 0.35;
        background-position: 0% 50%;
      }
      50% {
        opacity: 1;
        background-position: 100% 50%;
      }
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

    .booking-card.is-invalid-time {
      box-shadow: inset 0 0 0 6px #ef4444, var(--shadow) !important;
      animation: flashRedBorder 1.2s ease-in-out infinite !important;
    }

    @keyframes flashRedBorder {
      0%, 100% {
        box-shadow: inset 0 0 0 6px #ef4444, var(--shadow);
      }
      50% {
        box-shadow: inset 0 0 0 6px rgba(239, 68, 68, 0.2), var(--shadow);
      }
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
      padding: 12px 14px 14px;
      display: grid;
      gap: 8px;
      font-size: 0.94rem;
    }

    .fc .fc-timegrid-slot {
      min-height: 30px;
      border-bottom: 1px solid rgba(15,23,42,0.06);
    } 

    .fc .fc-timegrid-col {
      border-left: 1px solid rgba(15,23,42,0.06);
    }

    .fc .fc-timegrid-col:last-child {
      border-right: 0;
    }

    .fc .fc-col-header-cell {
      border-right: 1px solid rgba(15,23,42,0.06);
    }

    html[data-theme="dark"] .fc .fc-timegrid-slot {
      border-bottom: 1px solid rgba(255,255,255,0.12) !important;
    }

    html[data-theme="dark"] .fc .fc-timegrid-col,
    html[data-theme="dark"] .fc .fc-col-header-cell {
      border-color: rgba(255,255,255,0.10) !important;
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

    .customer-name-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .customer-name-grid.is-single-field {
      grid-template-columns: minmax(0, 1fr);
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

    .field-optional {
      color: var(--muted);
      font-weight: 600;
    }

    html[data-theme="dark"] .field input,
    html[data-theme="dark"] .field select,
    html[data-theme="dark"] .field textarea {
      background: rgba(15, 23, 42, 0.5) !important;
      border-color: rgba(226, 232, 240, 0.12) !important;
      color: #f8fafc !important;
    }

    html[data-theme="dark"] .meta-inline-inputs input {
      background: rgba(15, 23, 42, 0.6) !important;
      border-color: rgba(255, 255, 255, 0.14) !important;
      color: #f8fafc !important;
    }

    html[data-theme="dark"] input[type="time"]::-webkit-calendar-picker-indicator {
      filter: invert(1);
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
      gap: 8px;
      padding: 10px 12px;
      border: 1px dashed var(--panel-border);
      border-radius: 18px;
      background: var(--panel);
    }

    .meta-item {
      display: grid;
      gap: 4px;
    }

    .meta-item span {
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      font-weight: 700;
    }

    .meta-item strong {
      font-size: 0.84rem;
      font-weight: 700;
      color: var(--text);
    }

    .meta-item .meta-inline-inputs {
      display: grid;
      gap: 4px;
      margin-top: 6px;
    }

    .meta-item .meta-inline-inputs input {
      width: 100%;
      border: 1px solid rgba(15, 23, 42, 0.15);
      border-radius: 12px;
      padding: 7px 10px;
      font: inherit;
      font-size: 0.86rem;
      color: var(--text);
      background: #fff;
      box-sizing: border-box;
    }

    .booking-card__footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      padding: 0 14px 12px;
    }

    .booking-card__footer .secondary {
      color: var(--muted);
      font-size: 0.92rem;
    }

    .booking-card__footer button {
      border: 1px solid var(--panel-border);
      border-radius: 999px;
      padding: 8px 12px;
      background: rgba(255, 255, 255, 0.06);
      color: var(--text);
      font: inherit;
      font-weight: 700;
      font-size: 0.92rem;
      cursor: pointer;
    }

    .booking-card__footer .primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    .booking-edit-only {
      display: none;
    }

    .booking-modal[data-mode="edit"] .booking-status-row {
      display: grid;
    }

    .booking-modal[data-mode="edit"] .booking-delete-action {
      display: inline-flex;
    }

    .booking-modal[data-mode="draft"] .booking-draft-only {
      display: grid;
    }

    .booking-modal[data-mode="edit"] .booking-draft-only {
      display: none;
    }

    .booking-modal[data-mode="edit"] .booking-edit-customer-only {
      display: grid;
    }

    .booking-modal[data-mode="edit"] .booking-create-customer-only {
      display: none;
    }

    .booking-status-row {
      display: grid;
      grid-template-columns: 3fr 1fr;
      gap: 8px;
      align-items: end;
    }

    .booking-status-controls {
      display: grid;
      grid-template-columns: 3fr 1fr;
      gap: 8px;
      align-items: center;
    }

    .booking-status-controls select {
      min-width: 150px;
    }

    .booking-notes-label {
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
    }

    .booking-notes-label::after {
      content: '▾';
      font-size: 0.9em;
      opacity: 0.7;
    }

    .booking-notes-panel.is-collapsed {
      display: none;
    }

    .booking-delete-action {
      background: rgba(239, 68, 68, 0.10);
      border-color: rgba(239, 68, 68, 0.18);
      color: #b91c1c;
    }

    .booking-status-controls .booking-mode-toggle-secondary {
      padding: 8px 12px;
      font-size: 0.88rem;
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

    .appointment-email-warning {
      margin-top: 10px;
      color: #b45309;
      font-weight: 700;
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

    .booking-warning-card__footer button:disabled {
      opacity: 0.45;
      cursor: not-allowed;
      filter: grayscale(0.25);
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

    .booking-customer-tabs {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .booking-customer-tab {
      border: 1px solid var(--panel-border);
      color: var(--text);
      padding: 8px 12px;
      border-radius: 999px;
      font-size: 0.9rem;
      cursor: pointer;
      font-weight: 700;
      background: rgba(255, 255, 255, 0.18);
    }

    html[data-theme="dark"] .booking-customer-tab {
      background: rgba(255, 255, 255, 0.05);
    }

    .booking-customer-tab.is-active {
      background: rgba(36, 87, 255, 0.10);
      border-color: rgba(36, 87, 255, 0.35);
    }

    .booking-customer-panel {
      display: grid;
      gap: 12px;
    }

    .customer-search-results {
      display: grid;
      gap: 8px;
      max-height: 240px;
      overflow: auto;
      padding-right: 2px;
    }

    .customer-search-result {
      width: 100%;
      border: 1px solid var(--panel-border);
      background: rgba(255, 255, 255, 0.75);
      border-radius: 16px;
      padding: 12px 14px;
      text-align: left;
      cursor: pointer;
    }

    .customer-search-result:hover {
      background: rgba(36, 87, 255, 0.06);
    }

    .customer-search-result.is-selected {
      background: rgba(36, 87, 255, 0.10);
      border-color: rgba(36, 87, 255, 0.45);
    }

    .customer-search-result strong {
      display: block;
      font-size: 0.95rem;
      margin-bottom: 2px;
    }

    .customer-search-result span {
      display: block;
      font-size: 0.82rem;
      color: var(--muted);
      line-height: 1.35;
    }

    .customer-search-state,
    .selected-customer-card {
      border: 1px solid var(--panel-border);
      border-radius: 16px;
      background: var(--panel);
      padding: 12px 14px;
    }

    .selected-customer-card__head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
    }

    .selected-customer-card button {
      border: 0;
      background: transparent;
      color: var(--accent);
      padding: 0;
      font-weight: 700;
      cursor: pointer;
      flex-shrink: 0;
    }

    .selected-customer-card strong {
      display: block;
      margin-bottom: 2px;
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
      .calendar-provider-filters {
        width: 100%;
        min-width: 0;
      }

      .calendar-provider-filters__row {
        grid-template-columns: 1fr;
        gap: 6px;
      }

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
    
    // Add this right before the navigation include:
    if ((int) get_setting('disable_online_booking', '0') === 1) {
        echo '<div style="background-color: #f59e0b; color: #fff; text-align: center; padding: 8px 12px; font-weight: bold; font-size: 0.9rem; border-bottom: 1px solid rgba(0,0,0,0.1);">
                ⚠️ Public Online Bookings are Deactivated. Admin calendar bookings remain available.
              </div>';
    }
    
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
        <div class="calendar-provider-filters" aria-label="Calendar provider filters">
          <div class="calendar-provider-filters__row">
            <label for="calendarProviderPrimary">Provider</label>
            <select id="calendarProviderPrimary" name="calendar_provider_primary">
              <?php foreach ($providers as $provider): ?>
                <?php $providerListLabel = trim((string) ($provider['full_name'] ?? '')) !== '' ? (string) $provider['full_name'] : (string) ($provider['email'] ?? ''); ?>
                <option value="<?php echo (int) $provider['id']; ?>" <?php echo ($calendarDefaultId === (int) $provider['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($providerListLabel, ENT_QUOTES, 'UTF-8'); ?>
                  <?php if (!empty($provider['role'])): ?>
                    (<?php echo htmlspecialchars((string) $provider['role'], ENT_QUOTES, 'UTF-8'); ?>)
                  <?php endif; ?>
                  <?php if ((int) ($provider['is_active'] ?? 0) !== 1): ?>
                    (inactive)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="calendar-provider-filters__row" id="calendarProviderSecondaryWrap" style="display:none;">
            <label for="calendarProviderSecondary">Other</label>
            <select id="calendarProviderSecondary" name="calendar_provider_secondary">
              <option value="">None</option>
            </select>
          </div>
        </div>
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
            <div class="meta-inline-inputs">
              <input id="bookingStartTimeInput" type="time" step="60" aria-label="Start time">
            </div>
          </div>
          <div class="meta-item">
            <span>End</span>
            <strong id="selectedEndLabel">-</strong>
            <div class="meta-inline-inputs">
              <input id="bookingEndTimeInput" type="time" step="60" aria-label="End time">
            </div>
          </div>
        </div>

        <div class="booking-grid">
          <div class="field">
            <label for="providerSelect">Which provider?</label>
            <select id="providerSelect" name="provider_id">
            <?php foreach ($providers as $index => $provider): ?>
              <option value="<?php echo (int) $provider['id']; ?>" <?php echo ((int) ($provider['is_active'] ?? 0) === 1 && $calendarDefaultId === (int) $provider['id']) ? 'selected' : ''; ?> <?php echo ((int) ($provider['is_active'] ?? 0) !== 1) ? 'disabled' : ''; ?>>
                <?php echo htmlspecialchars($provider['full_name'] ?: $provider['email']); ?>
                <?php if (!empty($provider['role'])): ?>
                  (<?php echo htmlspecialchars($provider['role']); ?>)
                <?php endif; ?>
                  <?php if ((int) ($provider['is_active'] ?? 0) !== 1): ?>
                    (inactive)
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
         </div>

        <div class="booking-edit-only booking-status-row">
          <div class="field">
            <label for="appointmentStatusSelect">Status</label>
            <div class="booking-status-controls">
              <select id="appointmentStatusSelect">
                <option value="booked">Booked</option>
                <option value="confirmed">Confirmed</option>
                <option value="present">Present</option>
                <option value="completed">Completed</option>
                <option value="no show">No show</option>
              </select>
              <button type="button" id="cycleAppointmentStatusBtn" class="booking-mode-toggle-secondary">Toggle</button>
            </div>
          </div>
        </div>

        <div class="booking-grid booking-customer-section">
          <div class="field span-2">
            <label>Who do I want to book?</label>
            <div class="booking-customer-tabs" role="tablist" aria-label="Customer selection mode">
              <button type="button" id="createCustomerTab" class="booking-customer-tab is-active booking-create-customer-only" role="tab" aria-selected="true">Create new customer</button>
              <button type="button" id="existingCustomerTab" class="booking-customer-tab booking-edit-customer-only" role="tab" aria-selected="false">Existing customer</button>
            </div>

            <div id="createCustomerPanel" class="booking-customer-panel booking-create-customer-only">
              <?php
                $firstNameFieldSettings = $customerFieldSettings['first_name'] ?? ['display' => true, 'required' => false];
                $lastNameFieldSettings = $customerFieldSettings['last_name'] ?? ['display' => true, 'required' => false];
                $showFirstNameField = !empty($firstNameFieldSettings['display']);
                $showLastNameField = !empty($lastNameFieldSettings['display']);
              ?>
              <?php if ($showFirstNameField || $showLastNameField): ?>
              <div class="customer-name-grid <?php echo ($showFirstNameField && $showLastNameField) ? '' : 'is-single-field'; ?>">
                <?php if ($showFirstNameField): ?>
                <div class="field">
                  <label for="newCustomerFirstName"><?php echo render_customer_field_label('First name', $firstNameFieldSettings); ?></label>
                  <input id="newCustomerFirstName" name="new_customer_first_name" type="text" placeholder="First name">
                </div>
                <?php endif; ?>
                <?php if ($showLastNameField): ?>
                <div class="field">
                  <label for="newCustomerLastName"><?php echo render_customer_field_label('Last name', $lastNameFieldSettings); ?></label>
                  <input id="newCustomerLastName" name="new_customer_last_name" type="text" placeholder="Last name">
                </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              <?php $dobFieldSettings = $customerFieldSettings['date_of_birth'] ?? ['display' => true, 'required' => false]; ?>
              <div class="field"<?php echo !empty($dobFieldSettings['display']) ? '' : ' style="display:none;"'; ?>>
                <label for="newCustomerDob"><?php echo render_customer_field_label('Date of Birth', $dobFieldSettings); ?></label>
                <input id="newCustomerDob" name="date_of_birth" type="date">
              </div>
              <?php $genderFieldSettings = $customerFieldSettings['gender'] ?? ['display' => false, 'required' => false]; ?>
              <?php if (!empty($genderFieldSettings['display'])): ?>
              <div class="field">
                <label for="newCustomerGender"><?php echo render_customer_field_label('Gender', $genderFieldSettings); ?></label>
                <select id="newCustomerGender" name="customer_gender">
                  <option value="">Select gender</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="other">Other</option>
                  <option value="unknown">Unknown</option>
                </select>
              </div>
              <?php endif; ?>
              <?php $emailFieldSettings = $customerFieldSettings['email'] ?? ['display' => true, 'required' => false]; ?>
              <div class="field"<?php echo !empty($emailFieldSettings['display']) ? '' : ' style="display:none;"'; ?>>
                <label for="newCustomerEmail"><?php echo render_customer_field_label('Email', $emailFieldSettings); ?></label>
                <input id="newCustomerEmail" name="new_customer_email" type="email" placeholder="customer@example.com">
              </div>
              <?php $mobileFieldSettings = $customerFieldSettings['mobile'] ?? ['display' => true, 'required' => false]; ?>
              <div class="field"<?php echo !empty($mobileFieldSettings['display']) ? '' : ' style="display:none;"'; ?>>
                <label for="newCustomerMobile"><?php echo render_customer_field_label('Mobile', $mobileFieldSettings); ?></label>
                <input id="newCustomerMobile" name="new_customer_mobile" type="text" placeholder="1234567890">
              </div>
            </div>

            <div id="existingCustomerPanel" class="booking-customer-panel" style="display:none;">
              <div class="field">
                <label for="customerSearchInput">Search customers</label>
                <input id="customerSearchInput" type="search" placeholder="Search by name, mobile, or email">
              </div>
              <div id="customerSearchState" class="customer-search-state">Start typing to search.</div>
              <div id="customerSearchResults" class="customer-search-results"></div>
              <div id="selectedCustomerCard" class="selected-customer-card" style="display:none;">
                <div class="selected-customer-card__head">
                  <div>
                    <strong id="selectedCustomerName"></strong>
                    <div id="selectedCustomerDetails" class="booking-note"></div>
                  </div>
                  <button type="button" id="clearSelectedCustomerBtn">Change</button>
                </div>
              </div>
            </div>
            
            <input type="hidden" id="selectedCustomerId" value="">
          </div>

          <div class="field span-2">
            <label id="bookingNotesLabel" for="bookingNotes" class="booking-notes-label">Notes</label>
            <div id="bookingNotesPanel" class="booking-notes-panel is-collapsed">
              <textarea id="bookingNotes" name="notes" rows="3" placeholder="Optional notes for the booking draft"></textarea>
            </div>
          </div>
        </div>

        <input type="hidden" id="bookingStart" value="">
        <input type="hidden" id="bookingEnd" value="">
      </div>
      <div class="booking-card__footer">
        <button type="button" id="makeBookingBtn" class="primary booking-booking-action">Make booking</button>
        <button type="button" id="deleteBookingBtn" class="booking-delete-action booking-edit-only">Delete</button>
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

  <dialog id="appointmentEmailModal" class="booking-warning-modal">
    <div class="booking-warning-card">
      <div class="booking-warning-card__head">
        <h2>Send confirmation email?</h2>
      </div>
      <div class="booking-warning-card__body">
        <p id="appointmentEmailMessage"></p>
        <p id="appointmentEmailWarning" class="appointment-email-warning" style="display:none;"></p>
      </div>
      <div class="booking-warning-card__footer">
        <button type="button" id="appointmentEmailCancel" style="background: rgba(239, 68, 68, 0.1); color: #dc2626; border-color: rgba(239, 68, 68, 0.2);">Cancel Reschedule</button>
        <button type="button" id="appointmentEmailSkip">No, skip</button>
        <button type="button" id="appointmentEmailSend" class="primary">Yes, send email</button>
      </div>
    </div>
  </dialog>

  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
  <script>
    const isStaff = <?php echo json_encode($isStaff); ?>;
    const currentUserRole = <?php echo json_encode($role); ?>;
    const providerId = <?php echo json_encode($providerId); ?>;
    const providers = <?php echo json_encode($providers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const appointmentTypes = <?php echo json_encode($appointmentTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const defaultProviderId = <?php echo json_encode($defaultProviderId); ?>;
    const defaultAppointmentTypeId = <?php echo json_encode($defaultAppointmentTypeId); ?>;
    const bookingCalendarSettings = <?php echo json_encode($bookingCalendarSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const customerFieldSettings = <?php echo json_encode($customerFieldSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const genderFieldSettings = customerFieldSettings.gender || { display: false, required: false };
    const serverTimezoneName = <?php echo json_encode($serverTimezoneName); ?>;
    let serverNowIso = <?php echo json_encode($serverNowIso); ?>;

    /**
     * Resolves layout formats cleanly across views by tracking token index rules
     */
    const calendarDisplayFormat = (function(phpFormatString) {
      const format = String(phpFormatString || 'd/m/Y H:i').trim();
      
      const dayIndex = format.indexOf('d') !== -1 ? format.indexOf('d') : format.indexOf('j');
      const monthIndex = format.indexOf('m') !== -1 ? format.indexOf('m') : format.indexOf('n');
      const yearIndex = format.indexOf('Y') !== -1 ? format.indexOf('Y') : format.indexOf('y');

      let title = { year: 'numeric', month: 'long' };
      let column = { month: 'numeric', day: 'numeric' };

      if (yearIndex !== -1 && monthIndex !== -1 && yearIndex < monthIndex) {
        title = { year: 'numeric', month: 'long' };
        column = { month: 'numeric', day: 'numeric' }; 
      } else if (dayIndex !== -1 && monthIndex !== -1 && dayIndex < monthIndex) {
        title = { month: 'long', year: 'numeric' };
        column = { day: 'numeric', month: 'numeric' }; 
      }

      return { title, column };
    })(bookingCalendarSettings.date_format);

    const calendarEl = document.getElementById('calendar');
    const serverClockValue = document.getElementById('serverClockValue');
    const calendarProviderPrimary = document.getElementById('calendarProviderPrimary');
    const calendarProviderSecondary = document.getElementById('calendarProviderSecondary');
    const calendarProviderSecondaryWrap = document.getElementById('calendarProviderSecondaryWrap');
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
    const appointmentEmailModal = document.getElementById('appointmentEmailModal');
    const appointmentEmailMessage = document.getElementById('appointmentEmailMessage');
    const appointmentEmailWarning = document.getElementById('appointmentEmailWarning');
    const appointmentEmailSkip = document.getElementById('appointmentEmailSkip');
    const appointmentEmailSend = document.getElementById('appointmentEmailSend');
    const appointmentEmailCancel = document.getElementById('appointmentEmailCancel');
    const bookingStart = document.getElementById('bookingStart');
    const bookingEnd = document.getElementById('bookingEnd');
    const closeBookingModalTop = document.getElementById('closeBookingModalTop');
    const makeBookingBtn = document.getElementById('makeBookingBtn');
    const providerSelect = document.getElementById('providerSelect');
    const appointmentTypeSelect = document.getElementById('appointmentTypeSelect');
    const bookingStartTimeInput = document.getElementById('bookingStartTimeInput');
    const bookingEndTimeInput = document.getElementById('bookingEndTimeInput');
    const appointmentStatusSelect = document.getElementById('appointmentStatusSelect');
    const cycleAppointmentStatusBtn = document.getElementById('cycleAppointmentStatusBtn');
    const deleteBookingBtn = document.getElementById('deleteBookingBtn');
    const bookingNotesLabel = document.getElementById('bookingNotesLabel');
    const bookingNotesPanel = document.getElementById('bookingNotesPanel');
    const bookingNotes = document.getElementById('bookingNotes');
    let bookingEndOverrideActive = false;
    let currentEditingAppointmentId = null;
    let currentEditingAppointmentStatus = 'booked';
    let currentBookingStartDate = null;
    let currentBookingEndDate = null;
    let pendingDraftProviderId = null;
    let notesExpanded = false;
    let pendingEmailPromptContext = null;
    const appointmentStatusValues = ['booked', 'confirmed', 'present', 'completed', 'no show'];
    const appointmentEmailDeliveryReady = Boolean(<?php echo json_encode($appointmentEmailDeliveryReady); ?>);

    const fallbackDurationMinutes = appointmentTypes.length > 0 ? Number(appointmentTypes[0].duration_minutes || 30) : 30;

    function getDefaultPrimaryProviderId() {
      if (providerId && Number(providerId) > 0) {
        return Number(providerId);
      }
      if (defaultProviderId && Number(defaultProviderId) > 0) {
        return Number(defaultProviderId);
      }
      if (providers.length > 0) {
        return Number(providers[0].id);
      }
      return null;
    }

    let selectedPrimaryProviderId = getDefaultPrimaryProviderId();
    let selectedSecondaryProviderId = null;

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

    const calendarHeight = bookingCalendarSettings.calendar_height ?? 1800;
    const weekStartDay = Number.isInteger(Number(bookingCalendarSettings.week_start_day))
      ? Number(bookingCalendarSettings.week_start_day)
      : 0;

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

    const bookingSnapOptions = [1, 2, 5, 10, 15, 20, 30, 40, 45, 60];
    const bookingSnapMinutes = bookingSnapOptions.includes(Number(bookingCalendarSettings.snap_minutes))
      ? Number(bookingCalendarSettings.snap_minutes)
      : 15;
    const bookingDeadlineHours = Number.isFinite(Number(bookingCalendarSettings.deadline_hours))
      ? Number(bookingCalendarSettings.deadline_hours)
      : 24;

    function minutesToDuration(minutes) {
      const safeMinutes = Number(minutes) > 0 ? Math.floor(Number(minutes)) : 30;
      const hours = String(Math.floor(safeMinutes / 60)).padStart(2, '0');
      const mins = String(safeMinutes % 60).padStart(2, '0');
      return hours + ':' + mins + ':00';
    }

    const appointmentTypeDurationById = new Map(
      appointmentTypes.map(function(type) {
        return [Number(type.id), Number(type.duration_minutes || fallbackDurationMinutes)];
      })
    );

    function parseCalendarDate(value) {
      if (value instanceof Date) {
        return new Date(value.getTime());
      }

      const raw = String(value || '').trim();
      if (!raw) {
        return null;
      }

      if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(raw)) {
        return new Date(raw);
      }

      return new Date(raw.includes('T') ? raw + 'Z' : raw.replace(' ', 'T') + 'Z');
    }

    function roundDateToSnap(date, snapMinutes) {
      const intervalMinutes = Number(snapMinutes) > 0 ? Number(snapMinutes) : 15;
      const intervalMs = intervalMinutes * 60000;
      return new Date(Math.round(date.getTime() / intervalMs) * intervalMs);
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

    function isThreeDayOrDayView() {
      const currentViewType = calendar && calendar.view ? calendar.view.type : '';
      return currentViewType === 'timeGridThreeDay' || currentViewType === 'timeGridDay';
    }

    function isDualProviderSplitEnabled() {
      return isThreeDayOrDayView() && Number(selectedSecondaryProviderId || 0) > 0;
    }

    function formatProviderOptionLabel(provider) {
      if (!provider) {
        return 'Unknown provider';
      }

      const baseName = String(provider.full_name || provider.email || ('User #' + provider.id));
      const roleLabel = provider.role ? ' (' + provider.role + ')' : '';
      const inactiveLabel = Number(provider.is_active || 0) === 1 ? '' : ' (inactive)';
      return baseName + roleLabel + inactiveLabel;
    }

    function rebuildSecondaryProviderOptions() {
      if (!calendarProviderSecondary) {
        return;
      }

      const previousSelection = String(calendarProviderSecondary.value || '');
      calendarProviderSecondary.innerHTML = '';

      const noneOption = document.createElement('option');
      noneOption.value = '';
      noneOption.textContent = 'None';
      calendarProviderSecondary.appendChild(noneOption);

      providers.forEach(function(provider) {
        const numericProviderId = Number(provider.id || 0);
        if (!numericProviderId || numericProviderId === Number(selectedPrimaryProviderId || 0)) {
          return;
        }

        const option = document.createElement('option');
        option.value = String(numericProviderId);
        option.textContent = formatProviderOptionLabel(provider);
        calendarProviderSecondary.appendChild(option);
      });

      if (previousSelection && calendarProviderSecondary.querySelector('option[value="' + previousSelection + '"]')) {
        calendarProviderSecondary.value = previousSelection;
      } else {
        calendarProviderSecondary.value = '';
      }

      selectedSecondaryProviderId = calendarProviderSecondary.value ? Number(calendarProviderSecondary.value) : null;
    }

    function syncProviderFilterVisibility() {
      if (!calendarProviderSecondaryWrap) {
        return;
      }

      if (isThreeDayOrDayView()) {
        calendarProviderSecondaryWrap.style.display = '';
      } else {
        calendarProviderSecondaryWrap.style.display = 'none';
        if (calendarProviderSecondary) {
          calendarProviderSecondary.value = '';
        }
        selectedSecondaryProviderId = null;
      }
    }

    function applyDraftProviderSelection() {
      if (!providerSelect) {
        pendingDraftProviderId = null;
        return;
      }

      let targetProviderId = Number(pendingDraftProviderId || 0);
      if (!targetProviderId) {
        targetProviderId = Number(selectedPrimaryProviderId || getDefaultPrimaryProviderId() || 0);
      }

      if (targetProviderId) {
        const activeOption = providerSelect.querySelector('option[value="' + targetProviderId + '"]:not([disabled])');
        const fallbackOption = providerSelect.querySelector('option[value="' + targetProviderId + '"]');
        if (activeOption || fallbackOption) {
          providerSelect.value = String(targetProviderId);
        }
      }

      pendingDraftProviderId = null;
    }

    function getDateClickSide(info) {
      if (!isDualProviderSplitEnabled() || !info || !info.jsEvent) {
        return null;
      }

      const clickedElement = info.jsEvent.target;
      const col = clickedElement && typeof clickedElement.closest === 'function'
        ? clickedElement.closest('.fc-timegrid-col')
        : null;
      const referenceElement = col || info.dayEl || null;

      if (!referenceElement || typeof referenceElement.getBoundingClientRect !== 'function') {
        return null;
      }

      const rect = referenceElement.getBoundingClientRect();
      if (!rect || rect.width <= 0) {
        return null;
      }

      const relativeX = Number(info.jsEvent.clientX || 0) - rect.left;
      return relativeX < (rect.width / 2) ? 'left' : 'right';
    }

function getFilteredCalendarEvents(rawEvents) {
  const eventsArray = Array.isArray(rawEvents) ? rawEvents : [];
  const primaryId = Number(selectedPrimaryProviderId || 0);
  const secondaryId = Number(selectedSecondaryProviderId || 0);
  const dualEnabled = isThreeDayOrDayView() && secondaryId > 0;

  return eventsArray.filter(function(event) {
    if (!event) return false;

    // Background events (Breaks and Unavailabilities)
    if (event.display === 'background') {
      const eventProviderId = Number(
        (event.extendedProps && event.extendedProps.provider_id) || 0
      );
      
      // Global breaks always show
      if (!eventProviderId) return true; 

      // If viewing dual mode, check if it matches either column
      if (dualEnabled) {
        return eventProviderId === primaryId || eventProviderId === secondaryId;
      }
      
      // If a specific provider is selected in the dropdown, show only theirs.
      // Otherwise, show all unavailabilities.
      return primaryId > 0 ? eventProviderId === primaryId : true;
    }

    // Regular appointments filtering
    const providerValue = Number(
      (event.extendedProps && event.extendedProps.provider_id) || event.provider_id || 0
    );
    if (!providerValue) return false;

    if (dualEnabled) {
      return providerValue === primaryId || providerValue === secondaryId;
    }
    return primaryId > 0 ? providerValue === primaryId : true;
  });
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
      if (bookingStartTimeInput) {
        bookingStartTimeInput.value = getServerTimePartFromDateTime(bookingStart.value).slice(0, 5);
      }
      if (bookingEndTimeInput) {
        bookingEndTimeInput.value = getServerTimePartFromDateTime(bookingEnd.value).slice(0, 5);
      }
      setTimeout(checkTimeSlotConflicts, 100);
    }

    function syncBookingNotes(value) {
      if (!bookingNotes) {
        return;
      }

      bookingNotes.value = String(value || '');
      notesExpanded = bookingNotes.value.trim().length > 0;
      syncNotesVisibility(notesExpanded);
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
      if (makeBookingBtn) {
        makeBookingBtn.textContent = mode === 'edit' ? 'Save changes' : 'Make booking';
      }
      if (deleteBookingBtn) {
        deleteBookingBtn.style.display = mode === 'edit' ? '' : 'none';
      }

      if (mode === 'draft') {
        currentEditingAppointmentId = null;
        syncAppointmentStatusUI('booked');
        notesExpanded = false;
        syncBookingNotes('');
        syncBookingDraftTimes();
      } else {
        selectedStartLabel.textContent = formatCalendarDate(calendar, startDate);
        selectedEndLabel.textContent = formatCalendarDate(calendar, derivedEndDate);
        bookingStart.value = formatServerClockDateTime(startDate);
        bookingEnd.value = formatServerClockDateTime(derivedEndDate);
        if (bookingStartTimeInput) bookingStartTimeInput.value = getServerTimePartFromDateTime(bookingStart.value).slice(0,5);
        if (bookingEndTimeInput) bookingEndTimeInput.value = getServerTimePartFromDateTime(bookingEnd.value).slice(0,5);
        bookingEndOverrideActive = false;
        currentEditingAppointmentId = null;

        if (mode === 'edit' && options.appointment) {
          const appt = options.appointment;
          const cust = options.customer || {};
          currentEditingAppointmentId = appt.id;
          syncAppointmentStatusUI(appt.status || 'booked');
          syncBookingNotes(appt.notes || '');
          if (cust && cust.id) {
            setCustomerMode('existing');
            showSelectedCustomer({ id: cust.id, full_name: cust.full_name || ((cust.first_name || '') + ' ' + (cust.last_name || '')), email: cust.email || '', mobile: cust.mobile || '' });
          } else {
            if (newCustomerFirstName) newCustomerFirstName.value = cust.first_name || '';
            if (newCustomerLastName) newCustomerLastName.value = cust.last_name || '';
            if (newCustomerEmail) newCustomerEmail.value = cust.email || '';
            if (newCustomerMobile) newCustomerMobile.value = cust.mobile || '';
            if (newCustomerDob) newCustomerDob.value = cust.date_of_birth || '';
            if (newCustomerGender) newCustomerGender.value = cust.gender || '';
            setCustomerMode('create');
          }

          if (appt.provider_id) {
            providerSelect.value = String(appt.provider_id);
          }
          if (appt.appointment_type_id) {
            appointmentTypeSelect.value = String(appt.appointment_type_id);
          }

          const opt = appointmentTypeSelect.options[appointmentTypeSelect.selectedIndex];
          const duration = Number(opt?.dataset?.duration || fallbackDurationMinutes);
          const start = new Date(appt.start_time + 'Z');
          const defaultEnd = addMinutes(start, duration);
          const actualEnd = new Date(appt.end_time + 'Z');
          if (Math.abs((actualEnd.getTime() - defaultEnd.getTime())) > 60000) { 
            bookingEndOverrideActive = true;
          } else {
            bookingEndOverrideActive = false;
          }
        }
      }

      if (defaultProviderId && !providerSelect.value) {
        applyDraftProviderSelection();
      } else if (mode === 'draft') {
        applyDraftProviderSelection();
      }

      if (defaultAppointmentTypeId && !appointmentTypeSelect.value) {
        appointmentTypeSelect.value = String(defaultAppointmentTypeId);
        if (mode === 'draft') {
          syncBookingDraftTimes();
        }
      }

      setTimeout(checkTimeSlotConflicts, 50);

      if (typeof bookingModal.showModal === 'function') {
        bookingModal.showModal();
      } else {
        bookingModal.setAttribute('open', 'open');
      }
    }
    
const calendar = new FullCalendar.Calendar(calendarEl, {
      now: getServerClockDate(getServerNowDate()),
      initialView: isStaff ? 'timeGridWeek' : 'timeGridDay',
      height: calendarHeight,
      firstDay: weekStartDay,
      expandRows: true,
      forceEventDuration: true,   
      nowIndicator: Boolean(bookingCalendarSettings.show_now_indicator),
      selectable: false, 
      slotDuration: '00:30:00',
      snapDuration: minutesToDuration(bookingSnapMinutes),
      
      // 1. FORCE DAY-FIRST LOCALE SO BROWSER ENGINES EXPECT D/M BY DEFAULT
      locale: 'en-au', 

      // 2. APPLY YOUR DYNAMIC DB FORMAT TO THE MAIN TOOLBAR TITLE
      titleFormat: calendarDisplayFormat.title,

      viewDidMount: function(arg) {
        if (arg.view.type === 'dayGridMonth') {
          calendar.setOption('height', 'auto');
          calendar.setOption('contentHeight', 'auto');
          calendar.setOption('expandRows', false);
        } else {
          calendar.setOption('height', calendarHeight);
          calendar.setOption('contentHeight', null);
          calendar.setOption('expandRows', true);
        }
      },

      // 3. EXPLICITLY OVERRIDE COLUMN HEADERS PER VIEW TYPE TO FORCE YOUR DB FORMAT
      views: {
        dayGridMonth: {
          height: 'auto',        
          contentHeight: 'auto', 
          expandRows: false,
          dayHeaderFormat: { weekday: 'short' } // Just "Mon", "Tue"
        },
        timeGridWeek: {
          // Combines "Mon" + your format ("1/6" or "6/1") explicitly
          dayHeaderFormat: Object.assign({ weekday: 'short' }, calendarDisplayFormat.column)
        },
        timeGridDay: {
          // Combines "Monday" + your format explicitly
          dayHeaderFormat: Object.assign({ weekday: 'long' }, calendarDisplayFormat.column)
        },
        timeGridThreeDay: {
          type: 'timeGrid',
          duration: { days: 3 },
          buttonText: '3 day',
          // Combines "Mon" + your format explicitly
          dayHeaderFormat: Object.assign({ weekday: 'short' }, calendarDisplayFormat.column)
        }
      },

      slotLabelInterval: '01:00',
      eventStartEditable: isStaff,
      eventDurationEditable: false,
      eventResizableFromStart: false,
      timeZone: 'UTC',
      selectMirror: false,
      unselectAuto: true,
      hiddenDays: [0, 1, 2, 3, 4, 5, 6].filter(function(day) {
        return visibleDaysOfWeek.indexOf(day) === -1;
      }), 
      slotMinTime: getEffectiveSlotRange().slotMinTime,
      slotMaxTime: getEffectiveSlotRange().slotMaxTime,
      scrollTime: getEffectiveSlotRange().slotMinTime,
      slotEventOverlap: true,
      eventDataTransform: function(rawEvent) {
        if (!rawEvent || rawEvent.display === 'background') {
          return rawEvent;
        }

        const transformedEvent = Object.assign({}, rawEvent);
        const startDate = parseCalendarDate(transformedEvent.start);
        if (startDate && !Number.isNaN(startDate.getTime())) {
          transformedEvent.start = startDate;
          const parsedEnd = parseCalendarDate(transformedEvent.end);
          if (parsedEnd && !Number.isNaN(parsedEnd.getTime())) {
            transformedEvent.end = parsedEnd;
          } else {
            const typeId = Number(transformedEvent.extendedProps?.appointment_type_id || 0);
            const durationMinutes = appointmentTypeDurationById.get(typeId) || fallbackDurationMinutes;
            transformedEvent.end = addMinutes(startDate, durationMinutes);
          }
        }

        return transformedEvent;
      },
eventContent: function(arg) {
        if (arg.event.display === 'background') {
          return { domNodes: [] };
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'booking-event';

        const title = document.createElement('div');
        title.className = 'booking-event__title';
        title.textContent = arg.event.extendedProps.customer_name || arg.event.title || 'Appointment';

        const status = document.createElement('div');
        status.className = 'booking-event__status';
        status.textContent = String(arg.event.extendedProps.status || 'booked').replace(/_/g, ' ');

        // Build the Colored Indicator Dot
        const statusDot = document.createElement('div');
        statusDot.className = 'fc-status-cycler-dot';
        statusDot.setAttribute('title', 'Click to toggle next status state');

        // Read dynamic status to color the indicator dot on initial load
        const initialStatus = String(arg.event.extendedProps.status || 'booked').trim().toLowerCase().replace(/_/g, ' ');
        let dotColor = '#3b82f6'; // default 'booked' blue

        if (initialStatus === 'confirmed') dotColor = '#d4af37';   // Gold
        if (initialStatus === 'present')   dotColor = '#10b981';   // Emerald Green
        if (initialStatus === 'completed') dotColor = '#9ca3af';   // Muted Gray
        if (initialStatus === 'no show')   dotColor = '#dc143c';   // Crimson Red

        statusDot.style.backgroundColor = dotColor;

        // Intercept click bubble sequences cleanly
        statusDot.addEventListener('click', async function(jsEvent) {
          jsEvent.stopPropagation(); 
          jsEvent.preventDefault();

          const rawId = String(arg.event.id || '');
          if (!rawId || rawId.includes('break') || rawId.includes('unavail') || rawId === 'booking-draft-placeholder') {
            return;
          }
          const appointmentId = parseInt(rawId, 10);
          if (isNaN(appointmentId) || appointmentId < 1) return;

          //  FIX: Calculate the absolute live status context AT THE TIME OF THE CLICK
          const liveStatus = String(arg.event.extendedProps.status || 'booked').trim().toLowerCase().replace(/_/g, ' ');

          // Sequential mapping rule state transitions
          const cycleMap = {
            'booked': 'confirmed',
            'confirmed': 'present',
            'present': 'completed',
            'completed': 'no show',
            'no show': 'booked'
          };

          const nextStatus = cycleMap[liveStatus] || 'booked';

          statusDot.style.opacity = '0.5';
          statusDot.style.pointerEvents = 'none';

          try {
            const startIso = arg.event.start.toISOString();
            const endIso = arg.event.end ? arg.event.end.toISOString() : null;

            const formattedStart = startIso.replace('T', ' ').substring(0, 19);
            const formattedEnd = endIso ? endIso.replace('T', ' ').substring(0, 19) : null;

            const customerId = parseInt(arg.event.extendedProps.customer_id, 10) || 0;
            const providerId = parseInt(arg.event.extendedProps.provider_id, 10) || parseInt(selectedPrimaryProviderId, 10) || 0;
            const appointmentTypeId = parseInt(arg.event.extendedProps.appointment_type_id, 10) || parseInt(defaultAppointmentTypeId, 10) || 0;

            const response = await fetch('appointments_api.php?action=update', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                csrf_token: document.querySelector('input[name="csrf_token"]').value,
                appointment_id: appointmentId,
                selected_customer_id: customerId,
                provider_id: providerId,
                appointment_type_id: appointmentTypeId,
                start_time: formattedStart, 
                end_time: formattedEnd,     
                status: nextStatus, 
                notes: arg.event.extendedProps.notes || ''
              })
            });

            const json = await response.json();
            
            if (!response.ok || json.ok === false) {
              throw new Error(json.error || 'Server rejected inline state shift cycle.');
            }

            // Cleanly force full redraw update across views
            try {
              await window.calendarInstance.refetchEvents();
            } catch (refetchErr) {
              // refetch can fail if the event ref is stale; ignore
            }

            if (nextStatus === 'present') {
              const appt = json.appointment || arg.event;
              const cust = json.customer || { id: customerId };
              if (!bookingModal.open) {
              openBookingModal(new Date(arg.event.start), arg.event.end, {
                mode: 'edit',
                title: (appointmentId ? ('Appt #' + appointmentId) : 'Appointment') + ' • present',
                appointment: {
                  id: appointmentId,
                  status: 'present',
                  notes: arg.event.extendedProps.notes,
                  provider_id: providerId,
                  appointment_type_id: appointmentTypeId,
                  start_time: appt.start_time || formattedStart,
                  end_time: appt.end_time || formattedEnd
                },
                customer: cust
              });
            }
              }

          } catch (err) {

            console.error('Status dot error:', err);
            alert('Error updating status: ' + err.message);
          } finally {
            statusDot.style.opacity = '1';
            statusDot.style.pointerEvents = 'auto';
          }
        });

        wrapper.appendChild(title);
        wrapper.appendChild(status);
        wrapper.appendChild(statusDot);
        
        return { domNodes: [wrapper] };
      },
      eventClassNames: function(arg) {
        if (arg.event.display === 'background') {
          return [];
        }

        const rawStatus = String(arg.event.extendedProps.status || 'booked').trim().toLowerCase().replace(/_/g, '-');
        return ['appointment-status-' + rawStatus];
      },
      selectAllow: function(selectInfo) {
        const durationMs = bookingSnapMinutes * 60000; 
        
        if (selectInfo.end - selectInfo.start !== durationMs) {
          if (selectInfo.end > currentBookingEndDate || selectInfo.start > currentBookingStartDate) {
            selectInfo.end = new Date(selectInfo.start.getTime() + durationMs);
          } else {
            selectInfo.start = new Date(selectInfo.end.getTime() - durationMs);
          }
        }

        return !calendar.getEvents().some(function(event) {
          if (event.display !== 'background') return false;
          return event.start < selectInfo.end && (event.end || event.start) > selectInfo.start;
        });
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
      datesSet: function() {
        syncProviderFilterVisibility();
        calendar.refetchEvents();
      },
      selectConstraint: 'businessHours',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridThreeDay,timeGridDay'
      },
      events: function(fetchInfo, successCallback, failureCallback) {
        const params = new URLSearchParams();
        params.set('start', fetchInfo.startStr);
        params.set('end', fetchInfo.endStr);

        if (currentUserRole === 'provider' && providerId) {
          params.set('provider_id', String(providerId));
        } else {
          // Explicitly track the primary and secondary dropdown values selected on screen
          const selectedPrimary = document.getElementById('calendarProviderPrimary')?.value;
          const selectedSecondary = document.getElementById('calendarProviderSecondary')?.value;
          
          let ids = [];
          if (selectedPrimary) ids.push(selectedPrimary);
          if (selectedSecondary && isThreeDayOrDayView()) ids.push(selectedSecondary);
          
          if (ids.length > 0) {
            params.set('provider_id', ids.join(','));
          }
        }

        fetch('calendar_api.php?' + params.toString(), { credentials: 'same-origin' })
          .then(function(response) {
            if (!response.ok) {
              throw new Error('Could not load appointments');
            }
            return response.json();
          })
          .then(function(rawEvents) {
            successCallback(getFilteredCalendarEvents(rawEvents));
          })
          .catch(function(err) {
            failureCallback(err);
            alert('Could not load appointments');
          });
      },
      eventDidMount: function(arg) {
        // Background event splitting (from previous fix)
        if (arg.event.display === 'background') {
          if (isDualProviderSplitEnabled()) {
            const bgProviderId = Number(arg.event.extendedProps?.provider_id || 0);
            const el = arg.el;
            if (bgProviderId === Number(selectedPrimaryProviderId || 0)) {
              el.style.cssText += ';left:0%!important;right:50%!important;width:50%!important';
            } else if (bgProviderId === Number(selectedSecondaryProviderId || 0)) {
              el.style.cssText += ';left:50%!important;right:0%!important;width:50%!important';
            }
          }
          return;
        }

        const harness = arg.el.closest('.fc-timegrid-event-harness');
        if (!harness) return;

        harness.classList.remove('provider-split-left', 'provider-split-right');

        if (!isDualProviderSplitEnabled()) return;

        const eventProviderId = Number(
          arg.event.extendedProps?.provider_id ||
          (arg.event.extendedProps?.isDraftPlaceholder ? (pendingDraftProviderId || selectedPrimaryProviderId) : 0) ||
          0
        );

        if (eventProviderId === Number(selectedPrimaryProviderId || 0)) {
          harness.classList.add('provider-split-left');
        } else if (eventProviderId === Number(selectedSecondaryProviderId || 0)) {
          harness.classList.add('provider-split-right');
        }
      },
      dateClick: function(info) {
        const oldDraft = calendar.getEventById('booking-draft-placeholder');
        if (oldDraft) { oldDraft.remove(); }

        const clickSide = getDateClickSide(info);
        if (clickSide === 'right' && Number(selectedSecondaryProviderId || 0) > 0) {
          pendingDraftProviderId = Number(selectedSecondaryProviderId);
        } else {
          pendingDraftProviderId = Number(selectedPrimaryProviderId || 0) || getDefaultPrimaryProviderId();
        }

        const roundedStart = roundDateToSnap(info.date, bookingSnapMinutes);
        const fixedEnd = addMinutes(roundedStart, bookingSnapMinutes);

        calendar.addEvent({
          id: 'booking-draft-placeholder',
          title: '🆕 Creating Draft...',
          start: roundedStart,
          end: fixedEnd,
          color: '#2457ff',
          editable: true,
          extendedProps: {
            isDraftPlaceholder: true,
            provider_id: pendingDraftProviderId  // ← add this
          }
        });

        currentBookingStartDate = roundedStart;
        currentBookingEndDate = fixedEnd;
      },
      select: function(info) {
        const roundedStart = roundDateToSnap(info.start, bookingSnapMinutes);
        const fixedEnd = addMinutes(roundedStart, bookingSnapMinutes); 
        
        openBookingModal(roundedStart, fixedEnd);
        calendar.unselect(); 
      },
      editable: isStaff,
      eventClick: function(info) {
        const event = info.event;
if (event.display === 'background') return;

  if (event.extendedProps?.isDraftPlaceholder || event.id === 'booking-draft-placeholder') {
          currentBookingStartDate = event.start;
          currentBookingEndDate = event.end ? event.end : addMinutes(event.start, bookingSnapMinutes);
          
          openBookingModal(currentBookingStartDate, currentBookingEndDate);
          return;
        }

        const appointmentId = event.id;
        if (!appointmentId) return;
        
        fetch('appointments_api.php?action=get&id=' + encodeURIComponent(appointmentId), { credentials: 'same-origin' })
          .then(r => r.json())
          .then(json => {
            if (!json || !json.ok || !json.appointment) {
              alert('Could not load appointment details');
              return;
            }
            const appt = json.appointment;
            const cust = json.customer || {};
            const startDate = new Date(appt.start_time + 'Z');
            const endDate = new Date(appt.end_time + 'Z');
            const status = appt.status || 'unknown';
            
            openBookingModal(startDate, endDate, {
              mode: 'edit',
              title: (cust.full_name || appt.id ? ('Appt #' + appt.id) : (cust.full_name || appt.title)) + ' • ' + status,
              appointment: appt,
              customer: cust
            });
          }).catch(err => {
            alert('Could not load appointment details');
          });
      },
      eventDrop: function(info) {
        const event = info.event;

        if (event.extendedProps?.isDraftPlaceholder) {
          currentBookingStartDate = event.start;
          currentBookingEndDate = event.end ? event.end : addMinutes(event.start, bookingSnapMinutes);
          
          openBookingModal(currentBookingStartDate, currentBookingEndDate);
          setTimeout(checkTimeSlotConflicts, 50);
          return;
        }

        const appointmentId = event && event.id ? event.id : '';
        const customerId = Number(event?.extendedProps?.customer_id || 0);
        const providerValue = Number(event?.extendedProps?.provider_id || 0);
        const appointmentTypeValue = Number(event?.extendedProps?.appointment_type_id || 0);

        if (!appointmentId || !customerId || !providerValue || !appointmentTypeValue || !event.start) {
          info.revert();
          alert('Unable to move this appointment.');
          return;
        }

        const startTime = formatServerClockDateTime(event.start);
        const endTime = event.end ? formatServerClockDateTime(event.end) : formatServerClockDateTime(addMinutes(event.start, getSelectedDurationMinutes()));

        fetch('appointments_api.php?action=update', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: document.querySelector('input[name="csrf_token"]').value,
            appointment_id: appointmentId,
            selected_customer_id: customerId,
            provider_id: providerValue,
            appointment_type_id: appointmentTypeValue,
            start_time: startTime,
            end_time: endTime,
            status: normalizeAppointmentStatus(event.extendedProps?.status || 'booked'),
            notes: event.extendedProps?.notes || ''
          })
        })
          .then(function(response) {
            return response.json().then(function(data) {
              return { ok: response.ok, data: data };
            });
          })
          .then(async function(result) {
            if (!result.ok) {
              throw new Error(result.data.error || 'Failed to move appointment');
            }

            const savedAppointmentId = Number(appointmentId);
            const savedCustomerEmail = String(event.extendedProps?.customer_email || '').trim();
            const savedCustomerName = String(event.extendedProps?.customer_name || 'this customer').trim();

            if (savedAppointmentId > 0) {
              const promptResult = await showAppointmentEmailPrompt({
                appointmentId: savedAppointmentId,
                customerEmail: savedCustomerEmail,
                customerName: savedCustomerName
              });

              if (promptResult === 'revert') {
                info.revert(); 
                
                fetch('appointments_api.php?action=update', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    csrf_token: document.querySelector('input[name="csrf_token"]').value,
                    appointment_id: appointmentId,
                    selected_customer_id: customerId,
                    provider_id: providerValue,
                    appointment_type_id: appointmentTypeValue,
                    start_time: formatServerClockDateTime(info.oldEvent.start),
                    end_time: info.oldEvent.end ? formatServerClockDateTime(info.oldEvent.end) : formatServerClockDateTime(addMinutes(info.oldEvent.start, getSelectedDurationMinutes())),
                    status: normalizeAppointmentStatus(event.extendedProps?.status || 'booked'),
                    notes: event.extendedProps?.notes || ''
                  })
                }).then(() => calendar.refetchEvents());
                
                return;
              }

              if (promptResult === true) {
                try {
                  await sendAppointmentConfirmationEmail(savedAppointmentId);
                } catch (emailErr) {
                  alert(emailErr.message || 'Failed to send confirmation email.');
                }
              }
            }

            return calendar.refetchEvents();
          })
          .catch(function(err) {
            info.revert();
            alert(err.message || 'Failed to move appointment.');
          });
      }
    });

    window.calendarInstance = calendar;

    if (calendarProviderPrimary) {
      const preferredPrimary = Number(calendarProviderPrimary.value || 0) || getDefaultPrimaryProviderId();
      if (preferredPrimary) {
        selectedPrimaryProviderId = Number(preferredPrimary);
        calendarProviderPrimary.value = String(preferredPrimary);
      }
    }

    rebuildSecondaryProviderOptions();

    if (calendarProviderPrimary) {
      calendarProviderPrimary.addEventListener('change', function() {
        selectedPrimaryProviderId = Number(calendarProviderPrimary.value || 0) || getDefaultPrimaryProviderId();
        rebuildSecondaryProviderOptions();
        calendar.refetchEvents();
      });
    }

    if (calendarProviderSecondary) {
      calendarProviderSecondary.addEventListener('change', function() {
        selectedSecondaryProviderId = calendarProviderSecondary.value ? Number(calendarProviderSecondary.value) : null;
        calendar.refetchEvents();
      });
    }

    syncProviderFilterVisibility();
    calendar.render();

    (function addHourLines() {
      try {
        const slots = calendarEl.querySelectorAll('[data-time]');
        slots.forEach(el => {
          const t = el.getAttribute('data-time') || '';
          if (t.endsWith(':00:00')) {
            el.classList.add('fc-hour-line');
          }
        });
      } catch (e) {
        // fallback
      }
    })();

    const newCustomerFirstName = document.getElementById('newCustomerFirstName');
    const newCustomerLastName = document.getElementById('newCustomerLastName');
    const newCustomerDob = document.getElementById('newCustomerDob');
    const newCustomerGender = document.getElementById('newCustomerGender');
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
      const firstNameValue = newCustomerFirstName ? newCustomerFirstName.value.trim() : '';
      const lastNameValue = newCustomerLastName ? newCustomerLastName.value.trim() : '';
      const emailValue = newCustomerEmail.value.trim();
      const mobileValue = newCustomerMobile.value.trim();
      const dobValue = newCustomerDob.value.trim();
      const genderValue = newCustomerGender ? newCustomerGender.value.trim() : '';

      const firstNameRequired = Boolean(customerFieldSettings.first_name && customerFieldSettings.first_name.required);
      const lastNameRequired = Boolean(customerFieldSettings.last_name && customerFieldSettings.last_name.required);
      const middleNameRequired = Boolean(customerFieldSettings.middle_name && customerFieldSettings.middle_name.required);
      const emailRequired = Boolean(customerFieldSettings.email && customerFieldSettings.email.required);
      const mobileRequired = Boolean(customerFieldSettings.mobile && customerFieldSettings.mobile.required);
      const dobRequired = Boolean(customerFieldSettings.date_of_birth && customerFieldSettings.date_of_birth.required);
      const genderRequired = Boolean(customerFieldSettings.gender && customerFieldSettings.gender.required);

      if (firstNameRequired && !firstNameValue) {
        missing.push('first name');
      }
      if (lastNameRequired && !lastNameValue) {
        missing.push('last name');
      }
      if (middleNameRequired && !firstNameValue && !lastNameValue) {
        missing.push('middle name');
      }
      if (emailRequired && !emailValue) {
        missing.push('email');
      }
      if (mobileRequired && !mobileValue) {
        missing.push('mobile');
      }
      if (dobRequired && !dobValue) {
        missing.push('date of birth');
      }
      if (genderRequired && !genderValue) {
        missing.push('gender');
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

        if (bookingWarningModal.open) {
          if (typeof bookingWarningModal.close === 'function') {
            bookingWarningModal.close();
          } else {
            bookingWarningModal.removeAttribute('open');
          }
        }

        bookingWarningModal.returnValue = '';

        const handleConfirm = () => {
          cleanup();
          if (typeof bookingWarningModal.close === 'function') {
            bookingWarningModal.close();
          }
          resolve(true);
        };

        const handleCancel = () => {
          cleanup();
          if (typeof bookingWarningModal.close === 'function') {
            bookingWarningModal.close();
          }
          resolve(false);
        };

        const cleanup = () => {
          bookingWarningConfirm.removeEventListener('click', handleConfirm);
          bookingWarningCancel.removeEventListener('click', handleCancel);
        };

        bookingWarningConfirm.addEventListener('click', handleConfirm, { once: true });
        bookingWarningCancel.addEventListener('click', handleCancel, { once: true });
        
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

    function getAppointmentEmailWarning(customerEmail) {
      if (!String(customerEmail || '').trim()) {
        return 'Email not recorded for this customer';
      }

      if (!appointmentEmailDeliveryReady) {
        return 'Mail settings not configured';
      }

      return '';
    }

    function setAppointmentEmailPromptState(context) {
      if (!appointmentEmailModal) {
        return;
      }

      const warning = getAppointmentEmailWarning(context && context.customerEmail);
      if (appointmentEmailMessage) {
        appointmentEmailMessage.textContent = 'Send a confirmation email to ' + (context && context.customerName ? context.customerName : 'this customer') + '?';
      }
      if (appointmentEmailWarning) {
        if (warning) {
          appointmentEmailWarning.textContent = warning;
          appointmentEmailWarning.style.display = '';
        } else {
          appointmentEmailWarning.textContent = '';
          appointmentEmailWarning.style.display = 'none';
        }
      }
      if (appointmentEmailSend) {
        appointmentEmailSend.disabled = Boolean(warning);
      }
    }

    function hideAppointmentEmailPrompt() {
      if (appointmentEmailModal && typeof appointmentEmailModal.close === 'function') {
        appointmentEmailModal.close();
      } else if (appointmentEmailModal) {
        appointmentEmailModal.removeAttribute('open');
      }
      pendingEmailPromptContext = null;
    }

    function showAppointmentEmailPrompt(context) {
      pendingEmailPromptContext = context;
      setAppointmentEmailPromptState(context);

      if (appointmentEmailModal && appointmentEmailModal.open && typeof appointmentEmailModal.close === 'function') {
        appointmentEmailModal.close();
      }

      return new Promise((resolve) => {
        if (!appointmentEmailModal) {
          resolve(false);
          return;
        }

        const cleanup = () => {
          if (appointmentEmailSend) appointmentEmailSend.removeEventListener('click', handleSend);
          if (appointmentEmailSkip) appointmentEmailSkip.removeEventListener('click', handleSkip);
          if (appointmentEmailCancel) appointmentEmailCancel.removeEventListener('click', handleRevert); 
          appointmentEmailModal.removeEventListener('cancel', handleCancel);
        };

        const finish = (value) => {
          cleanup();
          hideAppointmentEmailPrompt();
          resolve(value);
        };

        const handleSend = () => finish(true);
        const handleSkip = () => finish(false);
        const handleRevert = () => finish('revert'); 
        const handleCancel = function(event) {
          event.preventDefault();
          finish('revert'); 
        };

        if (appointmentEmailSend) appointmentEmailSend.addEventListener('click', handleSend, { once: true });
        if (appointmentEmailSkip) appointmentEmailSkip.addEventListener('click', handleSkip, { once: true });
        if (appointmentEmailCancel) appointmentEmailCancel.addEventListener('click', handleRevert, { once: true }); 
        appointmentEmailModal.addEventListener('cancel', handleCancel, { once: true });

        if (typeof appointmentEmailModal.showModal === 'function') {
          appointmentEmailModal.showModal();
        } else {
          appointmentEmailModal.setAttribute('open', 'open');
        }
      });
    }

    async function sendAppointmentConfirmationEmail(appointmentId) {
      const response = await fetch('appointments_api.php?action=send_confirmation_email', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          csrf_token: document.querySelector('input[name="csrf_token"]').value,
          appointment_id: appointmentId
        })
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.error || 'Failed to send confirmation email');
      }

      return data;
    }

    const createCustomerTab = document.getElementById('createCustomerTab');
    const existingCustomerTab = document.getElementById('existingCustomerTab');
    const createCustomerPanel = document.getElementById('createCustomerPanel');
    const existingCustomerPanel = document.getElementById('existingCustomerPanel');
    const customerSearchInput = document.getElementById('customerSearchInput');
    const customerSearchState = document.getElementById('customerSearchState');
    const customerSearchResults = document.getElementById('customerSearchResults');
    const selectedCustomerCard = document.getElementById('selectedCustomerCard');
    const selectedCustomerName = document.getElementById('selectedCustomerName');
    const selectedCustomerDetails = document.getElementById('selectedCustomerDetails');
    const clearSelectedCustomerBtn = document.getElementById('clearSelectedCustomerBtn');
    let currentCustomerMode = 'create';
    let selectedCustomerRecord = null;
    let customerSearchTimer = null;
    let customerSearchRequestId = 0;

    function formatCustomerContact(customer) {
      const parts = [];
      if (customer.email) parts.push(customer.email);
      if (customer.mobile) parts.push(customer.mobile);
      return parts.length ? parts.join(' • ') : 'No contact details';
    }

    function escapeHtml(str) {
      return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function normalizeAppointmentStatus(status) {
      const value = String(status || '').trim().toLowerCase().replace(/_/g, ' ');
      return appointmentStatusValues.indexOf(value) >= 0 ? value : 'booked';
    }

    function syncAppointmentStatusUI(status) {
      const normalized = normalizeAppointmentStatus(status);
      currentEditingAppointmentStatus = normalized;
      if (appointmentStatusSelect) {
        appointmentStatusSelect.value = normalized;
      }
    }

    function cycleAppointmentStatus() {
      const currentIndex = appointmentStatusValues.indexOf(normalizeAppointmentStatus(appointmentStatusSelect ? appointmentStatusSelect.value : currentEditingAppointmentStatus));
      const nextStatus = appointmentStatusValues[(currentIndex + 1) % appointmentStatusValues.length];
      syncAppointmentStatusUI(nextStatus);
    }

    async function saveAppointmentStatus(status) {
      if (!currentEditingAppointmentId) {
        return false;
      }

      const normalizedStatus = normalizeAppointmentStatus(status);
      const payload = {
        csrf_token: document.querySelector('input[name="csrf_token"]').value,
        appointment_id: currentEditingAppointmentId,
        selected_customer_id: selectedCustomerId.value.trim(),
        provider_id: providerSelect.value.trim(),
        appointment_type_id: appointmentTypeSelect.value.trim(),
        start_time: bookingStart.value.trim(),
        status: normalizedStatus,
        notes: bookingNotes ? bookingNotes.value.trim() : ''
      };

      if (bookingEndOverrideActive && bookingEnd) {
        payload.end_time = bookingEnd.value.trim();
      }

      const response = await fetch('appointments_api.php?action=update', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.error || 'Failed to update appointment status');
      }

      await calendar.refetchEvents();
      syncAppointmentStatusUI(normalizedStatus);
      return true;
    }

    function syncNotesVisibility(forceExpanded = null) {
      if (!bookingNotesPanel || !bookingNotes) {
        return;
      }

      const hasNotes = bookingNotes.value.trim().length > 0;
      if (forceExpanded !== null) {
        notesExpanded = Boolean(forceExpanded);
      }

      const shouldShow = hasNotes || notesExpanded;
      bookingNotesPanel.classList.toggle('is-collapsed', !shouldShow);
    }

    function toggleNotesVisibility() {
      notesExpanded = !notesExpanded;
      syncNotesVisibility();
    }
function isAppointmentInPast(startTimeValue) {
  const raw = String(startTimeValue || '').trim();
  if (!raw) return false;

  // Parse as server local time, not UTC
  const normalized = raw.replace(' ', 'T');
  const serverClockDate = new Date(normalized + 'Z');
  const instant = serverClockDateToInstant(serverClockDate);

  if (Number.isNaN(instant.getTime())) return false;

  return instant.getTime() < getServerNowDate().getTime();
}

    function isBookingWithinDeadline(startTimeValue) {
      const startDate = parseDateValue(startTimeValue);
      if (Number.isNaN(startDate.getTime()) || bookingDeadlineHours <= 0) {
        return false;
      }

      const deadlineWindowMs = bookingDeadlineHours * 60 * 60 * 1000;
      return startDate.getTime() < (getServerNowDate().getTime() + deadlineWindowMs);
    }

    function clearCreateCustomerFields() {
      if (newCustomerFirstName) {
        newCustomerFirstName.value = '';
      }
      if (newCustomerLastName) {
        newCustomerLastName.value = '';
      }
      newCustomerDob.value = '';
      if (newCustomerGender) {
        newCustomerGender.value = '';
      }
      newCustomerEmail.value = '';
      newCustomerMobile.value = '';
    }

    function updateCustomerSearchSelection() {
      if (!customerSearchResults) {
        return;
      }
      customerSearchResults.querySelectorAll('[data-customer-id]').forEach(item => {
        item.classList.toggle('is-selected', Boolean(selectedCustomerRecord) && item.getAttribute('data-customer-id') === String(selectedCustomerRecord.id));
      });
    }

    function renderCustomerSearchState(message) {
      if (customerSearchState) {
        customerSearchState.textContent = message;
      }
      if (customerSearchResults) {
        customerSearchResults.innerHTML = '';
      }
    }

    function showSelectedCustomer(customer) {
      selectedCustomerRecord = customer;
      selectedCustomerId.value = String(customer.id);
      selectedCustomerName.textContent = customer.full_name || 'Selected customer';
      selectedCustomerDetails.textContent = formatCustomerContact(customer);
      selectedCustomerCard.style.display = 'block';
      if (customerSearchState) {
        customerSearchState.textContent = 'Selected customer saved. Search again to change it.';
      }
      updateCustomerSearchSelection();
    }

    function clearSelectedCustomer() {
      selectedCustomerRecord = null;
      selectedCustomerId.value = '';
      selectedCustomerName.textContent = '';
      selectedCustomerDetails.textContent = '';
      selectedCustomerCard.style.display = 'none';
      updateCustomerSearchSelection();
    }

    function renderCustomerSearchResults(customers) {
      if (!customerSearchResults || !customerSearchState) {
        return;
      }

      if (!customers.length) {
        renderCustomerSearchState('No matching customers found.');
        return;
      }

      customerSearchState.textContent = `Found ${customers.length} matching customer${customers.length === 1 ? '' : 's'}.`;
      customerSearchResults.innerHTML = '';

      customers.forEach(customer => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'customer-search-result';
        button.setAttribute('data-customer-id', String(customer.id));
        button.innerHTML = `<strong>${escapeHtml(customer.full_name || 'Unnamed customer')}</strong><span>${escapeHtml(formatCustomerContact(customer))}</span>`;
        button.addEventListener('click', () => showSelectedCustomer(customer));
        customerSearchResults.appendChild(button);
      });

      updateCustomerSearchSelection();
    }

    async function searchExistingCustomers(query) {
      const term = String(query || '').trim();
      const requestId = ++customerSearchRequestId;

      clearSelectedCustomer();

      if (term.length < 2) {
        renderCustomerSearchState(term ? 'Type at least 2 characters to search.' : 'Start typing to search.');
        return;
      }

      if (customerSearchState) {
        customerSearchState.textContent = 'Searching...';
      }
      if (customerSearchResults) {
        customerSearchResults.innerHTML = '';
      }

      try {
        const params = new URLSearchParams({ action: 'search', q: term, limit: '8' });
        const resp = await fetch(`customers_api.php?${params}`);
        if (!resp.ok) throw new Error('Failed to search customers');
        const json = await resp.json();
        if (requestId !== customerSearchRequestId) {
          return;
        }
        renderCustomerSearchResults(json.customers || []);
      } catch (err) {
        if (requestId !== customerSearchRequestId) {
          return;
        }
        renderCustomerSearchState(err.message || 'Failed to search customers.');
      }
    }

    function setCustomerMode(mode) {
      currentCustomerMode = mode;
      createCustomerTab.classList.toggle('is-active', mode === 'create');
      existingCustomerTab.classList.toggle('is-active', mode === 'existing');
      createCustomerTab.setAttribute('aria-selected', mode === 'create' ? 'true' : 'false');
      existingCustomerTab.setAttribute('aria-selected', mode === 'existing' ? 'true' : 'false');
      createCustomerPanel.style.display = mode === 'create' ? 'grid' : 'none';
      existingCustomerPanel.style.display = mode === 'existing' ? 'grid' : 'none';

      if (mode === 'create') {
        clearSelectedCustomer();
        clearCreateCustomerFields();
        if (customerSearchInput) {
          customerSearchInput.value = '';
        }
        renderCustomerSearchState('Start typing to search.');
      } else {
        clearCreateCustomerFields();
        if (customerSearchInput) {
          customerSearchInput.focus();
          searchExistingCustomers(customerSearchInput.value);
        }
      }
    }


    createCustomerTab.addEventListener('click', () => setCustomerMode('create'));
    existingCustomerTab.addEventListener('click', () => setCustomerMode('existing'));

    if (customerSearchInput) {
      customerSearchInput.addEventListener('input', () => {
        window.clearTimeout(customerSearchTimer);
        customerSearchTimer = window.setTimeout(() => {
          if (currentCustomerMode === 'existing') {
            searchExistingCustomers(customerSearchInput.value);
          }
        }, 220);
      });

      customerSearchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          if (currentCustomerMode === 'existing') {
            searchExistingCustomers(customerSearchInput.value);
          }
        }
      });
    }

    clearSelectedCustomerBtn.addEventListener('click', clearSelectedCustomer);

   setCustomerMode('create');

    function setDraftMode() {
      const cardElement = bookingModal.querySelector('.booking-card');
      if (cardElement) cardElement.classList.remove('is-invalid-time');
      const draft = window.calendarInstance?.getEventById('booking-draft-placeholder');
      if (draft) { draft.remove(); }

      bookingModal.setAttribute('data-mode', 'draft');
      bookingModalTitle.textContent = 'New booking draft';
      currentBookingStartDate = null;
      currentBookingEndDate = null;
      currentEditingAppointmentId = null;
      syncAppointmentStatusUI('booked');
      notesExpanded = false;
      syncBookingNotes('');
      if (makeBookingBtn) {
        makeBookingBtn.textContent = 'Make booking';
      }
      if (deleteBookingBtn) {
        deleteBookingBtn.style.display = 'none';
      }
      setBookingAlert('');
      clearSelectedCustomer();
      clearCreateCustomerFields();
      if (customerSearchInput) {
        customerSearchInput.value = '';
      }
      renderCustomerSearchState('Start typing to search.');
      setCustomerMode('create');
    }

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

    submitBooking._running = false;

  async function submitBooking() {
      if (submitBooking._running) return;
      submitBooking._running = true;

      const mode = bookingModal.getAttribute('data-mode');
      const isEdit = mode === 'edit' && currentEditingAppointmentId;
      const selectedCustomerValue = selectedCustomerId.value.trim();
      const firstNameValue = newCustomerFirstName ? newCustomerFirstName.value.trim() : '';
      const lastNameValue = newCustomerLastName ? newCustomerLastName.value.trim() : '';
      const customerDob = newCustomerDob.value.trim();
      const customerGender = newCustomerGender ? newCustomerGender.value.trim() : '';
      const customerEmail = newCustomerEmail.value.trim();
      const customerMobile = newCustomerMobile.value.trim();
      const providerId = providerSelect.value.trim();
      const appointmentTypeId = appointmentTypeSelect.value.trim();
      const startTime = bookingStart.value.trim();

      if (!providerId) {
        submitBooking._running = false;
        setBookingAlert('Choose a provider before making the booking.');
        return;
      }
      if (!appointmentTypeId) {
        submitBooking._running = false;
        setBookingAlert('Choose an appointment type before making the booking.');
        return;
      }
      if (currentCustomerMode === 'existing') {
        if (!selectedCustomerValue) {
          submitBooking._running = false;
          setBookingAlert('Select a customer from the search results.');
          return;
        }
      } else if (!firstNameValue && !lastNameValue) {
        submitBooking._running = false;
        setBookingAlert('Enter a customer name or switch to existing customer search.');
        return;
      }

      // 1. Compile warnings dynamically using updated calendar data tracks
      const confirmationQueue = [];

      if (checkTimeSlotConflicts()) {
        confirmationQueue.push('This booking overlaps with an existing appointment, shift break, or provider unavailability track.');
      }
      if (isAppointmentInPast(startTime)) {
        confirmationQueue.push('This appointment is in the past.');
      } else if (isBookingWithinDeadline(startTime)) {
        confirmationQueue.push('This booking is within the ' + bookingDeadlineHours + '-hour booking deadline window.');
      }
      if (isBookingOutsideDisplayTime()) {
        confirmationQueue.push('This booking is outside the configured calendar display layout times (' + visibleStartTime.slice(0, 5) + ' to ' + visibleEndTime.slice(0, 5) + ').');
      }
      const missingFields = getCustomerMissingRequiredFields();
      if (missingFields.length) {
        confirmationQueue.push('The new customer profile is missing required info fields: ' + missingFields.join(', ') + '.');
      }

      // 2. Process all confirmations sequentially BEFORE disabling UI button actions
      async function processWarningsSequentially(queue) {
        for (let i = 0; i < queue.length; i++) {
          setBookingAlert(queue[i], false);
          const proceed = await showBookingWarning(queue[i] + ' Do you want to override this constraint and save anyway?');
          if (!proceed) {
            return false;
          }
          await new Promise(r => setTimeout(r, 120));
        }
        return true;
      }

      const allWarningsCleared = await processWarningsSequentially(confirmationQueue);
      if (!allWarningsCleared) {
        submitBooking._running = false;
        setBookingAlert('Booking execution halted by user override.');
        return;
      }

      // 3. Clear alert states and flip submission button flags safely
      setBookingAlert('');
      makeBookingBtn.disabled = true;
      makeBookingBtn.textContent = mode === 'edit' ? 'Save changes...' : 'Saving...';

      try {
        const customerName = [firstNameValue, lastNameValue].filter(Boolean).join(' ').trim();
        const url = 'appointments_api.php?action=' + (isEdit ? 'update' : 'create');
        const statusValue = isEdit && appointmentStatusSelect ? normalizeAppointmentStatus(appointmentStatusSelect.value) : 'booked';
        const notesValue = bookingNotes ? bookingNotes.value.trim() : '';
        
        const resp = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(Object.assign({
            csrf_token: document.querySelector('input[name="csrf_token"]').value,
            selected_customer_id: selectedCustomerValue,
            customer_name: customerName,
            customer_first_name: firstNameValue,
            customer_last_name: lastNameValue,
            customer_email: customerEmail,
            customer_mobile: customerMobile,
            date_of_birth: customerDob,
            customer_gender: customerGender,
            provider_id: providerId,
            appointment_type_id: appointmentTypeId,
            start_time: startTime,
            status: statusValue,
            notes: notesValue
          }, (isEdit ? { appointment_id: currentEditingAppointmentId } : {}), (bookingEndOverrideActive ? { end_time: bookingEnd.value } : {})))
        });

        const json = await resp.json();
        if (!resp.ok) {
          throw new Error(json.error || 'The backend application endpoint rejected the submission data structure.');
        }

        const savedAppointmentId = Number(json.appointment && json.appointment.id ? json.appointment.id : 0);
        const savedCustomerEmail = String(json.customer && json.customer.email ? json.customer.email : '').trim();
        const savedCustomerName = String(json.customer && json.customer.full_name ? json.customer.full_name : customerName || 'this customer').trim();

        // 4. Trigger email routine confirmation prompts seamlessly
        if (savedAppointmentId > 0) {
          while (true) {
            const sendEmail = await showAppointmentEmailPrompt({
              appointmentId: savedAppointmentId,
              customerEmail: savedCustomerEmail,
              customerName: savedCustomerName
            });

            if (!sendEmail) break;

            makeBookingBtn.disabled = true;
            makeBookingBtn.textContent = 'Sending email...';
            try {
              await sendAppointmentConfirmationEmail(savedAppointmentId);
              break;
            } catch (emailErr) {
              setBookingAlert(emailErr.message || 'The booking transaction was successful, but your confirmation email could not be delivered.', true);
              break;
            }
          }
        }

        await calendar.refetchEvents();
        if (typeof bookingModal.close === 'function') {
          bookingModal.close();
        } else {
          bookingModal.removeAttribute('open');
        }
        setDraftMode();
      } catch (err) {
        setBookingAlert(err.message || (mode === 'edit' ? 'Failed to update booking structure.' : 'Failed to save appointment.'));
      } finally {
        submitBooking._running = false;
        makeBookingBtn.disabled = false;
        makeBookingBtn.textContent = mode === 'edit' ? 'Save changes' : 'Make booking';
      }
    }

    if (makeBookingBtn) {
      makeBookingBtn.addEventListener('click', submitBooking);
    }

    if (deleteBookingBtn) {
      deleteBookingBtn.addEventListener('click', async function() {
        if (!currentEditingAppointmentId) {
          return;
        }

        const confirmed = window.confirm('Delete this appointment? This cannot be undone.');
        if (!confirmed) {
          return;
        }

        deleteBookingBtn.disabled = true;
        deleteBookingBtn.textContent = 'Deleting...';
        try {
          const resp = await fetch('appointments_api.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              csrf_token: document.querySelector('input[name="csrf_token"]').value,
              appointment_id: currentEditingAppointmentId
            })
          });
          const json = await resp.json();
          if (!resp.ok) {
            throw new Error(json.error || 'Failed to delete appointment');
          }

          await calendar.refetchEvents();
          if (typeof bookingModal.close === 'function') {
            bookingModal.close();
          } else {
            bookingModal.removeAttribute('open');
          }
          setDraftMode();
        } catch (err) {
          setBookingAlert(err.message || 'Failed to delete appointment.');
        } finally {
          deleteBookingBtn.disabled = false;
          deleteBookingBtn.textContent = 'Delete';
        }
      });
    }

    appointmentTypeSelect.addEventListener('change', function() {
      if (bookingModal.getAttribute('data-mode') === 'draft') {
        syncBookingDraftTimes();
        return;
      }
      if (bookingEndOverrideActive) {
        bookingEndOverrideActive = false;
      }
      applyVisibleTimeInputsToHidden();
      checkTimeSlotConflicts();
    });

    if (providerSelect) {
      providerSelect.addEventListener('change', function() {
        if (bookingModal.getAttribute('data-mode') === 'draft') {
          const placeholder = window.calendarInstance?.getEventById('booking-draft-placeholder');
          if (placeholder) {
            placeholder.setExtendedProp('provider_id', Number(providerSelect.value || 0));
          }
        }
        checkTimeSlotConflicts();
      });
    }

    if (bookingStartTimeInput) {
      bookingStartTimeInput.addEventListener('change', function() {
        bookingEndOverrideActive = false;
        applyVisibleTimeInputsToHidden();
      });
    }

    if (bookingEndTimeInput) {
      bookingEndTimeInput.addEventListener('change', function() {
        bookingEndOverrideActive = true;
        applyVisibleTimeInputsToHidden();
      });
    }

    if (appointmentStatusSelect) {
      appointmentStatusSelect.addEventListener('change', function() {
        syncAppointmentStatusUI(appointmentStatusSelect.value);
      });
    }

    if (cycleAppointmentStatusBtn) {
      cycleAppointmentStatusBtn.addEventListener('click', async function() {
        cycleAppointmentStatus();
        if (bookingModal.getAttribute('data-mode') === 'edit' && currentEditingAppointmentId) {
          cycleAppointmentStatusBtn.disabled = true;
          cycleAppointmentStatusBtn.textContent = 'Saving...';
          try {
            await saveAppointmentStatus(appointmentStatusSelect ? appointmentStatusSelect.value : currentEditingAppointmentStatus);
          } catch (err) {
            setBookingAlert(err.message || 'Failed to save appointment status.');
          } finally {
            cycleAppointmentStatusBtn.disabled = false;
            cycleAppointmentStatusBtn.textContent = 'Toggle';
          }
        }
      });
    }

    if (bookingNotesLabel) {
      bookingNotesLabel.addEventListener('click', function(event) {
        event.preventDefault();
        toggleNotesVisibility();
      });
    }

    if (bookingNotes) {
      bookingNotes.addEventListener('input', function() {
        syncNotesVisibility();
      });
    }

    function applyVisibleTimeInputsToHidden() {
      try {
        const startIso = bookingStart.value.trim();
        if (!startIso) return;
        const datePart = startIso.split(' ')[0];
        const startTimePart = (bookingStartTimeInput && bookingStartTimeInput.value) ? bookingStartTimeInput.value + ':00' : getServerTimePartFromDateTime(startIso) || '00:00:00';
        const newStart = datePart + ' ' + (startTimePart.length === 8 ? startTimePart : startTimePart + ':00');
        bookingStart.value = newStart;

        if (bookingEndOverrideActive) {
          const endTimePart = (bookingEndTimeInput && bookingEndTimeInput.value) ? bookingEndTimeInput.value + ':00' : getServerTimePartFromDateTime(bookingEnd.value) || startTimePart;
          bookingEnd.value = datePart + ' ' + (endTimePart.length === 8 ? endTimePart : endTimePart + ':00');
        } else {
          const opt = appointmentTypeSelect.options[appointmentTypeSelect.selectedIndex];
          const duration = Number(opt?.dataset?.duration || fallbackDurationMinutes);
          const startDate = new Date(newStart + 'Z');
          const endDate = addMinutes(startDate, duration);
          const yyyy = endDate.getUTCFullYear();
          const mm = String(endDate.getUTCMonth() + 1).padStart(2, '0');
          const dd = String(endDate.getUTCDate()).padStart(2, '0');
          const hh = String(endDate.getUTCHours()).padStart(2, '0');
          const mi = String(endDate.getUTCMinutes()).padStart(2, '0');
          const ss = String(endDate.getUTCSeconds()).padStart(2, '0');
          bookingEnd.value = `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`;
          if (bookingEndTimeInput) bookingEndTimeInput.value = `${hh}:${mi}`;
        }

        const calendar = window.calendarInstance;
        const sd = new Date(bookingStart.value + 'Z');
        const ed = new Date(bookingEnd.value + 'Z');
        selectedStartLabel.textContent = formatCalendarDate(calendar, sd);
        selectedEndLabel.textContent = formatCalendarDate(calendar, ed);

        setTimeout(checkTimeSlotConflicts, 100);
      } catch (e) {
        // ignore
      }
    }

    function checkTimeSlotConflicts() {
      const startIso = bookingStart.value.trim();
      const endIso = bookingEnd.value.trim();
      const selectedBookingProviderId = Number(providerSelect?.value || 0);

      if (!startIso || !endIso) return false;

      const draftStart = new Date(startIso.replace(' ', 'T') + 'Z');
      const draftEnd = new Date(endIso.replace(' ', 'T') + 'Z');

      if (Number.isNaN(draftStart.getTime())) return false;

      const hasTimeConflict = window.calendarInstance.getEvents().some(function(event) {
        if (event.id === 'booking-draft-placeholder' || event.extendedProps?.isDraftPlaceholder) return false;
        if (currentEditingAppointmentId && String(event.id) === String(currentEditingAppointmentId)) return false;

        if (event.display === 'background') {
          if (event.classNames.includes('unavail-event') || event.classNames.includes('standard-break-event')) {
            const bgProviderId = Number(event.extendedProps?.provider_id || 0);
            if (bgProviderId > 0 && selectedBookingProviderId > 0 && bgProviderId !== selectedBookingProviderId) {
              return false;
            }
            return event.start < draftEnd && (event.end || event.start) > draftStart;
          }
          return false;
        }

        const eventProviderId = Number(event.extendedProps?.provider_id || 0);
        if (selectedBookingProviderId > 0 && eventProviderId > 0 && eventProviderId !== selectedBookingProviderId) {
          return false;
        }

        return event.start < draftEnd && (event.end || event.start) > draftStart;
      });

      const cardElement = bookingModal.querySelector('.booking-card');
      if (hasTimeConflict) {
        if (cardElement) cardElement.classList.add('is-invalid-time');
        if (!submitBooking._running) {
          setBookingAlert('🚨 Selected time overlaps with an appointment, break, or unavailability.', false);
        }
      } else {
        if (cardElement) cardElement.classList.remove('is-invalid-time');
        if (!submitBooking._running) {
          setBookingAlert('');
        }
      }

      return hasTimeConflict;
    }
  </script>

  <script src="assets/js/global.js"></script>
</body>
</html>