<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();

$siteName = trim((string) get_setting('site_name', APP_NAME ?? 'Boomy Appointments'));
if ($siteName === '') {
    $siteName = APP_NAME ?? 'Boomy Appointments';
}
$csrfToken = csrf_token();

$disableOnlineBooking = (int) get_setting('disable_online_booking', '0');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Book an Appointment - <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    :root {
      --bg: #f4f7fb;
      --panel: rgba(255, 255, 255, 0.92);
      --panel-strong: #ffffff;
      --text: #1d2433;
      --muted: #667085;
      --border: rgba(18, 27, 43, 0.12);
      --accent: #2457ff;
      --accent-strong: #1639b5;
      --accent-soft: rgba(36, 87, 255, 0.12);
      --danger: #d92d20;
      --success: #067647;
      --shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
      --shadow-soft: 0 12px 32px rgba(15, 23, 42, 0.08);
      --slot: #0f172a;
      --slot-soft: rgba(15, 23, 42, 0.06);
      --selected: #2457ff;
      --current: #f59e0b;
      --disabled: #c7ceda;
      --hero-bg-blend: rgba(255, 255, 255, 0.78);
      color-scheme: light;
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #0b1220;
        --panel: rgba(15, 23, 42, 0.88);
        --panel-strong: #111827;
        --text: #f3f6fb;
        --muted: #a8b3c7;
        --border: rgba(255, 255, 255, 0.12);
        --accent: #7ca2ff;
        --accent-strong: #9eb8ff;
        --accent-soft: rgba(124, 162, 255, 0.16);
        --shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
        --shadow-soft: 0 12px 32px rgba(0, 0, 0, 0.22);
        --slot: #f8fafc;
        --slot-soft: rgba(255, 255, 255, 0.06);
        --disabled: #59657a;
        --hero-bg-blend: rgba(15, 23, 42, 0.65);
        color-scheme: dark;
      }
    }

    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at top left, rgba(36, 87, 255, 0.18), transparent 28%),
        radial-gradient(circle at 85% 18%, rgba(245, 158, 11, 0.14), transparent 24%),
        var(--bg);
      color: var(--text);
    }

    a { color: inherit; }
    button, input, select, textarea { font: inherit; }

    .booking-shell {
      width: min(1240px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 48px;
    }

  .hero {
    display: grid;
    gap: 18px;
    padding: 28px;
    border: 1px solid var(--border);
    border-radius: 28px;
    background: linear-gradient(135deg, rgba(36, 87, 255, 0.08), var(--hero-bg-blend));
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
  }

    .hero::after {
      content: '';
      position: absolute;
      inset: auto -20% -80px auto;
      width: 260px;
      height: 260px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(36, 87, 255, 0.16), transparent 68%);
      pointer-events: none;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      width: fit-content;
      padding: 8px 12px;
      border-radius: 999px;
      background: var(--panel);
      border: 1px solid var(--border);
      color: var(--muted);
      font-size: 0.92rem;
      box-shadow: var(--shadow-soft);
    }

    .hero h1 {
      margin: 0;
      font-size: clamp(2rem, 5vw, 4rem);
      line-height: 0.98;
      letter-spacing: -0.05em;
    }

    .hero p {
      margin: 0;
      max-width: 62ch;
      color: var(--muted);
      font-size: 1.02rem;
      line-height: 1.6;
    }

    .stepper {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin: 24px 0;
    }

    .stepper button {
      border: 1px solid var(--border);
      background: var(--panel);
      color: var(--muted);
      border-radius: 18px;
      padding: 14px 16px;
      text-align: left;
      box-shadow: var(--shadow-soft);
      cursor: pointer;
      transition: transform 0.18s ease, border-color 0.18s ease, color 0.18s ease, background 0.18s ease;
    }

    .stepper button.is-active {
      color: var(--text);
      border-color: color-mix(in srgb, var(--accent) 30%, var(--border));
      background: linear-gradient(180deg, var(--panel-strong), var(--panel));
      transform: translateY(-1px);
    }

    .stepper button strong {
      display: block;
      color: inherit;
      margin-bottom: 4px;
      font-size: 0.98rem;
    }

    .stepper button span {
      display: block;
      font-size: 0.84rem;
      line-height: 1.35;
    }

    .stepper button { cursor: not-allowed; }
    .stepper button.is-reachable { cursor: pointer; }

    .panel {
      display: none;
      border: 1px solid var(--border);
      border-radius: 28px;
      background: var(--panel);
      box-shadow: var(--shadow);
      padding: 24px;
      backdrop-filter: blur(18px);
    }

    .panel.is-active { display: block; animation: fadeUp 0.22s ease; }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .panel-head {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 18px;
    }

    .panel-head h2 {
      margin: 0;
      font-size: 1.45rem;
      letter-spacing: -0.03em;
    }

    .panel-head p {
      margin: 0;
      color: var(--muted);
      max-width: 68ch;
      line-height: 1.55;
    }

    .grid-2 {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    .field {
      display: grid;
      gap: 8px;
      min-width: 0;
    }

    .field label {
      font-weight: 650;
      letter-spacing: -0.01em;
    }

    .field input,
    .field select,
    .field textarea {
      width: 100%;
      border: 1px solid var(--border);
      background: var(--panel-strong);
      color: var(--text);
      border-radius: 16px;
      padding: 13px 14px;
      outline: none;
      transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
    }

    .field textarea { min-height: 120px; resize: vertical; }

    .field input:focus,
    .field select:focus,
    .field textarea:focus {
      border-color: color-mix(in srgb, var(--accent) 65%, white);
      box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 18%, transparent);
    }

    .actions {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      margin-top: 20px;
      flex-wrap: wrap;
    }

    .btn {
      appearance: none;
      border: 1px solid var(--border);
      background: var(--panel-strong);
      color: var(--text);
      border-radius: 999px;
      padding: 12px 18px;
      cursor: pointer;
      font-weight: 650;
      transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-soft); }
    .btn.primary {
      background: linear-gradient(135deg, var(--accent), var(--accent-strong));
      color: #fff;
      border-color: transparent;
    }
    .btn.ghost { background: transparent; }
    .btn.danger { background: rgba(217, 45, 32, 0.08); color: var(--danger); }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

    .calendar-layout {
      display: grid;
      grid-template-columns: minmax(0, 2fr) minmax(280px, 360px);
      gap: 20px;
      align-items: start;
    }

    .months {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    .month-card,
    .slots-card,
    .summary-card,
    .customer-card,
    .verify-card {
      border: 1px solid var(--border);
      border-radius: 24px;
      background: var(--panel-strong);
      box-shadow: var(--shadow-soft);
    }

    .month-card { padding: 16px; }

    .month-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
    }

    .month-head h3 { margin: 0; font-size: 1.12rem; letter-spacing: -0.02em; }
    .month-head small { color: var(--muted); }

    .weekday-row,
    .day-grid {
      display: grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap: 8px;
    }

    .weekday-row {
      margin-bottom: 10px;
      color: var(--muted);
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .weekday-row span,
    .day-cell {
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      min-height: 40px;
    }

    .day-cell {
      position: relative;
      border: 0;
      background: transparent;
      color: var(--text);
      border-radius: 14px;
      cursor: pointer;
      font-weight: 650;
      padding: 0;
      min-height: 44px;
      transition: transform 0.16s ease, background 0.16s ease, color 0.16s ease;
    }

    .day-cell:hover { transform: translateY(-1px); }
    .day-cell.is-empty { pointer-events: none; color: transparent; }
    .day-cell.is-disabled { color: var(--disabled); background: color-mix(in srgb, var(--slot-soft) 72%, transparent); cursor: not-allowed; }
    .day-cell.is-disabled .day-badge { opacity: 0.45; }
    .day-cell.is-today .day-badge {
      border-color: var(--current);
      box-shadow: 0 0 0 4px color-mix(in srgb, var(--current) 18%, transparent);
    }
    .day-cell.is-selected .day-badge {
      background: var(--selected);
      color: #fff;
      border-color: var(--selected);
    }
    .day-cell.is-available { color: var(--slot); }
    .day-cell.is-available .day-badge {
      border-color: color-mix(in srgb, var(--text) 14%, var(--border));
    }
    .day-cell.is-selected.is-today .day-badge {
      box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 18%, transparent);
    }
    .day-badge {
      width: 34px;
      height: 34px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      border: 1px solid transparent;
      background: transparent;
      line-height: 1;
      font-size: 0.96rem;
    }

    .day-mark {
      position: absolute;
      inset: 0;
      border-radius: 999px;
      background: rgba(6, 118, 71, 0.12);
      border: 1.5px solid rgba(6, 118, 71, 0.35);
      pointer-events: none;
      z-index: -1;
    }

    .slots-card { padding: 18px; position: sticky; top: 18px; }
    .slots-card h3 { margin: 0 0 6px; font-size: 1.2rem; }
    .slots-card p { margin: 0 0 14px; color: var(--muted); line-height: 1.5; }
    .slot-list {
      display: grid;
      gap: 10px;
      margin-top: 12px;
    }
    .slot-btn {
      border: 1px solid var(--border);
      background: linear-gradient(180deg, var(--panel-strong), var(--panel));
      color: var(--text);
      border-radius: 16px;
      padding: 12px 14px;
      text-align: left;
      cursor: pointer;
      font-weight: 650;
    }
    .slot-btn.is-selected {
      border-color: var(--selected);
      background: color-mix(in srgb, var(--selected) 12%, var(--panel-strong));
    }
    .slot-meta {
      display: block;
      color: var(--muted);
      font-size: 0.84rem;
      margin-top: 4px;
      font-weight: 500;
    }

    .tabs {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    .tabs button {
      border: 1px solid var(--border);
      background: transparent;
      color: var(--muted);
      border-radius: 999px;
      padding: 10px 16px;
      cursor: pointer;
      font-weight: 650;
    }

    .tabs button.is-active {
      color: var(--text);
      background: var(--accent-soft);
      border-color: color-mix(in srgb, var(--accent) 25%, var(--border));
    }

    .customer-pane { display: none; }
    .customer-pane.is-active { display: block; }

    .customer-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
    }

    .customer-grid .span-2 { grid-column: span 2; }
    .customer-grid .span-3 { grid-column: 1 / -1; }

    .summary-card { padding: 18px; display: grid; gap: 16px; }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .summary-item {
      padding: 14px;
      border-radius: 18px;
      background: color-mix(in srgb, var(--slot-soft) 60%, transparent);
      border: 1px solid var(--border);
    }

    .summary-item span {
      display: block;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      margin-bottom: 6px;
    }

    .summary-item strong { display: block; font-size: 1rem; line-height: 1.4; }
    .notice {
      padding: 14px 16px;
      border-radius: 18px;
      border: 1px solid var(--border);
      background: color-mix(in srgb, var(--accent) 10%, var(--panel-strong));
      color: var(--text);
      line-height: 1.6;
    }

    .notice.is-warning {
      background: color-mix(in srgb, #f59e0b 16%, var(--panel-strong));
    }

    .notice.is-error {
      background: color-mix(in srgb, var(--danger) 12%, var(--panel-strong));
    }

    .results-list {
      display: grid;
      gap: 10px;
      margin-top: 12px;
    }

    .result-card {
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 14px;
      background: var(--panel-strong);
    }

    .result-card label { display: flex; gap: 12px; align-items: flex-start; cursor: pointer; }
    .result-card strong { display: block; margin-bottom: 4px; }
    .result-card small { display: block; color: var(--muted); line-height: 1.5; }

    .modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(10, 15, 25, 0.52);
      padding: 18px;
      z-index: 50;
    }
    .modal.is-open { display: flex; }
    .modal-card {
      width: min(720px, 100%);
      border: 1px solid var(--border);
      border-radius: 24px;
      background: var(--panel-strong);
      box-shadow: var(--shadow);
      padding: 20px;
    }
    .modal-card h3 { margin: 0 0 10px; }
    .modal-card p { margin: 0; color: var(--muted); line-height: 1.6; }
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; margin-top: 18px; }

    .toast {
      position: fixed;
      left: 18px;
      bottom: 18px;
      max-width: min(420px, calc(100vw - 36px));
      background: var(--panel-strong);
      border: 1px solid var(--border);
      border-radius: 18px;
      box-shadow: var(--shadow);
      padding: 14px 16px;
      display: none;
      z-index: 1660;
    }
    .toast.is-visible { display: block; animation: fadeUp 0.2s ease; }
    .toast strong { display: block; margin-bottom: 4px; }
    .toast p { margin: 0; color: var(--muted); line-height: 1.5; }

    .hidden { display: none !important; }
    
    @media (max-width: 980px) {
    .calendar-layout,
    .months,
    .grid-2,
    .summary-grid,
    .customer-grid {
      grid-template-columns: 1fr;
    }
    .slots-card { position: static; }
    .stepper { grid-template-columns: 1fr 1fr; } 
  }

  @media (max-width: 640px) {
    .booking-shell { width: min(100% - 20px, 100%); padding: 14px 0 28px; }
    .hero, .panel { border-radius: 22px; padding: 18px; }
    .panel-head { align-items: flex-start; flex-direction: column; }
    .weekday-row, .day-grid { gap: 6px; }
    .day-cell { min-height: 40px; }
    .day-badge { width: 36px; height: 36px; }
  }

  @media (max-width: 480px) {
    .stepper button span { display: none; }
    .stepper button { padding: 12px 14px; }
    .modal-actions { flex-direction: column; }
    .modal-actions .btn { width: 100%; }
  }

  @media (max-width: 320px) {
    .stepper { grid-template-columns: 1fr; }
  }
/* Ensure the action container behaves reliably across browser configurations */
.actions {
    /* Kept for modern engines, fallback handled via fixed viewport indexing below */
    anchor-name: --actions; 
}

.floating-actions {
    position: fixed;
    right: 24px;
    bottom: 24px;

    /* Changed from display: none to force initialization state management via JS classes */
    display: flex; 
    gap: 12px;
    padding: 12px 18px;

    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 999px;
    box-shadow: var(--shadow);

    z-index: 1000;
    transition: opacity 0.2s ease, transform 0.2s ease;
}

/* Force standard utilities to override responsive layouts cleanly */
.floating-actions.hidden {
    display: none !important;
    opacity: 0;
    pointer-events: none;
}

/* Mobile responsive style overrides */
@media (max-width: 480px) {
    .floating-actions {
        left: 0;
        right: 0;
        bottom: 0;
        justify-content: space-between;
        border-radius: 0;
        border-width: 1px 0 0;
        padding: 16px 24px;
    }
}
  </style>
</head>
<body>
  <main class="booking-shell">
    <section class="hero">
      <div class="eyebrow"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> booking</div>
      <h1>Book a visit in four simple steps.</h1>
      <p>Choose a service and provider, pick a time, enter your details and confirm the booking.</p>
    </section>

    <div class="stepper" aria-label="Booking progress">
      <button type="button" class="is-active" data-stepper="1"><strong>1. Service</strong><span>Choose the appointment type and provider.</span></button>
      <button type="button" data-stepper="2"><strong>2. Time</strong><span>Pick a date and available slot.</span></button>
      <button type="button" data-stepper="3"><strong>3. Customer</strong><span>New customer or existing customer lookup.</span></button>
      <button type="button" data-stepper="4"><strong>4. Summary</strong><span>Review the booking and confirm.</span></button>
    </div>

    <section class="panel is-active" data-panel="1">
      <div class="panel-head">
        <div>
          <h2>Appointment details</h2>
          <p>Select the appointment type and provider first.</p>
        </div>
      </div>

      <?php if ($disableOnlineBooking === 1): ?>
        <div class="notice is-error" style="margin-bottom: 0px;">
          🚨 <strong>Online Bookings Temporarily Offline:</strong> Public scheduling via our website has been paused. Please contact our front desk operations staff directly to verify current space options and arrange calendar slots manually.
        </div>
        <script>
          document.addEventListener('DOMContentLoaded', () => {
            const stepGrid = document.getElementById('step1SelectionGrid');
            if (stepGrid) stepGrid.classList.add('hidden');
            
            if (els.step1Continue) {
              els.step1Continue.disabled = true;
              els.step1Continue.classList.add('hidden');
            }
            
            els.stepButtons.forEach(btn => { btn.disabled = true; btn.style.cursor = 'not-allowed'; });
            setToast('Booking Unavailable', 'Online registrations are currently deactivated.');
          });
        </script>
      <?php else: ?>
        <div id="step1RestrictionNotice" class="notice is-error hidden" style="margin-bottom: 20px;"></div>
      <?php endif; ?>

      <div class="grid-2" id="step1SelectionGrid"> 
        <div class="field">
          <label for="appointmentTypeSelect">Appointment type</label>
          <select id="appointmentTypeSelect"></select>
        </div>
        <div class="field">
          <label for="providerSelect">Provider</label>
          <select id="providerSelect"></select>
        </div>
      </div>
      <div class="actions">
        <button type="button" class="btn primary" id="step1Continue">Continue to calendar</button>
      </div>
    </section>

    <section class="panel" data-panel="2">
      <div class="panel-head">
        <div>
          <h2>Choose a date and time</h2>
          <p>The calendar shows the current month and next month only. Days with no available slots are greyed out. Current day and selected day use different highlight rings.</p>
        </div>
      </div>
      <div class="calendar-layout">
        <div class="months" id="monthsContainer"></div>
        <aside class="slots-card">
          <h3 id="selectedDateLabel">Select a day</h3>
          <p id="selectedDateMeta">Available times will appear here after you select a calendar day.</p>
          <div id="slotList" class="slot-list"></div>
          <div class="actions" style="justify-content: stretch; margin-top: 18px;">
            <button type="button" class="btn ghost" id="step2Back">Back</button>
            <button type="button" class="btn primary" id="step2Continue" disabled>Continue to customer details</button>
          </div>
        </aside>
      </div>
    </section>

    <section class="panel" data-panel="3">
      <div class="panel-head">
        <div>
          <h2>Customer information</h2>
          <p>New customers must complete the displayed mandatory fields. Existing customers only need email and mobile so we can locate the matching profile.</p>
        </div>
      </div>
      <div class="tabs" role="tablist" aria-label="Customer mode">
        <button type="button" id="newCustomerTab" class="is-active" role="tab" aria-selected="true">New customer</button>
        <button type="button" id="existingCustomerTab" role="tab" aria-selected="false">Existing customer</button>
      </div>

      <div id="customerMessage" class="notice hidden"></div>

      <div id="newCustomerPane" class="customer-pane is-active">
        <div class="customer-grid">
          <div class="field">
            <label for="newFirstName">First name</label>
            <input id="newFirstName" type="text" autocomplete="given-name">
          </div>
          <div class="field">
            <label for="newMiddleName">Middle name</label>
            <input id="newMiddleName" type="text" autocomplete="additional-name">
          </div>
          <div class="field">
            <label for="newLastName">Last name</label>
            <input id="newLastName" type="text" autocomplete="family-name">
          </div>
          <div class="field">
            <label for="newEmail">Email</label>
            <input id="newEmail" type="email" autocomplete="email">
          </div>
          <div class="field">
            <label for="newMobile">Mobile</label>
            <input id="newMobile" type="text" autocomplete="tel">
          </div>
          <div class="field">
            <label for="newDob">Date of birth</label>
            <input id="newDob" type="date" autocomplete="bday">
          </div>
          <div class="field span-3">
            <label for="newGender">Gender</label>
            <select id="newGender">
              <option value="">Select gender</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
              <option value="unknown">Unknown</option>
            </select>
          </div>
        </div>
      </div>

      <div id="existingCustomerPane" class="customer-pane">
        <div class="customer-grid">
          <div class="field span-2">
            <label for="existingEmail">Email</label>
            <input id="existingEmail" type="email" autocomplete="email">
          </div>
          <div class="field span-2">
            <label for="existingMobile">Mobile</label>
            <input id="existingMobile" type="text" autocomplete="tel">
          </div>
        </div>
        <div id="existingMatches" class="results-list hidden"></div>
      </div>

      <div class="actions">
        <button type="button" class="btn ghost" id="step3Back">Back</button>
        <button type="button" class="btn primary" id="step3Continue">Continue to summary</button>
      </div>
    </section>

    <section class="panel" data-panel="4">
      <div class="panel-head">
        <div>
          <h2>Summary</h2>
          <p>Review everything before we create the appointment or send the verification email.</p>
        </div>
      </div>
      <div class="summary-card">
        <div id="summaryNotice" class="notice hidden"></div>
        <div class="summary-grid" id="summaryGrid"></div>
        <div id="summaryCustomerSection"></div>
        <div class="actions">
          <button type="button" class="btn ghost" id="step4Back">Back</button>
          <button type="button" class="btn primary" id="confirmBookingBtn">Confirm booking</button>
        </div>
      </div>
    </section>

    <section class="panel" data-panel="5">
      <div class="summary-card" id="resultCard"></div>
    </section>
  </main>

<div id="duplicateModal" class="modal" aria-hidden="true">
  <div class="modal-card">
    <h3>This email and mobile combination already exists</h3>
    <p id="duplicateModalText">Choose whether to continue, cancel, or switch to the existing customer profile.</p>
    
    <div class="modal-actions">
      <button type="button" class="btn ghost" id="duplicateCancelBtn">Cancel</button>
      <button type="button" class="btn" id="duplicateUseExistingBtn">Use existing profile</button>
      <button type="button" class="btn primary" id="duplicateContinueBtn">Continue anyway</button>
    </div>
  </div>
</div>

  <div id="toast" class="toast" role="status" aria-live="polite"></div>

  <script>
    const BOOMY = {
      csrfToken: <?php echo json_encode($csrfToken); ?>,
      initUrl: 'booking_api.php?action=init',
      monthUrl: 'booking_api.php?action=month',
      dayUrl: 'booking_api.php?action=day_slots',
      customerCheckUrl: 'booking_api.php?action=customer_check',
      createUrl: 'booking_api.php?action=create',
      requestUrl: 'booking_api.php?action=request',
      verifyUrl: 'booking_api.php?action=verify'
    };

    const state = {
      step: 1,
      maxStep: 1,
      appointmentTypes: [],
      providers: [],
      settings: null,
      currentMonth: null,
      nextMonth: null,
      monthViews: [],
      appointmentTypeId: '',
      providerId: '',
      selectedDate: '',
      selectedSlot: '',
      customerMode: 'new',
      customerCheck: null,
      allowDuplicate: false,
      bookingResult: null,
      loading: false,
      requestToken: '',
      duplicateMatches: [],
      prevent_same_user_booking: 0 
    };

    const els = {
      stepButtons: Array.from(document.querySelectorAll('[data-stepper]')),
      panels: Array.from(document.querySelectorAll('[data-panel]')),
      appointmentTypeSelect: document.getElementById('appointmentTypeSelect'),
      providerSelect: document.getElementById('providerSelect'),
      step1Continue: document.getElementById('step1Continue'),
      monthsContainer: document.getElementById('monthsContainer'),
      slotList: document.getElementById('slotList'),
      selectedDateLabel: document.getElementById('selectedDateLabel'),
      selectedDateMeta: document.getElementById('selectedDateMeta'),
      step2Back: document.getElementById('step2Back'),
      step2Continue: document.getElementById('step2Continue'),
      newCustomerTab: document.getElementById('newCustomerTab'),
      existingCustomerTab: document.getElementById('existingCustomerTab'),
      newCustomerPane: document.getElementById('newCustomerPane'),
      existingCustomerPane: document.getElementById('existingCustomerPane'),
      customerMessage: document.getElementById('customerMessage'),
      newFirstName: document.getElementById('newFirstName'),
      newMiddleName: document.getElementById('newMiddleName'),
      newLastName: document.getElementById('newLastName'),
      newEmail: document.getElementById('newEmail'),
      newMobile: document.getElementById('newMobile'),
      newDob: document.getElementById('newDob'),
      newGender: document.getElementById('newGender'),
      existingEmail: document.getElementById('existingEmail'),
      existingMobile: document.getElementById('existingMobile'),
      existingMatches: document.getElementById('existingMatches'),
      step3Back: document.getElementById('step3Back'),
      step3Continue: document.getElementById('step3Continue'),
      summaryNotice: document.getElementById('summaryNotice'),
      summaryGrid: document.getElementById('summaryGrid'),
      summaryCustomerSection: document.getElementById('summaryCustomerSection'),
      step4Back: document.getElementById('step4Back'),
      confirmBookingBtn: document.getElementById('confirmBookingBtn'),
      resultCard: document.getElementById('resultCard'),
      duplicateModal: document.getElementById('duplicateModal'),
      duplicateModalText: document.getElementById('duplicateModalText'),
      duplicateMatchList: document.getElementById('duplicateMatchList'),
      duplicateCancelBtn: document.getElementById('duplicateCancelBtn'),
      duplicateUseExistingBtn: document.getElementById('duplicateUseExistingBtn'),
      duplicateContinueBtn: document.getElementById('duplicateContinueBtn'),
      toast: document.getElementById('toast'),
      floatingActions: document.getElementById('floating-actions'),
      floatingBack: document.getElementById('floating-back'),
      floatingNext: document.getElementById('floating-next')
    };

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"]/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;'
  }[char] || char)); 
}

    function setToast(title, message) {
      els.toast.innerHTML = '<strong>' + escapeHtml(title) + '</strong><p>' + escapeHtml(message) + '</p>';
      els.toast.classList.add('is-visible');
      clearTimeout(setToast._timer);
      setToast._timer = setTimeout(() => els.toast.classList.remove('is-visible'), 4200);
    }

    function setMessage(text, type = 'info') {
      if (!text) {
        els.customerMessage.classList.add('hidden');
        els.customerMessage.textContent = '';
        return;
      }

      els.customerMessage.classList.remove('hidden', 'is-warning', 'is-error');
      if (type === 'warning') {
        els.customerMessage.classList.add('is-warning');
      } else if (type === 'error') {
        els.customerMessage.classList.add('is-error');
      }
      els.customerMessage.textContent = text;
    }

    function setStep(step) {
      state.step = step;
      if (step > state.maxStep && step <= 4) state.maxStep = step;
      els.stepButtons.forEach((btn) => {
        const target = Number(btn.dataset.stepper);
        btn.classList.toggle('is-active', target === step);
        btn.classList.toggle('is-reachable', target <= state.maxStep);
      });
      els.panels.forEach((panel) => {
        panel.classList.toggle('is-active', Number(panel.dataset.panel) === step);
      });
      if (step === 4) {
        renderSummary();
      }
      watchActions(step);

      // LIVE VALIDATION TRIGGER FOR STEP 3
      if (step === 3 && typeof checkStep3Readiness === 'function') {
        checkStep3Readiness();
      } else {
        if (els.step3Continue) els.step3Continue.disabled = false;
        if (els.floatingNext) els.floatingNext.disabled = false;
      }
    }
    function monthStart(date) {
      return new Date(date.getFullYear(), date.getMonth(), 1);
    }

    function addMonths(date, amount) {
      return new Date(date.getFullYear(), date.getMonth() + amount, 1);
    }

    function formatDateLong(dateString) {
      return new Intl.DateTimeFormat(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(dateString + 'T00:00:00'));
    }

    function formatDateShort(dateString) {
      return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(dateString + 'T00:00:00'));
    }

    function formatTimeLabel(value) {
      const date = new Date(value.replace(' ', 'T'));
      if (Number.isNaN(date.getTime())) {
        return value;
      }
      return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(date);
    }

function validateEmail(email) {
  if (!email || email.length > 100) return false;
  const parts = email.split('@');
  if (parts.length !== 2) return false;
  const [username, domain] = parts;
  if (!username || !domain) return false;
  if (/\s/.test(username)) return false;
  if (!domain.includes('.')) return false;
  if (!/^[a-zA-Z0-9.\-]+$/.test(domain)) return false;
  return true;
}

    function combineCustomerName() {
      return [els.newFirstName.value.trim(), els.newMiddleName.value.trim(), els.newLastName.value.trim()].filter(Boolean).join(' ');
    }

    function currentCustomerPayload() {
      if (state.customerMode === 'existing') {
        return {
          customer_mode: 'existing',
          customer_email: els.existingEmail.value.trim(),
          customer_mobile: els.existingMobile.value.trim()
        };
      }

      return {
        customer_mode: 'new',
        customer_first_name: els.newFirstName.value.trim(),
        customer_middle_name: els.newMiddleName.value.trim(),
        customer_last_name: els.newLastName.value.trim(),
        customer_name: combineCustomerName(),
        customer_email: els.newEmail.value.trim(),
        customer_mobile: els.newMobile.value.trim(),
        customer_date_of_birth: els.newDob.value.trim(),
        customer_gender: els.newGender.value.trim()
      };
    }

    async function fetchJson(url, options = {}) {
      const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
          'Content-Type': 'application/json',
          ...(options.headers || {})
        }
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        const error = new Error(data.error || data.message || 'Request failed');
        error.response = response;
        error.data = data;
        throw error;
      }
      return data;
    }

    async function fetchInit() {
      const data = await fetchJson(BOOMY.initUrl, { method: 'GET' });
      state.appointmentTypes = data.appointment_types || [];
      state.providers = data.providers || [];
      state.settings = data.settings || {};
      
      if (data.csrf_token) {
        BOOMY.csrfToken = data.csrf_token;
      }
      
      state.prevent_same_user_booking = parseInt(data.prevent_same_user_booking ?? 0, 10);

      state.settings.customer_field_settings = data.customer_field_settings || {};
      fillSelects();
      state.appointmentTypeId = String(state.appointmentTypes[0]?.id || '');
      state.providerId = String(state.providers[0]?.id || '');
      els.appointmentTypeSelect.value = state.appointmentTypeId;
      els.providerSelect.value = state.providerId;
      state.currentMonth = data.month_start;
      state.nextMonth = data.next_month_start;
    }

    function fillSelects() {
      els.appointmentTypeSelect.innerHTML = state.appointmentTypes.map((type) => {
        return '<option value="' + escapeHtml(type.id) + '">' + escapeHtml(type.name) + ' (' + escapeHtml(type.duration_minutes) + ' mins)</option>';
      }).join('');

      els.providerSelect.innerHTML = state.providers.map((provider) => {
        const label = provider.full_name || provider.email;
        return '<option value="' + escapeHtml(provider.id) + '">' + escapeHtml(label) + '</option>';
      }).join('');
    }

    async function loadMonths() {
      const providerId = state.providerId;
      const appointmentTypeId = state.appointmentTypeId;
      if (!providerId || !appointmentTypeId) {
        return;
      }

      els.monthsContainer.innerHTML = '<div class="month-card"><div class="notice">Loading calendar...</div></div><div class="month-card"><div class="notice">Loading calendar...</div></div>';
      const monthUrls = [state.currentMonth, state.nextMonth].map((month) => {
        const url = new URL(BOOMY.monthUrl, window.location.href);
        url.searchParams.set('provider_id', providerId);
        url.searchParams.set('appointment_type_id', appointmentTypeId);
        url.searchParams.set('month', month.slice(0, 7));
        return url.toString();
      });

      const months = await Promise.all(monthUrls.map((url) => fetchJson(url, { method: 'GET' })));
      state.monthViews = months
        .map((item) => item.month)
        .filter((month) => month !== null && month !== undefined);
      renderMonths();
      if (state.selectedDate) {
        await loadSlots(state.selectedDate);
      }
    }

    function renderMonths() {
      els.monthsContainer.innerHTML = state.monthViews.map((month) => renderMonth(month)).join('');
      els.monthsContainer.querySelectorAll('[data-date]').forEach((button) => {
        button.addEventListener('click', () => selectDate(button.dataset.date));
      });
    }

    function renderMonth(month) {
      const weekdayRow = (month.weekday_labels || []).map((label) => '<span>' + escapeHtml(label) + '</span>').join('');
      const todayKey = new Date().toISOString().slice(0, 10);
      const days = (month.days || []).map((day) => {
        if (!day.in_month) {
          return '<div class="day-cell is-empty" aria-hidden="true"></div>';
        }

        const classes = ['day-cell'];
        if (!day.has_slots) classes.push('is-disabled');
        if (day.has_slots) classes.push('is-available');
        if (day.date === todayKey) classes.push('is-today');
        if (day.date === state.selectedDate) classes.push('is-selected');

        const mark = day.has_slots ? '<span class="day-mark" aria-hidden="true"></span>' : '';
        const badge = '<span class="day-badge">' + escapeHtml(day.day) + '</span>';
        return '<button type="button" class="' + classes.join(' ') + '" data-date="' + escapeHtml(day.date) + '" ' + (!day.has_slots ? 'disabled' : '') + ' aria-label="' + escapeHtml(day.date) + '">' + badge + mark + '</button>';
      }).join('');

      return '<article class="month-card"><div class="month-head"><h3>' + escapeHtml(month.label) + '</h3><small>' + escapeHtml(month.month) + '</small></div><div class="weekday-row">' + weekdayRow + '</div><div class="day-grid">' + days + '</div></article>';
    }

    async function loadSlots(date) {
      const url = new URL(BOOMY.dayUrl, window.location.href);
      url.searchParams.set('provider_id', state.providerId);
      url.searchParams.set('appointment_type_id', state.appointmentTypeId);
      url.searchParams.set('date', date);
      const data = await fetchJson(url.toString(), { method: 'GET' });
      renderSlots(data);
    }

    function renderSlots(data) {
      els.selectedDateLabel.textContent = data.label || 'Select a day';
      els.selectedDateMeta.textContent = data.slot_count > 0
        ? data.slot_count + ' available time slot' + (data.slot_count === 1 ? '' : 's') + ' found.'
        : 'No available time slots on this day.';

      if (!data.slot_count) {
        els.slotList.innerHTML = '<div class="notice is-warning">No available times on this day.</div>';
        els.step2Continue.disabled = true;
        return;
      }

      els.slotList.innerHTML = (data.slots || []).map((slot) => {
        const selected = slot.value === state.selectedSlot ? ' is-selected' : '';
        return '<button type="button" class="slot-btn' + selected + '" data-slot="' + escapeHtml(slot.value) + '">' + escapeHtml(slot.label) + '<span class="slot-meta">' + escapeHtml(formatDateShort(slot.start.slice(0, 10))) + ' • ' + escapeHtml(formatTimeLabel(slot.start)) + ' to ' + escapeHtml(formatTimeLabel(slot.end)) + '</span></button>';
      }).join('');

      els.slotList.querySelectorAll('[data-slot]').forEach((button) => {
        button.addEventListener('click', () => selectSlot(button.dataset.slot));
      });
      els.step2Continue.disabled = !state.selectedSlot;
    }

    async function selectDate(date) {
      state.selectedDate = date;
      state.selectedSlot = '';
      await loadSlots(date);
      renderMonths();
    }

    function selectSlot(slot) {
      state.selectedSlot = slot;
      const buttons = els.slotList.querySelectorAll('[data-slot]');
      buttons.forEach((button) => button.classList.toggle('is-selected', button.dataset.slot === slot));
      els.step2Continue.disabled = false;
    }

    function switchCustomerMode(mode) {
      state.customerMode = mode;
      els.newCustomerTab.classList.toggle('is-active', mode === 'new');
      els.existingCustomerTab.classList.toggle('is-active', mode === 'existing');
      els.newCustomerTab.setAttribute('aria-selected', mode === 'new' ? 'true' : 'false');
      els.existingCustomerTab.setAttribute('aria-selected', mode === 'existing' ? 'true' : 'false');
      els.newCustomerPane.classList.toggle('is-active', mode === 'new');
      els.existingCustomerPane.classList.toggle('is-active', mode === 'existing');
      setMessage('');
      els.existingMatches.classList.add('hidden');
    }

    function renderDuplicateModal(matches, mode) {
      const message = mode === 'new'
        ? 'A customer profile already exists for this email and mobile combination. You can continue to create a pending booking request, switch to the existing profile, or cancel.'
        : 'Multiple customer profiles share this email and mobile combination. The booking will need to be verified before it can be completed.';
      
      els.duplicateModalText.textContent = message;
      els.duplicateModal.classList.add('is-open');
      els.duplicateModal.setAttribute('aria-hidden', 'false');
    }

    function closeDuplicateModal() {
      els.duplicateModal.classList.remove('is-open');
      els.duplicateModal.setAttribute('aria-hidden', 'true');
    }

    async function validateCustomerAndAdvance() {
      const payload = currentCustomerPayload();
      const response = await fetchJson(BOOMY.customerCheckUrl, {
        method: 'POST',
        body: JSON.stringify({
          csrf_token: BOOMY.csrfToken,
          ...payload
        })
      });

      if (state.customerMode === 'existing') {
        state.customerCheck = response;
        state.allowDuplicate = false;
        state.duplicateMatches = response.matches || [];
        if (response.requires_verification) {
          setMessage('Multiple customer profiles match this contact. We will create a verification request instead of creating the appointment immediately.', 'warning');
        } else {
          setMessage('Customer found. Continue to review the appointment summary.');
        }
        if (response.selected_customer) {
          state.customerCheck.selected_customer = response.selected_customer;
        }
        setStep(4);
        return;
      }

      state.customerCheck = response;
      state.duplicateMatches = response.matches || [];
      if (response.duplicate_contact) {
        renderDuplicateModal(response.matches || [], 'new');
        return;
      }

      state.allowDuplicate = false;
      setMessage('Customer profile looks good. Continue to review the appointment summary.');
      setStep(4);
    }

    function fillExistingFromNew() {
      els.existingEmail.value = els.newEmail.value.trim();
      els.existingMobile.value = els.newMobile.value.trim();
      switchCustomerMode('existing');
      setMessage('Switched to existing customer lookup using the email and mobile you entered.', 'warning');
    }

    function validateStep1() {
      if (!els.appointmentTypeSelect.value || !els.providerSelect.value) {
        setToast('Missing selection', 'Please choose an appointment type and a provider first.');
        return false;
      }
      state.appointmentTypeId = els.appointmentTypeSelect.value;
      state.providerId = els.providerSelect.value;
      return true;
    }

    function validateStep2() {
      if (!state.selectedDate || !state.selectedSlot) {
        setToast('Pick a time', 'Choose a day and an available time slot before continuing.');
        return false;
      }
      return true;
    }

    function validateCustomerInputs() {
      const fieldSettings = state.settings?.customer_field_settings || {};

      if (state.customerMode === 'existing') {
        if (!els.existingEmail.value.trim() || !els.existingMobile.value.trim()) {
          setToast('Missing customer details', 'Email and mobile are required for an existing customer lookup.');
          return false;
        }
        return true;
      }

      const checks = [
        { el: els.newFirstName, key: 'first_name',     label: 'First name' },
        { el: els.newLastName,  key: 'last_name',      label: 'Last name' },
        { el: els.newEmail,     key: 'email',          label: 'Email' },
        { el: els.newMobile,    key: 'mobile',         label: 'Mobile' },
        { el: els.newDob,       key: 'date_of_birth',  label: 'Date of birth' },
        { el: els.newGender,    key: 'gender',         label: 'Gender' },
      ];

      for (const { el, key, label } of checks) {
        const cfg = fieldSettings[key] || {};
        if (cfg.required && !el.value.trim()) {
          setToast('Missing required field', `${label} is required.`);
          el.focus();
          return false;
        }
      }

      const emailToCheck = state.customerMode === 'existing'
        ? els.existingEmail.value.trim()
        : els.newEmail.value.trim();

      if (emailToCheck && !validateEmail(emailToCheck)) {
        setToast('Invalid email', 'Email must be lowercase, max 100 characters, and contain a valid domain.');
        (state.customerMode === 'existing' ? els.existingEmail : els.newEmail).focus();
        return false;
      }
      if (!els.newEmail.value.trim() || !els.newMobile.value.trim()) {
        setToast('Missing customer details', 'Email and mobile are always needed to check for duplicates.');
        return false;
      }

      return true;
    }

    function renderSummary() {
      const appointmentType = state.appointmentTypes.find((item) => String(item.id) === String(state.appointmentTypeId));
      const provider = state.providers.find((item) => String(item.id) === String(state.providerId));
      const selectedDateText = state.selectedDate ? formatDateLong(state.selectedDate) : '-';
      const selectedTimeText = state.selectedSlot ? formatTimeLabel(state.selectedSlot) : '-';
      const fieldSettings = state.settings?.customer_field_settings || {};
      const customerInfo = currentCustomerPayload();

      els.summaryGrid.innerHTML = [
        ['Appointment type', appointmentType ? appointmentType.name : '-'],
        ['Provider', provider ? (provider.full_name || provider.email || '-') : '-'],
        ['Date', selectedDateText],
        ['Time', selectedTimeText]
      ].map((row) => {
        return '<div class="summary-item"><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong></div>';
      }).join('');

      if (state.customerMode === 'existing' && state.customerCheck && state.customerCheck.requires_verification) {
        els.summaryCustomerSection.innerHTML = '<div class="notice is-warning">Confirmation of customer required. Multiple profiles match this contact, so the appointment will be held for verification instead of being created immediately.</div>';
        els.summaryNotice.textContent = 'This booking will create a verification request and send a link to the customer email. No appointment will be created until the customer is selected on the verification page.';
        els.summaryNotice.classList.remove('hidden', 'is-error');
        els.summaryNotice.classList.add('is-warning');
        els.confirmBookingBtn.textContent = 'Send verification email';
        return;
      }

      if (state.allowDuplicate && state.customerMode === 'new') {
        els.summaryCustomerSection.innerHTML = '<div class="notice is-warning">This contact already exists. Continuing will create a pending booking request and send a verification link to the customer email.</div>';
        els.summaryNotice.textContent = 'The booking will not be created immediately. The customer must confirm their profile first.';
        els.summaryNotice.classList.remove('hidden', 'is-error');
        els.summaryNotice.classList.add('is-warning');
        els.confirmBookingBtn.textContent = 'Send verification email';
        return;
      }

      els.summaryNotice.classList.add('hidden');
      const customerLines = state.customerMode === 'existing'
        ? [
            ['Customer', state.customerCheck?.selected_customer?.full_name || 'Selected customer'],
            ['Email', els.existingEmail.value.trim()],
            ['Mobile', els.existingMobile.value.trim()]
          ]
        : [
            ['Customer', customerInfo.customer_name],
            (fieldSettings.email?.display !== false)        ? ['Email', customerInfo.customer_email]         : null,
            (fieldSettings.mobile?.display !== false)       ? ['Mobile', customerInfo.customer_mobile]        : null,
            (fieldSettings.date_of_birth?.display !== false)? ['Date of birth', customerInfo.customer_date_of_birth || '-'] : null,
            (fieldSettings.gender?.display !== false)       ? ['Gender', customerInfo.customer_gender || '-'] : null,
          ].filter(Boolean);

      els.summaryCustomerSection.innerHTML = '<div class="summary-grid">' + customerLines.map((row) => {
        return '<div class="summary-item"><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1] || '-') + '</strong></div>';
      }).join('') + '</div>';
      els.confirmBookingBtn.textContent = 'Confirm booking';
    }

    function renderSuccess(result) {
      const appointment = result.appointment || {};
      const customer = result.customer || {};
      const pending = Boolean(result.pending);
      const appointmentDate = String(appointment.start_time || state.selectedDate || '').slice(0, 10);
      const appointmentTime = appointment.start_time ? formatTimeLabel(String(appointment.start_time)) : (state.selectedSlot ? formatTimeLabel(String(state.selectedSlot)) : '-');

      const title = pending ? 'Verification request sent' : 'Booking confirmed';
      const body = pending
        ? 'We sent a verification link to the customer email address. The appointment will be created only after the customer profile is confirmed.'
        : 'The appointment has been created successfully.';

      els.resultCard.innerHTML = [
        '<div class="notice ' + (pending ? 'is-warning' : '') + '"><strong>' + escapeHtml(title) + '</strong><br>' + escapeHtml(body) + '</div>',
        '<div class="summary-grid">',
        '<div class="summary-item"><span>Customer</span><strong>' + escapeHtml(customer.full_name || 'Pending verification') + '</strong></div>',
        '<div class="summary-item"><span>Start time</span><strong>' + escapeHtml((appointmentDate ? formatDateShort(appointmentDate) : '-')) + ' ' + escapeHtml(appointmentTime) + '</strong></div>',
        '<div class="summary-item"><span>Provider</span><strong>' + escapeHtml((state.providers.find((item) => String(item.id) === String(state.providerId)) || {}).full_name || '-') + '</strong></div>',
        '<div class="summary-item"><span>Status</span><strong>' + escapeHtml(pending ? 'Pending verification' : (appointment.status || 'booked')) + '</strong></div>',
        '</div>',
        pending ? '<div class="actions"><button class="btn primary" disabled style="cursor: not-allowed; opacity: 0.65;">Check your email</button></div>' : '<div class="actions"><button type="button" class="btn primary" id="startOverBtn">Book another appointment</button></div>'
      ].join('');
      setStep(5);
      if (!pending) {
        const btn = document.getElementById('startOverBtn');
        if (btn) {
          btn.addEventListener('click', resetBooking);
        }
      }
    }

    function collectClientMeta() {
      return {
        client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
        client_screen:   (screen.width || 0) + 'x' + (screen.height || 0),
        client_locale:   navigator.language || ''
      };
    }

    async function createBooking() {
      const payload = {
        csrf_token: BOOMY.csrfToken,
        provider_id: state.providerId,
        appointment_type_id: state.appointmentTypeId,
        start_time: state.selectedSlot,
        customer_mode: state.customerMode,
        allow_duplicate: state.allowDuplicate,
        notes: '',
        ...currentCustomerPayload(),
        ...collectClientMeta()
      };

      if (state.customerMode === 'new') {
        payload.customer_name = combineCustomerName();
      }

      const response = await fetchJson(BOOMY.createUrl, {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      return response;
    }

    async function handleStep3Continue() {
      if (!validateCustomerInputs()) {
        return;
      }

      try {
        const payload = currentCustomerPayload();
        const response = await fetchJson(BOOMY.customerCheckUrl, {
          method: 'POST',
          body: JSON.stringify({
            csrf_token: BOOMY.csrfToken,
            ...payload
          })
        });

        state.customerCheck = response;
        state.duplicateMatches = response.matches || [];

        if (state.customerMode === 'new' && response.duplicate_contact) {
          renderDuplicateModal(response.matches || [], 'new');
          return;
        }

        if (state.customerMode === 'existing') {
          if (!response.match_count) {
            setToast('No customer found', 'No customer matched the email and mobile combination you entered.');
            return;
          }
          if (response.requires_verification) {
            setMessage('Multiple customer profiles were found. The booking will be sent for verification first.', 'warning');
          } else {
            setMessage('Customer found. Continue to the summary.', 'info');
          }
        } else {
          state.allowDuplicate = false;
          setMessage('Customer profile is ready. Continue to the summary.', 'info');
        }

        setStep(4);
      } catch (error) {
        setToast('Customer lookup failed', error.message || 'Unable to check customer details.');
      }
    }

    async function confirmBooking() {
      els.confirmBookingBtn.disabled = true;
      els.confirmBookingBtn.textContent = 'Processing...';

      try {
        const response = await createBooking();
        state.bookingResult = response;
        renderSuccess(response);
      } catch (error) {
        if (error.response && error.response.status === 409 && error.data && error.data.duplicate_contact) {
          renderDuplicateModal(error.data.matches || [], 'new');
          setStep(3);
          setMessage('This contact already exists. Choose whether to continue, cancel, or use the existing profile.', 'warning');
        } else {
          setToast('Booking failed', error.message || 'Unable to complete the booking right now.');
        }
      } finally {
        els.confirmBookingBtn.disabled = false;
        els.confirmBookingBtn.textContent = 'Confirm booking';
      }
    }

    function resetBooking() {
      state.step = 1;
      state.maxStep = 1;
      state.selectedDate = '';
      state.selectedSlot = '';
      state.customerCheck = null;
      state.allowDuplicate = false;
      state.bookingResult = null;
      state.duplicateMatches = [];
      els.newFirstName.value = '';
      els.newMiddleName.value = '';
      els.newLastName.value = '';
      els.newEmail.value = '';
      els.newMobile.value = '';
      els.newDob.value = '';
      els.newGender.value = '';
      els.existingEmail.value = '';
      els.existingMobile.value = '';
      setMessage('');
      els.existingMatches.classList.add('hidden');
      els.slotList.innerHTML = '';
      els.selectedDateLabel.textContent = 'Select a day';
      els.selectedDateMeta.textContent = 'Available times will appear here after you select a calendar day.';
      els.step2Continue.disabled = true;
      els.resultCard.innerHTML = '';
      setStep(1);
    }

    function applyFieldVisibility(inputEl, fieldKey) {
      const fieldSettings = state.settings?.customer_field_settings || {};
      const cfg = fieldSettings[fieldKey] || {};
      const field = inputEl?.closest('.field');
      if (!field) return;
      if (!cfg.display) {
        field.classList.add('hidden');
        inputEl.disabled = true;
      } else if (cfg.required) {
        inputEl.required = true;
        const label = field.querySelector('label');
        if (label && !label.textContent.includes('*')) {
          label.textContent += ' *';
        }
      }
    }

    async function boot() {
      try {
        await fetchInit();

        console.log("Configured Booking Limit:", state.prevent_same_user_booking);
        
        if (state.prevent_same_user_booking > 0) {
          const clientMeta = collectClientMeta();
          const checkUrl = new URL('booking_api.php?action=check_metadata_limit', window.location.href);
          
          const verifyCheck = await fetchJson(checkUrl.toString(), {
            method: 'POST',
            body: JSON.stringify({
              csrf_token: BOOMY.csrfToken,
              ...clientMeta
            })
          });

          if (verifyCheck.is_blocked) {
            const supportEmail = state.settings?.organization_email ?? 'appointments@example.com';
            const supportPhone = state.settings?.organization_number ?? '';
            let contactLine = `email at <strong>${escapeHtml(supportEmail)}</strong>`;
            if (supportPhone.trim() !== '') {
              contactLine += ` or phone at <strong>${escapeHtml(supportPhone)}</strong>`;
            }

            const noticeEl = document.getElementById('step1RestrictionNotice');
            if (noticeEl) {
               noticeEl.innerHTML = `🚨 <strong>Booking Limit Exceeded:</strong> You have reached the maximum allowed concurrent appointments. Cannot make booking, please contact us via ${contactLine} to make a booking.`;
               noticeEl.classList.remove('hidden');
            }

            const gridEl = document.getElementById('step1SelectionGrid');
            if (gridEl) {
               gridEl.classList.add('hidden');
            }
            
            if (els.step1Continue) {
               els.step1Continue.disabled = true;
               els.step1Continue.classList.add('hidden');
            }
            
            setToast('Booking locked', 'Maximum concurrent appointments threshold crossed.');
            return; 
          }
        }

        applyFieldVisibility(els.newFirstName, 'first_name');
        applyFieldVisibility(els.newMiddleName, 'middle_name');
        applyFieldVisibility(els.newLastName,  'last_name');
        applyFieldVisibility(els.newEmail,     'email');
        applyFieldVisibility(els.newMobile,    'mobile');
        applyFieldVisibility(els.newDob,       'date_of_birth');
        applyFieldVisibility(els.newGender,    'gender');

        els.appointmentTypeSelect.addEventListener('change', () => {
          state.appointmentTypeId = els.appointmentTypeSelect.value;
        });
        els.providerSelect.addEventListener('change', () => {
          state.providerId = els.providerSelect.value;
        });

        els.step1Continue.addEventListener('click', async () => {
          if (!validateStep1()) return;
          setStep(2);
          try {
            await loadMonths();
          } catch (error) {
            setToast('Calendar error', error.message || 'Unable to load availability.');
          }
        });

        els.step2Back.addEventListener('click', () => setStep(1));
        els.step2Continue.addEventListener('click', () => {
          if (validateStep2()) {
            setStep(3);
          }
        });

        els.newCustomerTab.addEventListener('click', () => switchCustomerMode('new'));
        els.existingCustomerTab.addEventListener('click', () => switchCustomerMode('existing'));
        els.step3Back.addEventListener('click', () => setStep(2));
        els.step3Continue.addEventListener('click', handleStep3Continue);
        els.step4Back.addEventListener('click', () => setStep(3));
        els.confirmBookingBtn.addEventListener('click', confirmBooking);

        els.duplicateCancelBtn.addEventListener('click', () => {
          closeDuplicateModal();
          state.allowDuplicate = false;
        });
        els.duplicateUseExistingBtn.addEventListener('click', () => {
          closeDuplicateModal();
          fillExistingFromNew();
        });
        els.duplicateContinueBtn.addEventListener('click', () => {
          closeDuplicateModal();
          state.allowDuplicate = true;
          setMessage('Continuing will keep the duplicate contact and send a verification request.', 'warning');
          setStep(4);
        });
        els.duplicateModal.addEventListener('click', (event) => {
          if (event.target === els.duplicateModal) {
            closeDuplicateModal();
          }
        });

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            closeDuplicateModal();
          }
        });

        els.stepButtons.forEach((btn) => {
          btn.addEventListener('click', () => {
            const target = Number(btn.dataset.stepper);
            if (btn.classList.contains('is-reachable') && target !== state.step) {
              setStep(target);
            }
          });
        });

        // SAFE ALTERNATIVE: Fallback target query selection directly within runtime logic 
        // to fully insulate the layout initialization if global handles fail to grab on boot.
        const btnNext = els.floatingNext || document.getElementById('floating-next');
        const btnBack = els.floatingBack || document.getElementById('floating-back');

        if (btnNext) {
          btnNext.onclick = () => {
            document.querySelector('.panel.is-active .actions .btn.primary')?.click();
          };
        }

        if (btnBack) {
          btnBack.onclick = () => {
            document.querySelector('.panel.is-active .actions .btn.ghost')?.click();
          };
        }
        // --- STEP 3 LIVE VALIDATION ENGINE ---
        const step3PrimaryBtn = els.step3Continue;
        const step3FloatingBtn = els.floatingNext;

        function checkStep3Readiness() {
          const fieldSettings = state.settings?.customer_field_settings || {};
          let isValid = true;

          if (state.customerMode === 'existing') {
            const emailVal = els.existingEmail.value.trim();
            const mobileVal = els.existingMobile.value.trim();
            if (!emailVal || !mobileVal || !validateEmail(emailVal)) {
              isValid = false;
            }
          } else {
            const checks = [
              { el: els.newFirstName, key: 'first_name' },
              { el: els.newLastName, key: 'last_name' },
              { el: els.newEmail, key: 'email' },
              { el: els.newMobile, key: 'mobile' },
              { el: els.newDob, key: 'date_of_birth' },
              { el: els.newGender, key: 'gender' },
            ];

            for (const { el, key } of checks) {
              const cfg = fieldSettings[key] || {};
              const isRequired = cfg.required === true || cfg.required === 1 || cfg.required === '1';
              if (isRequired && !el.value.trim()) {
                isValid = false;
                break;
              }
            }

            const newEmailVal = els.newEmail.value.trim();
            if (newEmailVal && !validateEmail(newEmailVal)) {
              isValid = false;
            }
            if (!els.newEmail.value.trim() || !els.newMobile.value.trim()) {
              isValid = false;
            }
          }

          if (state.step === 3) {
            if (step3PrimaryBtn) step3PrimaryBtn.disabled = !isValid;
            if (step3FloatingBtn) step3FloatingBtn.disabled = !isValid;
          }
        }

        window.checkStep3Readiness = checkStep3Readiness;

        const customerInputs = [
          els.newFirstName, els.newMiddleName, els.newLastName,
          els.newEmail, els.newMobile, els.newDob, els.newGender,
          els.existingEmail, els.existingMobile
        ];

        customerInputs.forEach((input) => {
          if (input) {
            input.addEventListener('input', checkStep3Readiness);
            input.addEventListener('change', checkStep3Readiness);
          }
        });

        els.newCustomerTab.addEventListener('click', () => {
          switchCustomerMode('new');
          checkStep3Readiness();
        });
        els.existingCustomerTab.addEventListener('click', () => {
          switchCustomerMode('existing');
          checkStep3Readiness();
        });

        setToast('Ready', 'Pick an appointment type and provider to begin.');
        // --- END STEP 3 LIVE VALIDATION ENGINE ---

        if (step3FloatingBtn) {
          step3FloatingBtn.onclick = () => {
            if (state.step === 1) els.step1Continue.click();
            else if (state.step === 2) els.step2Continue.click();
            else if (state.step === 3) els.step3Continue.click();
            else if (state.step === 4) els.confirmBookingBtn.click();
          };
        }

        if (els.floatingBack) {
          els.floatingBack.onclick = () => {
            if (state.step === 2) els.step2Back.click();
            else if (state.step === 3) els.step3Back.click();
            else if (state.step === 4) els.step4Back.click();
          };
        }

        setStep(1);

        
      } catch (error) {
        setToast('Initialization failed', error.message || 'Unable to load booking data.');
      }
    }

    let observer;

    function watchActions(step) {
        observer?.disconnect();

        const actions = document.querySelector(
            `.panel[data-panel="${step}"] .actions`
        );

        const floatingContainer = els.floatingActions || document.getElementById('floating-actions');
        if (!actions || !floatingContainer) return;

        observer = new IntersectionObserver(([entry]) => {
            floatingContainer.classList.toggle("hidden", entry.isIntersecting);
        });

        observer.observe(actions);
    }

    // Wait until the elements have definitely parsed before kicking off the script engine.
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  </script>

  <div id="floating-actions" class="floating-actions hidden">
    <button class="btn ghost" id="floating-back">Back</button>
    <button class="btn primary" id="floating-next">Continue</button>
  </div>
</body>
</html>