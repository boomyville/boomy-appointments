<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();

$csrfToken = csrf_token();
$token = trim((string) ($_GET['token'] ?? ''));
$siteName = trim((string) get_setting('site_name', APP_NAME ?? 'Boomy Appointments'));
if ($siteName === '') {
    $siteName = APP_NAME ?? 'Boomy Appointments';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verify Appointment - <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
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
      --shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
      --shadow-soft: 0 12px 32px rgba(15, 23, 42, 0.08);
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
        color-scheme: dark;
      }
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at top left, rgba(36, 87, 255, 0.18), transparent 28%),
        radial-gradient(circle at 85% 18%, rgba(245, 158, 11, 0.14), transparent 24%),
        var(--bg);
      color: var(--text);
    }

    .shell {
      width: min(1100px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 48px;
    }

    .hero,
    .card {
      border: 1px solid var(--border);
      border-radius: 28px;
      background: var(--panel);
      box-shadow: var(--shadow);
      padding: 24px;
      backdrop-filter: blur(18px);
    }

    .hero {
      margin-bottom: 20px;
      position: relative;
      overflow: hidden;
    }

    .hero h1 {
      margin: 0 0 8px;
      font-size: clamp(1.8rem, 4vw, 3rem);
      letter-spacing: -0.04em;
    }

    .hero p {
      margin: 0;
      color: var(--muted);
      line-height: 1.6;
      max-width: 70ch;
    }

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

    .layout {
      display: grid;
      grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
      gap: 18px;
    }

    .item-grid,
    .summary-grid {
      display: grid;
      gap: 12px;
    }

    .summary-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      margin-bottom: 18px;
    }

    .summary-item,
    .match-item {
      padding: 14px;
      border-radius: 18px;
      background: color-mix(in srgb, rgba(36, 87, 255, 0.08) 45%, var(--panel-strong));
      border: 1px solid var(--border);
    }

    .summary-item span,
    .match-item small {
      display: block;
      color: var(--muted);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 6px;
    }

    .summary-item strong,
    .match-item strong { display: block; line-height: 1.4; }

    .match-list {
      display: grid;
      gap: 10px;
      margin-top: 12px;
    }

    .match-choice {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      cursor: pointer;
    }

    .field {
      display: grid;
      gap: 8px;
      margin-top: 14px;
    }

    .field label { font-weight: 650; }
    .field input {
      border: 1px solid var(--border);
      background: var(--panel-strong);
      color: var(--text);
      border-radius: 16px;
      padding: 13px 14px;
      outline: none;
    }

    .field input:focus {
      border-color: color-mix(in srgb, var(--accent) 65%, white);
      box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 18%, transparent);
    }

    .actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 18px;
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
    }

    .btn.primary {
      background: linear-gradient(135deg, var(--accent), var(--accent-strong));
      color: #fff;
      border-color: transparent;
    }

    .btn:disabled { opacity: 0.5; cursor: not-allowed; }

    .hidden { display: none !important; }

    @media (max-width: 900px) {
      .layout,
      .summary-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main class="shell">
    <section class="hero">
      <h1>Verify customer details</h1>
      <p>Please review and confirm your identity options below to finalize your booking assignment securely.</p>
    </section>

    <section class="card" id="pageStateCard">
      <div id="loadingNotice" class="notice">Loading verification link...</div>
      <div id="requestArea" class="hidden">
        <div class="layout">
          <div>
            <div class="summary-grid" id="requestSummary"></div>
            <div id="requestNotice" class="notice is-warning hidden"></div>
          </div>
          <aside class="card" style="padding:18px; box-shadow:none; background: color-mix(in srgb, var(--panel-strong) 90%, transparent);">
            <h3 style="margin-top:0;">Customer selection</h3>
            <p style="margin:0 0 12px; color:var(--muted); line-height:1.6;">Choose one of the matching customer profiles or create a new profile identity variant.</p>
            <div id="matchesList" class="match-list"></div>
            <div class="field" style="grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 14px;">
              <div>
                <label for="newFirstName" style="display: block; font-weight: 650; margin-bottom: 8px;">First name *</label>
                <input id="newFirstName" type="text" placeholder="First name" style="width: 100%; border: 1px solid var(--border); background: var(--panel-strong); color: var(--text); border-radius: 16px; padding: 13px 14px; outline: none;" disabled>
              </div>
              <div>
                <label for="newLastName" style="display: block; font-weight: 650; margin-bottom: 8px;">Last name *</label>
                <input id="newLastName" type="text" placeholder="Last name" style="width: 100%; border: 1px solid var(--border); background: var(--panel-strong); color: var(--text); border-radius: 16px; padding: 13px 14px; outline: none;" disabled>
              </div>
            </div>
          </aside>
        </div>
        <div class="actions">
          <button type="button" class="btn" id="cancelBtn">Cancel</button>
          <button type="button" class="btn primary" id="confirmBtn">Confirm customer and book</button>
        </div>
      </div>
      <div id="resultArea" class="hidden"></div>
    </section>
  </main>

  <script>
  const BOOMY = {
    csrfToken: <?php echo json_encode($csrfToken); ?>,
    token: <?php echo json_encode($token); ?>,
    requestUrl: 'booking_api.php?action=request',
    verifyUrl: 'booking_api.php?action=verify'
  };

  const els = {
    loadingNotice: document.getElementById('loadingNotice'),
    requestArea: document.getElementById('requestArea'),
    requestSummary: document.getElementById('requestSummary'),
    requestNotice: document.getElementById('requestNotice'),
    matchesList: document.getElementById('matchesList'),
    newFirstName: document.getElementById('newFirstName'),
    newLastName: document.getElementById('newLastName'),
    cancelBtn: document.getElementById('cancelBtn'),
    confirmBtn: document.getElementById('confirmBtn'),
    resultArea: document.getElementById('resultArea')
  };

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"]/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;'
    }[char]));
  }

  function setNotice(text, warning = true) {
    els.requestNotice.textContent = text || '';
    els.requestNotice.classList.toggle('hidden', !text);
    els.requestNotice.classList.toggle('is-warning', warning);
    els.requestNotice.classList.toggle('is-error', !warning);
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

  function renderSummary(appointment, provider, appointmentType, matches) {
    // 1. Render core appointment details
    const bookedCustomerName = appointment.customer ? (appointment.customer.full_name || appointment.customer.email || '-') : '-';
    
    els.requestSummary.innerHTML = [
      ['Appointment type', appointmentType ? appointmentType.name : '-'],
      ['Provider', provider ? (provider.full_name || provider.email || '-') : '-'],
      ['Start time', appointment.start_time || '-'],
      ['Name on Booking', bookedCustomerName]
    ].map((row) => {
      return '<div class="summary-item"><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong></div>';
    }).join('');

    // 2. Clear out list layout
    els.matchesList.innerHTML = '';

    if (matches && matches.length > 0) {
      matches.forEach((profile) => {
        const div = document.createElement('div');
        div.className = 'match-item';
        div.style.marginBottom = '8px';
        
        const isMatch = profile.full_name === appointment.customer?.full_name;

        div.innerHTML = `
          <label class="match-choice">
            <input type="radio" name="selected_customer_id" value="${escapeHtml(profile.id)}" ${isMatch ? 'checked' : ''}>
            <div>
              <strong>${escapeHtml(profile.full_name)}</strong>
              <small>${escapeHtml(profile.email)} ${profile.mobile ? ' / ' + escapeHtml(profile.mobile) : ''}</small>
            </div>
          </label>
        `;
        els.matchesList.appendChild(div);
      });
      
      setNotice('Multiple profiles share this contact info. Please select the correct identity or create a new profile.');
    } else {
      // Clean baseline profile fallback option
      const defaultDiv = document.createElement('div');
      defaultDiv.className = 'match-item';
      defaultDiv.style.marginBottom = '8px';
      defaultDiv.innerHTML = `
        <label class="match-choice">
          <input type="radio" name="selected_customer_id" value="current" checked>
          <div>
            <strong>Use name from booking (${escapeHtml(bookedCustomerName)})</strong>
            <small>Finalize the appointment with the exact details provided.</small>
          </div>
        </label>
      `;
      els.matchesList.appendChild(defaultDiv);
      setNotice('Review your appointment details below, then confirm to finalize the booking.');
    }

    // Always render the option to create a newly modified identity profile variant
    const newProfileDiv = document.createElement('div');
    newProfileDiv.className = 'match-item';
    newProfileDiv.innerHTML = `
      <label class="match-choice">
        <input type="radio" name="selected_customer_id" value="new">
        <div>
          <strong>Create a new profile instead</strong>
          <small>Select this if you need to register under a different first and last name configuration.</small>
        </div>
      </label>
    `;
    els.matchesList.appendChild(newProfileDiv);

    // 3. Dynamic input enabling toggle engine
    const radios = document.querySelectorAll('input[name="selected_customer_id"]');
    const toggleInputField = () => {
      const selectedRadio = document.querySelector('input[name="selected_customer_id"]:checked');
      if (selectedRadio && selectedRadio.value === 'new') {
        els.newFirstName.disabled = false;
        els.newLastName.disabled = false;
        els.newFirstName.focus();
      } else {
        els.newFirstName.disabled = true;
        els.newLastName.disabled = true;
        els.newFirstName.value = '';
        els.newLastName.value = '';
      }
    };

    radios.forEach(radio => radio.addEventListener('change', toggleInputField));
    toggleInputField();
  }

  function renderResult(result) {
    const pending = Boolean(result.pending);
    const appointment = result.appointment || {};
    const customer = result.customer || {};
    const verifyUrl = result.verify_url || '';

    els.requestArea.classList.add('hidden');
    els.resultArea.classList.remove('hidden');
    els.resultArea.innerHTML = [
      '<div class="notice ' + (pending ? 'is-warning' : '') + '"><strong>' + escapeHtml(pending ? 'Verification link sent' : 'Appointment confirmed') + '</strong><br>' + escapeHtml(pending ? 'The customer will receive a verification link by email before the appointment is created.' : 'The appointment has been created and the confirmation email was sent.') + '</div>',
      '<div class="summary-grid" style="margin-top:18px;">',
      '<div class="summary-item"><span>Customer</span><strong>' + escapeHtml(customer.full_name || 'Pending verification') + '</strong></div>',
      '<div class="summary-item"><span>Start time</span><strong>' + escapeHtml(appointment.start_time || '-') + '</strong></div>',
      '<div class="summary-item"><span>Status</span><strong>' + escapeHtml(pending ? 'Pending verification' : (appointment.status || 'booked')) + '</strong></div>',
      '<div class="summary-item"><span>Contact</span><strong>' + escapeHtml((customer.email || '') + (customer.mobile ? ' / ' + customer.mobile : '')) + '</strong></div>',
      '</div>',
      pending && verifyUrl ? '<div class="actions"><a class="btn primary" href="' + escapeHtml(verifyUrl) + '">Open verification link</a></div>' : '<div class="actions"><a class="btn" href="booking.php">Book another appointment</a></div>'
    ].join('');
  }

  async function loadRequest() {
    if (!BOOMY.token) {
      els.loadingNotice.textContent = 'Missing verification token.';
      els.loadingNotice.classList.add('is-error');
      return;
    }

    const url = new URL(BOOMY.requestUrl, window.location.href);
    url.searchParams.set('token', BOOMY.token);
    const data = await fetchJson(url.toString(), { method: 'GET' });
    const appointment = data.appointment || {};
    const provider = data.provider || null;
    const appointmentType = data.appointment_type || null;
    const matches = data.matches || [];

    els.loadingNotice.classList.add('hidden');
    els.requestArea.classList.remove('hidden');
    renderSummary(appointment, provider, appointmentType, matches);

    if ((appointment.status || 'pending') !== 'unconfirmed') {
      els.requestArea.classList.add('hidden');
      els.resultArea.classList.remove('hidden');
      els.resultArea.innerHTML = '<div class="notice">This appointment is already ' + escapeHtml(appointment.status || 'completed') + '.</div>';
      return;
    }
  }

  async function submitVerification() {
    const selectedRadio = document.querySelector('input[name="selected_customer_id"]:checked');
    if (!selectedRadio) {
      alert('Please select a customer profile option before confirming.');
      return;
    }

    const customerSelection = selectedRadio.value;
    const firstName = els.newFirstName.value.trim();
    const lastName = els.newLastName.value.trim();

    if (customerSelection === 'new') {
      if (firstName === '') {
        alert('First name is required.');
        els.newFirstName.focus();
        return;
      }
      if (lastName === '') {
        alert('Last name is required.');
        els.newLastName.focus();
        return;
      }
    }

    els.confirmBtn.disabled = true;
    els.confirmBtn.textContent = 'Processing...';

    try {
      const payload = {
        csrf_token: BOOMY.csrfToken,
        token: BOOMY.token,
        customer_option: customerSelection, 
        first_name: customerSelection === 'new' ? firstName : null,
        last_name: customerSelection === 'new' ? lastName : null
      };

      const result = await fetchJson(BOOMY.verifyUrl, {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      renderResult(result);
    } catch (error) {
      alert(error.message || 'Unable to confirm the booking.');
    } finally {
      els.confirmBtn.disabled = false;
      els.confirmBtn.textContent = 'Confirm customer and book';
    }
  }

  els.cancelBtn.addEventListener('click', () => {
    window.location.href = 'booking.php';
  });
  els.confirmBtn.addEventListener('click', submitVerification);

  loadRequest().catch((error) => {
    els.loadingNotice.textContent = error.message || 'Unable to load verification link.';
    els.loadingNotice.classList.add('is-error');
  });
</script>
</body>
</html>
