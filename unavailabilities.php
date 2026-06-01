<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();
require_login();

$user = $_SESSION['user'] ?? [];
$db = get_db();

// Fetch all available administrators and providers to populate our filter bar
$providers = [];
try {
    $stmt = $db->query("SELECT id, full_name, email, role, is_active FROM users WHERE role IS NOT NULL AND role <> '' AND role <> 'customer' ORDER BY full_name ASC, id ASC");
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $providers = [];
}

// Set up sensible default selection context
$initialProviderId = null;
if (!empty($providers)) {
    $initialProviderId = (int) $providers[0]['id'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Manage Unavailabilities - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="https://unpkg.com/@picocss/pico@1.*/css/pico.min.css">
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
      max-width: 1200px;
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

    .toolbar button {
      border: 1px solid var(--panel-border);
      background: rgba(255, 255, 255, 0.8);
      color: var(--text);
      border-radius: 999px;
      padding: 10px 14px;
      font: inherit;
      cursor: pointer;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 28px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
      overflow: hidden;
      margin-bottom: 20px;
    }

    .panel-header {
      padding: 18px 20px;
      border-bottom: 1px solid var(--panel-border);
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

    .panel-body {
      padding: 18px;
    }

    .unavail-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.95rem;
    }

    .unavail-table thead {
      background: rgba(15, 23, 42, 0.04);
    }

    .unavail-table th {
      padding: 12px;
      text-align: left;
      font-weight: 700;
      color: var(--text);
      border-bottom: 2px solid var(--panel-border);
    }

    .unavail-table td {
      padding: 12px;
      border-bottom: 1px solid var(--panel-border);
    }

    .unavail-table tbody tr:hover {
      background: rgba(36, 87, 255, 0.03);
    }

    .badge {
      display: inline-block;
      padding: 2px 8px;
      font-size: 0.75rem;
      font-weight: 700;
      border-radius: 6px;
      text-transform: uppercase;
    }

    .badge--single { background: rgba(36, 87, 255, 0.1); color: var(--accent); }
    .badge--recurring { background: rgba(16, 185, 129, 0.1); color: var(--accent-2); }

    .modal-dialog {
      width: min(600px, calc(100vw - 24px));
      border: 0;
      padding: 0;
      border-radius: 28px;
      box-shadow: 0 28px 80px rgba(15, 23, 42, 0.22);
      background: transparent;
      margin: auto;
      position: fixed;
      inset: 0;
    }

    .modal-dialog::backdrop {
      background: rgba(15, 23, 42, 0.5);
      backdrop-filter: blur(4px);
    }

    .modal-card {
      position: relative;
      width: 100%;
      background: var(--panel);
      border: 1px solid var(--panel-border);
      color: var(--text);
      border-radius: 28px;
      overflow: hidden;
    }

    .modal-card__head {
      padding: 16px 18px;
      border-bottom: 1px solid var(--panel-border);
    }

    .modal-card__head h2 { margin: 0; font-size: 1.15rem; }

    .modal-card__body {
      padding: 16px 18px 18px;
      display: grid;
      gap: 12px;
    }

    .field label {
      display: block;
      font-size: 0.8rem;
      font-weight: 700;
      margin-bottom: 4px;
    }

    .field input, .field select {
      width: 100%;
      border: 1px solid rgba(15, 23, 42, 0.15);
      border-radius: 14px;
      padding: 10px 12px;
      font: inherit;
      color: var(--text);
      background: #fff;
      box-sizing: border-box;
    }

    html[data-theme="dark"] .field input,
    html[data-theme="dark"] .field select {
      background: rgba(15, 23, 42, 0.5) !important;
      border-color: rgba(226, 232, 240, 0.12) !important;
      color: #f8fafc !important;
    }

    .modal-card__footer {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      padding: 0 18px 18px;
    }

    .modal-card__footer button {
      border: 0;
      border-radius: 999px;
      padding: 9px 14px;
      font-weight: 700;
      cursor: pointer;
    }

    .modal-card__footer button.primary { background: var(--accent); color: #fff; }

    .alert {
      padding: 12px;
      border-radius: 14px;
      font-size: 0.9rem;
      margin-bottom: 12px;
      display: none;
    }
    .alert.error { background: rgba(239, 68, 68, 0.08); color: #7f1d1d; border: 1px solid rgba(239, 68, 68, 0.2); }
    .alert.success { background: rgba(16, 185, 129, 0.08); color: #065f46; border: 1px solid rgba(16, 185, 129, 0.2); }
  </style>
</head>
<body>
  <?php $current_page = 'unavailabilities.php'; include __DIR__ . '/assets/nav.html'; ?>

  <div class="page-shell">
    <div class="hero">
      <div>
        <h1>Provider Unavailabilities</h1>
        <p>Set custom rolling schedules or block individual single date footprints out of client reservation options.</p>
      </div>
      <div class="toolbar">
        <button type="button" id="openAddModalBtn">+ Add Unavailability</button>
      </div>
    </div>

    <div id="globalAlert" class="alert"></div>

    <div class="panel">
      <div class="panel-header">
        <small>Filter Board Target</small>
      </div>
      <div class="panel-body">
        <div class="field" style="max-width: 320px; margin: 0;">
          <label for="providerFilterSelect">Select Staff Profile</label>
          <select id="providerFilterSelect">
            <?php foreach ($providers as $p): ?>
              <option value="<?php echo (int) $p['id']; ?>" <?php echo ((int)$p['id'] === $initialProviderId) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($p['full_name'] ?: $p['email']); ?>
                <?php echo ((int)($p['is_active'] ?? 0) !== 1) ? ' (inactive)' : ''; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <small>Active Lockout Rules Block Registry</small>
      </div>
      <div class="panel-body">
        <div id="tableLoading" style="text-align:center; color:var(--muted); padding:20px;">Fetching records...</div>
        
        <table class="unavail-table" id="unavailTable" style="display:none;">
          <thead>
            <tr>
              <th>Scope</th>
              <th>Details / Day Pattern</th>
              <th>Time Window Bounds</th>
              <th>Valid Date Lifespan</th>
              <th>Reason</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="unavailTableBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <dialog id="unavailModal" class="modal-dialog">
    <div class="modal-card">
      <div class="modal-card__head">
        <h2>Add Provider Unavailability</h2>
      </div>
      <div class="modal-card__body">
        <div id="modalAlert" class="alert error"></div>
        
        <form id="unavailForm" onsubmit="return false;">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          
          <div class="field">
            <label for="modalProviderId">Target Provider Staff Profile</label>
            <select id="modalProviderId" name="provider_id">
              <?php foreach ($providers as $p): ?>
                <option value="<?php echo (int) $p['id']; ?>">
                  <?php echo htmlspecialchars($p['full_name'] ?: $p['email']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="modalType">Rule Strategy Engine Type</label>
            <select id="modalType" name="type">
              <option value="single">Single Layout Footprint (One-off Event)</option>
              <option value="recurring">Rolling Calendar Constraint (Recurring)</option>
            </select>
          </div>

          <div id="singleFieldsContainer">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div class="field">
                <label for="modalStartDatetime">Start Datetime Bounds</label>
                <input type="datetime-local" id="modalStartDatetime" name="start_datetime">
              </div>
              <div class="field">
                <label for="modalEndDatetime">End Datetime Bounds</label>
                <input type="datetime-local" id="modalEndDatetime" name="end_datetime">
              </div>
            </div>
          </div>

          <div id="recurringFieldsContainer" style="display: none;">
            <div class="field">
              <label for="modalDayOfWeek">Target Day of Week Pattern</label>
              <select id="modalDayOfWeek" name="day_of_week">
                <option value="1">Every Monday</option>
                <option value="2">Every Tuesday</option>
                <option value="3">Every Wednesday</option>
                <option value="4">Every Thursday</option>
                <option value="5">Every Friday</option>
                <option value="6">Every Saturday</option>
                <option value="7">Every Sunday</option>
              </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div class="field">
                <label for="modalStartTime">Start Time (Daily Rule)</label>
                <input type="time" id="modalStartTime" name="start_time" step="60">
              </div>
              <div class="field">
                <label for="modalEndTime">End Time (Daily Rule)</label>
                <input type="time" id="modalEndTime" name="end_time" step="60">
              </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 8px;">
              <div class="field">
                <label for="modalRollingStartDate">Rolling Lifespan Start</label>
                <input type="date" id="modalRollingStartDate" name="rolling_start_date">
              </div>
              <div class="field">
                <label for="modalRollingEndDate">Rolling Lifespan End</label>
                <input type="date" id="modalRollingEndDate" name="rolling_end_date" placeholder="Indefinite rules span">
              </div>
            </div>
          </div>

          <div class="field">
            <label for="modalReason">Reason / Log Note Description</label>
            <input type="text" id="modalReason" name="reason" placeholder="e.g., Personal Leave, Direct Training Break">
          </div>
        </form>
      </div>
      <div class="modal-card__footer">
        <button type="button" id="closeModalBtn" class="secondary">Cancel</button>
        <button type="button" id="saveUnavailBtn" class="primary">Save Block Constraint</button>
      </div>
    </div>
  </dialog>

  <script src="assets/js/global.js"></script>
  <script>
    const filterSelect = document.getElementById('providerFilterSelect');
    const tableLoading = document.getElementById('tableLoading');
    const unavailTable = document.getElementById('unavailTable');
    const unavailTableBody = document.getElementById('unavailTableBody');

    const unavailModal = document.getElementById('unavailModal');
    const unavailForm = document.getElementById('unavailForm');
    const modalType = document.getElementById('modalType');
    const singleContainer = document.getElementById('singleFieldsContainer');
    const recurringContainer = document.getElementById('recurringFieldsContainer');
    
    const globalAlert = document.getElementById('globalAlert');
    const modalAlert = document.getElementById('modalAlert');

    const dayLabels = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };

    function displayFlashMessage(element, text, status = 'success') {
      element.textContent = text;
      element.className = `alert ${status}`;
      element.style.display = 'block';
      if (element === globalAlert) {
        setTimeout(() => { element.style.display = 'none'; }, 5000);
      }
    }

    // Toggle visual column tracks based on strategy selection rules
    modalType.addEventListener('change', function() {
      if (this.value === 'recurring') {
        singleContainer.style.display = 'none';
        recurringContainer.style.display = 'block';
      } else {
        singleContainer.style.display = 'block';
        recurringContainer.style.display = 'none';
      }
      modalAlert.style.display = 'none';
    });

    async function loadUnavailabilitiesTable() {
      const pid = filterSelect.value;
      if (!pid) return;

      tableLoading.style.display = 'block';
      unavailTable.style.display = 'none';
      unavailTableBody.innerHTML = '';

      try {
        const response = await fetch(`unavailabilities_api.php?action=list&provider_id=${pid}`);
        if (!response.ok) throw new Error('Failed tracking connection parameters');
        
        const data = await response.json();
        const list = data.unavailabilities || [];

        if (list.length === 0) {
          unavailTableBody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--muted);">No unavailabilities logged for this profile track context.</td></tr>';
        } else {
          list.forEach(item => {
            const tr = document.createElement('tr');
            
            let detailCell = '-';
            let timeCell = '-';
            let lifespanCell = 'Permanent';

            if (item.type === 'single') {
              const startParsed = new Date(item.start_datetime.replace(' ', 'T'));
              const endParsed = new Date(item.end_datetime.replace(' ', 'T'));
              detailCell = startParsed.toLocaleDateString();
              timeCell = `${startParsed.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})} to ${endParsed.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}`;
              lifespanCell = 'One-off Single';
            } else {
              detailCell = `Every ${dayLabels[item.day_of_week] || 'Day'}`;
              timeCell = `${String(item.start_time).slice(0,5)} to ${String(item.end_time).slice(0,5)}`;
              
              const sDate = item.rolling_start_date ? new Date(item.rolling_start_date).toLocaleDateString() : 'Start Instant';
              const eDate = item.rolling_end_date ? new Date(item.rolling_end_date).toLocaleDateString() : 'Indefinite';
              lifespanCell = `${sDate} → ${eDate}`;
            }

            tr.innerHTML = `
              <td><span class="badge badge--${item.type}">${item.type}</span></td>
              <td><strong>${detailCell}</strong></td>
              <td>${timeCell}</td>
              <td style="font-size:0.88rem; color:var(--muted);">${lifespanCell}</td>
              <td><em>${item.reason ? escapeHtml(item.reason) : 'No description'}</em></td>
              <td><button type="button" class="secondary" style="padding:4px 10px; font-size:0.8rem; margin:0;" onclick="deleteUnavailabilityTrack(${item.id})">Remove</button></td>
            `;
            unavailTableBody.appendChild(tr);
          });
        }
        unavailTable.style.display = 'table';
      } catch (err) {
        displayFlashMessage(globalAlert, 'Failed loading list pipeline context: ' + err.message, 'error');
      } finally {
        tableLoading.style.display = 'none';
      }
    }

    async function deleteUnavailabilityTrack(id) {
      if (!window.confirm('Drop this active rule lockout constraint? Appointments may instantly slide onto this track path.')) return;

      try {
        const response = await fetch('unavailabilities_api.php?action=delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            csrf_token: document.querySelector('input[name="csrf_token"]').value,
            id: id
          })
        });

        const res = await response.json();
        if (!response.ok || !res.ok) throw new Error(res.error || 'Pipeline write error rejection');

        displayFlashMessage(globalAlert, 'Lockout structure removed successfully.', 'success');
        loadUnavailabilitiesTable();
      } catch (err) {
        displayFlashMessage(globalAlert, 'Rejection dropping rule payload: ' + err.message, 'error');
      }
    }

    document.getElementById('openAddModalBtn').addEventListener('click', () => {
      unavailForm.reset();
      modalType.value = 'single';
      modalType.dispatchEvent(new Event('change'));
      document.getElementById('modalProviderId').value = filterSelect.value;
      modalAlert.style.display = 'none';
      unavailModal.showModal();
    });

    document.getElementById('closeModalBtn').addEventListener('click', () => { unavailModal.close(); });

    document.getElementById('saveUnavailBtn').addEventListener('click', async () => {
      modalAlert.style.display = 'none';
      
      const formData = new FormData(unavailForm);
      const payload = {};
      formData.forEach((val, key) => { payload[key] = val; });

      try {
        const response = await fetch('unavailabilities_api.php?action=create', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        const res = await response.json();
        if (!response.ok || !res.ok) throw new Error(res.error || 'Server processing error structural fault');

        displayFlashMessage(globalAlert, 'Unavailability block pinned successfully.', 'success');
        unavailModal.close();
        loadUnavailabilitiesTable();
      } catch (err) {
        displayFlashMessage(modalAlert, err.message, 'error');
      }
    });

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    filterSelect.addEventListener('change', loadUnavailabilitiesTable);
    loadUnavailabilitiesTable();
  </script>
</body>
</html>