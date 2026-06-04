<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();
require_login();

$user = $_SESSION['user'] ?? [];
$role = (string) ($user['role'] ?? '');

if ($role !== 'admin') {
    header('Location: admin.php');
    exit;
}

$settings = get_customer_field_settings();

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
  <title>Customers - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="assets/css/pico.min.css" id="pico-css" onerror="this.onerror=null; this.href='https://unpkg.com/@picocss/pico@2.*/css/pico.min.css';">
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

    .page-shell { max-width: 1200px; margin: 0 auto; padding: 28px 18px 42px; }

    .hero { display: flex; flex-wrap: wrap; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
    .hero h1 { margin: 0; font-size: clamp(2rem, 3vw, 3rem); line-height: 1.05; letter-spacing: -0.03em; }
    .hero p { margin: 10px 0 0; color: var(--muted); max-width: 62ch; }

    .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: flex-end; }
    .toolbar a, .toolbar button {
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

    .panel { background: var(--panel); border: 1px solid var(--panel-border); border-radius: 28px; box-shadow: var(--shadow); backdrop-filter: blur(10px); overflow: hidden; margin-bottom: 20px; }
    .panel-header { padding: 18px 20px; border-bottom: 1px solid var(--panel-border); }
    .panel-header small { display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; }
    .panel-body { padding: 18px; }

    /* ── Shared modal base ── */
    .modal-dialog {
      width: min(640px, calc(100vw - 24px));
      border: 0;
      padding: 0;
      border-radius: 28px;
      box-shadow: 0 28px 80px rgba(15, 23, 42, 0.22);
      background: transparent;
      margin: auto;
      position: fixed;
      inset: 0;
    }
    .modal-dialog::backdrop { background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); }
    .modal-card { position: relative; width: 100%; background: var(--panel); border: 1px solid var(--panel-border); color: var(--text); border-radius: 28px; overflow: hidden; }
    .modal-card__head { padding: 16px 18px; background: transparent; color: var(--text); border-bottom: 1px solid var(--panel-border); display: flex; align-items: center; justify-content: space-between; }
    .modal-card__head h2 { margin: 0; font-size: 1.15rem; letter-spacing: -0.02em; }
    .modal-card__body { padding: 16px 18px 18px; display: grid; gap: 12px; }
    .modal-card__footer { display: flex; justify-content: flex-end; gap: 12px; padding: 0 18px 18px; }
    .modal-card__footer button { border: 0; border-radius: 999px; padding: 9px 14px; background: rgba(226, 232, 240, 0.9); color: var(--text); font: inherit; font-weight: 700; cursor: pointer; }
    .modal-card__footer button.primary { background: var(--accent); color: #fff; }

    /* ── Edit modal form ── */
    #customerForm { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    #customerForm .field { min-width: 0; }
    @media (max-width: 640px) { #customerForm { grid-template-columns: 1fr; } }

    .field label { display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px; color: var(--label); }
    .field input, .field textarea, .field select { width: 100%; border: 1px solid rgba(15, 23, 42, 0.15); border-radius: 14px; padding: 10px 12px; font: inherit; color: var(--text); background: var(--panel); box-sizing: border-box; font-size: 0.92rem; }
    .field input:focus, .field textarea:focus, .field select:focus { outline: 3px solid rgba(36, 87, 255, 0.14); border-color: rgba(36, 87, 255, 0.5); }

    /* ── Meta modal ── */
    #metaModal { width: min(680px, calc(100vw - 24px)); }

    .meta-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    @media (max-width: 560px) { .meta-grid { grid-template-columns: 1fr; } }

    .meta-item {
      padding: 12px 14px;
      border-radius: 16px;
      border: 1px solid var(--panel-border);
      background: rgba(15, 23, 42, 0.03);
      min-width: 0;
    }
    .meta-item .meta-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 4px; }
    .meta-item .meta-value { font-size: 0.9rem; word-break: break-all; line-height: 1.45; }
    .meta-item .meta-value.empty { color: var(--muted); font-style: italic; }

    .meta-item.span-2 { grid-column: span 2; }
    @media (max-width: 560px) { .meta-item.span-2 { grid-column: span 1; } }

    /* ── Signal pills ── */
    .signal-pills { display: flex; flex-wrap: wrap; gap: 5px; }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 700;
      white-space: nowrap;
      cursor: default;
    }
    .pill-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }

    .pill.green  { background: rgba(16, 185, 129, 0.12); color: #065f46; border: 1px solid rgba(16, 185, 129, 0.25); }
    .pill.yellow { background: rgba(245, 158, 11, 0.12); color: #78350f; border: 1px solid rgba(245, 158, 11, 0.28); }
    .pill.red    { background: rgba(239, 68, 68, 0.10);  color: #7f1d1d; border: 1px solid rgba(239, 68, 68, 0.22); }

    .pill.green  .pill-dot { background: #10b981; }
    .pill.yellow .pill-dot { background: #f59e0b; }
    .pill.red    .pill-dot { background: #ef4444; }

    /* dark mode pills */
    html[data-theme="dark"] .pill.green  { background: rgba(16, 185, 129, 0.18); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.35); }
    html[data-theme="dark"] .pill.yellow { background: rgba(245, 158, 11, 0.18); color: #fcd34d; border-color: rgba(245, 158, 11, 0.35); }
    html[data-theme="dark"] .pill.red    { background: rgba(239, 68, 68, 0.18);  color: #fca5a5; border-color: rgba(239, 68, 68, 0.32); }

    /* signals section inside meta modal */
    .signals-section { display: grid; gap: 8px; }
    .signal-row {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 11px 13px;
      border-radius: 14px;
      border: 1px solid var(--panel-border);
      background: rgba(15, 23, 42, 0.02);
    }
    .signal-row .signal-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }
    .signal-row .signal-body { min-width: 0; flex: 1; }
    .signal-row .signal-title { font-size: 0.88rem; font-weight: 700; margin-bottom: 2px; }
    .signal-row .signal-detail { font-size: 0.8rem; color: var(--muted); word-break: break-all; line-height: 1.4; }

    .signal-row.green  { border-color: rgba(16, 185, 129, 0.25); background: rgba(16, 185, 129, 0.05); }
    .signal-row.yellow { border-color: rgba(245, 158, 11, 0.28);  background: rgba(245, 158, 11, 0.05); }
    .signal-row.red    { border-color: rgba(239, 68, 68, 0.22);   background: rgba(239, 68, 68, 0.05);  }

    html[data-theme="dark"] .signal-row.green  { border-color: rgba(16, 185, 129, 0.3);  background: rgba(16, 185, 129, 0.08); }
    html[data-theme="dark"] .signal-row.yellow { border-color: rgba(245, 158, 11, 0.32); background: rgba(245, 158, 11, 0.08); }
    html[data-theme="dark"] .signal-row.red    { border-color: rgba(239, 68, 68, 0.28);  background: rgba(239, 68, 68, 0.08);  }

    .no-signals { color: var(--muted); font-size: 0.9rem; padding: 10px 0; }

    /* ── Table ── */
    .customers-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
    .customers-table thead { background: rgba(15, 23, 42, 0.04); }
    .customers-table th { padding: 12px; text-align: left; font-weight: 700; color: var(--text); border-bottom: 2px solid var(--panel-border); }
    .customers-table td { padding: 10px 12px; border-bottom: 1px solid var(--panel-border); vertical-align: middle; }
    .customers-table tbody tr:hover { background: rgba(36, 87, 255, 0.03); }

    .action-group { display: flex; gap: 6px; align-items: center; flex-wrap: nowrap; }
    .action-group button { padding: 4px 10px; font-size: 0.82rem; margin-bottom: 0; border-radius: 999px; border: 1px solid var(--panel-border); background: rgba(255,255,255,0.7); color: var(--text); cursor: pointer; font: inherit; font-size: 0.82rem; font-weight: 600; white-space: nowrap; }
    .action-group button:hover { background: rgba(36, 87, 255, 0.07); }
    .action-group button.info-btn { background: rgba(36, 87, 255, 0.07); border-color: rgba(36, 87, 255, 0.2); color: var(--accent); }
    .action-group button.info-btn:hover { background: rgba(36, 87, 255, 0.14); }

    /* ── Misc ── */
    .alert { padding: 14px 16px; border-radius: 16px; margin-bottom: 16px; }
    .alert.info    { background: rgba(36, 87, 255, 0.08);  border: 1px solid rgba(36, 87, 255, 0.2);  color: var(--text); }
    .alert.error   { background: rgba(239, 68, 68, 0.08);  border: 1px solid rgba(239, 68, 68, 0.2);  color: #7f1d1d; }
    .alert.success { background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); color: #065f46; }

    .override-note { background: var(--panel); border: 1px solid var(--panel-border); border-left: 3px solid rgba(245, 158, 11, 0.6); padding: 12px 14px; border-radius: 16px; margin: 10px 0; font-size: 0.9rem; color: var(--text); }

    html[data-theme="dark"] .modal-dialog { box-shadow: 0 28px 80px rgba(0, 0, 0, 0.32); }
    html[data-theme="dark"] .modal-card__footer button { background: rgba(30, 50, 80, 0.6); color: var(--text); }
    html[data-theme="dark"] .modal-card__footer button.primary { background: var(--accent); color: #fff; }
    html[data-theme="dark"] .override-note { background: rgba(20, 40, 70, 0.45); }
    html[data-theme="dark"] .field input, html[data-theme="dark"] .field textarea { background: rgba(15, 23, 42, 0.5); border-color: rgba(226, 232, 240, 0.12); }
    html[data-theme="dark"] .field select {
  background: rgba(15, 23, 42, 0.5);
  border-color: rgba(226, 232, 240, 0.12);
}
html[data-theme="dark"] .meta-item { background: rgba(255, 255, 255, 0.03); }
    html[data-theme="dark"] .action-group button { background: rgba(255,255,255,0.06); }
  </style>
</head>
<body>
  <?php $current_page = 'customers.php'; include __DIR__ . '/assets/nav.html'; ?>

  <div class="page-shell">
    <div class="hero">
      <div>
        <h1>Customer Management</h1>
        <p>Create and manage customer records for appointments.</p>
      </div>
      <div class="toolbar">
        <button id="addCustomerBtn">+ Add Customer</button>
      </div>
    </div>

    <div id="alertContainer"></div>

    <div class="panel">
      <div class="panel-header"><small>Search & Filter Options</small></div>
      <div class="panel-body">
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
          <input type="text" id="searchInput" placeholder="Search by name, email, or mobile..." style="flex:1; min-width:200px; margin-bottom:0;" />
          <select id="sortSelect" style="min-width:220px; width:auto; margin-bottom:0;">
            <option value="full_name">Sort: Full name</option>
            <option value="first_name">Sort: First name</option>
            <option value="last_name">Sort: Last name</option>
            <option value="mobile">Sort: Mobile</option>
            <option value="email">Sort: Email</option>
            <option value="date_added_desc">Sort: Date added (newest)</option>
            <option value="date_added_asc">Sort: Date added (oldest)</option>
            <option value="gender">Sort: Gender</option>
          </select>
          <button type="button" id="searchBtn" style="margin-bottom:0; width:auto;">Search</button>
          <button type="button" id="clearBtn" class="secondary" style="margin-bottom:0; width:auto;">Clear</button>
        </div>
        
        <div style="margin-top: 14px; display: flex; align-items: center;">
          <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 0; cursor: pointer; font-size: 0.9rem;">
            <input type="checkbox" id="showSignalsToggle" style="margin-bottom: 0;" checked />
            Show duplicate signals column in registry table
          </label>
        </div>
        
        <div id="resultCount" style="color:var(--muted); font-size:0.9rem; margin-top:12px;">Loading...</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header"><small>Customer Registry</small></div>
      <div class="panel-body">
        <div id="customersLoading" style="text-align:center; color:var(--muted); padding:20px;">Loading customers...</div>
        <table class="customers-table" id="customersTable" style="display:none;">
          <thead>
            <tr>
              <th>Full Name</th>
              <?php if (!empty($settings['date_of_birth']['display'])): ?>
                <th>Date of Birth</th>
              <?php endif; ?>
              <th>Gender</th>
              <th>Email</th>
              <th>Mobile</th>
              <th>Date Added</th>
              <th id="thSignals">Signals</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="customersTableBody"></tbody>
        </table>
        <div id="customersPagination" style="display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap; margin-top:14px;"></div>
      </div>
    </div>
  </div>

  <dialog id="customerModal" class="modal-dialog">
    <div class="modal-card">
      <div class="modal-card__head">
        <h2 id="modalTitle">Create New Customer</h2>
      </div>
      <div class="modal-card__body">
        <div id="modalAlert"></div>
        <div id="customerForm">
          <?php if (!empty($settings['first_name']['display'])): ?>
            <div class="field">
              <label for="firstName"><?php echo render_customer_field_label('First Name', $settings['first_name']); ?></label>
              <input id="firstName" name="first_name" type="text" placeholder="John" />
            </div>
          <?php endif; ?>
          <?php if (!empty($settings['last_name']['display'])): ?>
            <div class="field">
              <label for="lastName"><?php echo render_customer_field_label('Last Name', $settings['last_name']); ?></label>
              <input id="lastName" name="last_name" type="text" placeholder="Doe" />
            </div>
          <?php endif; ?>
          <?php if (!empty($settings['middle_name']['display'])): ?>
            <div class="field">
              <label for="middleName"><?php echo render_customer_field_label('Middle Name', $settings['middle_name']); ?></label>
              <input id="middleName" name="middle_name" type="text" placeholder="Michael" />
            </div>
          <?php endif; ?>
          <?php $dobFieldSettings = $settings['date_of_birth'] ?? ['display' => true, 'required' => false]; ?>
          <div class="field"<?php echo !empty($dobFieldSettings['display']) ? '' : ' style="display:none;"'; ?>>
            <label for="dateOfBirth"><?php echo render_customer_field_label('Date of Birth', $dobFieldSettings); ?></label>
            <input id="dateOfBirth" name="date_of_birth" type="date" />
          </div>
          <?php $genderFieldSettings = $settings['gender'] ?? ['display' => true, 'required' => false]; ?>
          <?php if (!empty($genderFieldSettings['display'])): ?>
            <div class="field">
              <label for="gender"><?php echo render_customer_field_label('Gender', $genderFieldSettings); ?></label>
              <select id="gender" name="gender">
                <option value="">Select gender</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
                <option value="unknown">Unknown</option>
              </select>
            </div>
          <?php endif; ?>
          <?php if (!empty($settings['email']['display'])): ?>
            <div class="field">
              <label for="email"><?php echo render_customer_field_label('Email', $settings['email']); ?></label>
              <input id="email" name="email" type="email" placeholder="john@example.com" />
            </div>
          <?php endif; ?>
          <?php if (!empty($settings['mobile']['display'])): ?>
            <div class="field">
              <label for="mobile"><?php echo render_customer_field_label('Mobile Number', $settings['mobile']); ?></label>
              <input id="mobile" name="mobile" type="text" placeholder="1234567890" />
            </div>
          <?php endif; ?>
        </div>
        <div id="overrideSection" style="display:none;">
          <div class="override-note">
            <strong>Override required fields?</strong>
            <p style="margin:6px 0 0;">Some required fields are missing. As an admin, you can create this customer anyway.</p>
          </div>
          <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" id="adminOverrideCheckbox" style="margin-bottom:0;" />
            Yes, create this customer
          </label>
        </div>
      </div>
      <div class="modal-card__footer">
        <button type="button" id="cancelButton">Cancel</button>
        <button type="button" id="submitButton" class="primary">Create Customer</button>
      </div>
    </div>
  </dialog>

  <dialog id="metaModal" class="modal-dialog" style="width:min(680px, calc(100vw - 24px));">
    <div class="modal-card">
      <div class="modal-card__head" style="display:flex; align-items:center; justify-content:space-between;">
        <h2 id="metaModalTitle">Customer Info</h2>
        <button type="button" id="metaModalClose" style="background:none; border:none; cursor:pointer; font-size:1.2rem; color:var(--muted); padding:4px 8px; border-radius:8px; flex-shrink:0; width:28px; height:28px; display:flex; align-items:center; justify-content:center; margin-left:auto;">✕</button>
      </div>
      <div class="modal-card__body" id="metaModalBody" style="max-height:70vh; overflow-y:auto;">
        <div style="text-align:center; color:var(--muted); padding:20px;">Loading…</div>
      </div>
      <div class="modal-card__footer">
        <button type="button" id="metaModalCloseFooter">Close</button>
      </div>
    </div>
  </dialog>

  <script src="assets/js/global.js"></script>
  <script>
    // ── DOM refs ──────────────────────────────────────────────────────────────
    const customerModal       = document.getElementById('customerModal');
    const metaModal           = document.getElementById('metaModal');
    const metaModalBody       = document.getElementById('metaModalBody');
    const metaModalTitle      = document.getElementById('metaModalTitle');
    const metaModalClose      = document.getElementById('metaModalClose');
    const metaModalCloseFooter= document.getElementById('metaModalCloseFooter');
    const addCustomerBtn      = document.getElementById('addCustomerBtn');
    const cancelButton        = document.getElementById('cancelButton');
    const submitButton        = document.getElementById('submitButton');
    const modalTitle          = document.getElementById('modalTitle');
    const customerForm        = document.getElementById('customerForm');
    const overrideSection     = document.getElementById('overrideSection');
    const adminOverrideCheckbox = document.getElementById('adminOverrideCheckbox');
    const searchInput         = document.getElementById('searchInput');
    const searchBtn           = document.getElementById('searchBtn');
    const clearBtn            = document.getElementById('clearBtn');
    const sortSelect          = document.getElementById('sortSelect');
    const resultCount         = document.getElementById('resultCount');
    const customersTable      = document.getElementById('customersTable');
    const customersTableBody  = document.getElementById('customersTableBody');
    const customersLoading    = document.getElementById('customersLoading');
    const alertContainer      = document.getElementById('alertContainer');
    const modalAlert          = document.getElementById('modalAlert');
    const customersPagination = document.getElementById('customersPagination');
    
    const showSignalsToggle   = document.getElementById('showSignalsToggle');
    const thSignals           = document.getElementById('thSignals');

    const settings   = <?php echo json_encode($settings); ?>;
    const pageSize   = 20;
    let currentPage  = 1;
    let currentSearch= '';
    let currentCustomerMode      = 'create';
    let currentEditingCustomerId = null;

    const isDobColumnVisible = Boolean(settings.date_of_birth && settings.date_of_birth.display);

    const sortLabels = {
      full_name:'Full name', first_name:'First name', last_name:'Last name',
      mobile:'Mobile', email:'Email', date_added_desc:'Date added (newest)',
      date_added_asc:'Date added (oldest)', gender:'Gender'
    };

    // ── Utilities ─────────────────────────────────────────────────────────────
    function escapeHtml(text) {
      const d = document.createElement('div');
      d.textContent = String(text ?? '');
      return d.innerHTML;
    }

    function formatGender(value) {
      const raw = String(value || '').trim();
      if (!raw) return '-';
      return raw.charAt(0).toUpperCase() + raw.slice(1);
    }

    function formatDateTime(value) {
      const raw = String(value || '').trim();
      if (!raw || raw === '-') return '-';
      const parsed = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
      if (Number.isNaN(parsed.getTime())) return raw;
      return parsed.toLocaleString();
    }

    function showAlert(container, message, type = 'info') {
      container.innerHTML = `<div class="alert ${type}">${escapeHtml(message)}</div>`;
    }

    function splitFullName(fullName) {
      const parts = String(fullName || '').trim().split(/\s+/).filter(Boolean);
      if (!parts.length) return { firstName:'', middleName:'', lastName:'' };
      if (parts.length === 1) return { firstName:parts[0], middleName:'', lastName:'' };
      if (parts.length === 2) return { firstName:parts[0], middleName:'', lastName:parts[1] };
      return { firstName:parts[0], middleName:parts.slice(1,-1).join(' '), lastName:parts[parts.length-1] };
    }

    function setFieldValueById(id, value) {
      const el = document.getElementById(id);
      if (el) el.value = value ?? '';
    }

    // ── Signal pills (used in table column) ───────────────────────────────────
    const SIGNAL_ICONS = {
      shared_email:  '✉',
      shared_mobile: '📱',
      shared_ip:     '🌐',
      shared_device: '💻',
    };

    const SIGNAL_LABELS = {
      shared_email:  'Email',
      shared_mobile: 'Mobile',
      shared_ip:     'IP',
      shared_device: 'Device',
    };

    function buildPillsHtml(signals) {
      if (!signals || !signals.length) return '<span style="color:var(--muted); font-size:0.8rem;">—</span>';
      return signals.map(s =>
        `<span class="pill ${s.severity}" title="${escapeHtml(s.label)}: ${s.count} customers share this">
          <span class="pill-dot"></span>${escapeHtml(SIGNAL_LABELS[s.type] || s.label)} ×${s.count}
        </span>`
      ).join('');
    }

    // Cache of already-fetched signals keyed by customer id so re-renders don't
    // re-fetch for every row that's already been loaded.
    const signalCache = {};

    async function fetchSignals(customerId) {
      if (signalCache[customerId] !== undefined) return signalCache[customerId];
      try {
        const resp = await fetch(`customers_api.php?action=signals&id=${customerId}`);
        if (!resp.ok) { signalCache[customerId] = []; return []; }
        const json = await resp.json();
        signalCache[customerId] = json.signals || [];
        return signalCache[customerId];
      } catch {
        signalCache[customerId] = [];
        return [];
      }
    }

    // ── Table ─────────────────────────────────────────────────────────────────
    function populateTable(customers) {
      customersTableBody.innerHTML = '';
      const isSignalsVisible = showSignalsToggle.checked;

      if (!customers.length) {
        let cols = isDobColumnVisible ? 7 : 6;
        if (isSignalsVisible) cols += 1;
        customersTableBody.innerHTML = `<tr><td colspan="${cols}" style="text-align:center; color:var(--muted);">No customers yet</td></tr>`;
        customersTable.style.display = 'table';
        return;
      }

      customers.forEach(c => {
        const row = document.createElement('tr');
        row.dataset.customerId = c.id;

        let html = `<td>${escapeHtml(c.full_name)}</td>`;
        if (isDobColumnVisible) html += `<td>${escapeHtml(c.date_of_birth || '-')}</td>`;
        html += `
          <td>${escapeHtml(formatGender(c.gender))}</td>
          <td>${escapeHtml(c.email || '-')}</td>
          <td>${escapeHtml(c.mobile || '-')}</td>
          <td>${escapeHtml(formatDateTime(c.date_added || c.created_at || '-'))}</td>
          <td class="signals-cell" data-id="${escapeHtml(String(c.id))}" style="${isSignalsVisible ? '' : 'display:none;'}">
            <span style="color:var(--muted); font-size:0.8rem;">…</span>
          </td>
          <td>
            <div class="action-group">
              <button type="button" class="customer-edit-btn">Edit</button>
              <button type="button" class="info-btn customer-info-btn">Info</button>
            </div>
          </td>
        `;
        row.innerHTML = html;

        row.querySelector('.customer-edit-btn').addEventListener('click', () => openCustomerModal('edit', c));
        row.querySelector('.customer-info-btn').addEventListener('click', () => openMetaModal(c));

        customersTableBody.appendChild(row);
      });

      customersTable.style.display = 'table';

      // Fetch signals asynchronously for visible rows if the column is turned on
      if (isSignalsVisible) {
        customers.forEach(c => {
          fetchSignals(c.id).then(signals => {
            const cell = customersTableBody.querySelector(`.signals-cell[data-id="${c.id}"]`);
            if (cell) cell.innerHTML = `<div class="signal-pills">${buildPillsHtml(signals)}</div>`;
          });
        });
      }
    }

    // ── Pagination ────────────────────────────────────────────────────────────
    function buildPagination(total) {
      const totalPages = Math.max(1, Math.ceil(total / pageSize));
      customersPagination.innerHTML = `
        <span style="color:var(--muted);">Page ${currentPage} of ${totalPages}</span>
        <span style="color:var(--muted);">${escapeHtml(sortLabels[sortSelect.value] || 'Full name')}</span>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-left:auto;">
          <button type="button" id="prevPageBtn" ${currentPage <= 1 ? 'disabled' : ''}>Previous</button>
          <button type="button" id="nextPageBtn" ${currentPage >= totalPages ? 'disabled' : ''}>Next</button>
        </div>`;

      document.getElementById('prevPageBtn')?.addEventListener('click', () => { if (currentPage > 1) { currentPage--; loadCustomers(currentSearch, currentPage); } });
      document.getElementById('nextPageBtn')?.addEventListener('click', () => { if (currentPage < totalPages) { currentPage++; loadCustomers(currentSearch, currentPage); } });
    }

    async function loadCustomers(query = '', page = 1) {
      try {
        customersLoading.style.display = 'block';
        customersTable.style.display   = 'none';
        currentSearch = query;
        currentPage   = Math.max(1, page);

        const params = new URLSearchParams({ action:'list', q:query, page:String(currentPage), limit:String(pageSize), sort:sortSelect?.value || 'full_name' });
        const resp = await fetch(`customers_api.php?${params}`);
        if (!resp.ok) throw new Error('Failed to load customers');
        const json = await resp.json();
        const customers = json.customers || [];
        const total     = Number(json.total || 0);

        populateTable(customers);
        buildPagination(total);

        if (!total) {
          resultCount.textContent = query ? 'No customers found for current search.' : 'No customers found.';
        } else {
          const s = (currentPage - 1) * pageSize + 1;
          const e = Math.min(currentPage * pageSize, total);
          resultCount.textContent = query ? `Showing ${s}–${e} of ${total} matching customers` : `Showing ${s}–${e} of ${total} customers`;
        }
      } catch (err) {
        showAlert(alertContainer, 'Error: ' + err.message, 'error');
        resultCount.textContent = 'Failed to load customers.';
        customersPagination.innerHTML = '';
      } finally {
        customersLoading.style.display = 'none';
      }
    }

    // ── Edit modal ────────────────────────────────────────────────────────────
    function getFormData() {
      const data = {};
      customerForm.querySelectorAll('input, select').forEach(f => { data[f.name] = f.value; });
      return data;
    }

    function resetForm() {
      customerForm.querySelectorAll('input, select').forEach(f => f.value = '');
      adminOverrideCheckbox.checked = false;
      overrideSection.style.display = 'none';
      modalAlert.innerHTML = '';
      currentCustomerMode      = 'create';
      currentEditingCustomerId = null;
      modalTitle.textContent   = 'Create New Customer';
      submitButton.textContent = 'Create Customer';
    }

    function openCustomerModal(mode = 'create', customer = null) {
      resetForm();
      currentCustomerMode      = mode;
      currentEditingCustomerId = mode === 'edit' && customer ? Number(customer.id || 0) : null;
      modalTitle.textContent   = mode === 'edit' ? 'Edit Customer' : 'Create New Customer';
      submitButton.textContent = mode === 'edit' ? 'Save Customer' : 'Create Customer';

      if (mode === 'edit' && customer) {
        const n = splitFullName(customer.full_name);
        setFieldValueById('firstName',   n.firstName);
        setFieldValueById('middleName',  n.middleName);
        setFieldValueById('lastName',    n.lastName);
        setFieldValueById('dateOfBirth', customer.date_of_birth || '');
        setFieldValueById('gender',      customer.gender || '');
        setFieldValueById('email',       customer.email || '');
        setFieldValueById('mobile',      customer.mobile || '');
      }
      customerModal.showModal();
    }

    async function submitCustomer() {
      const data = getFormData();
      const isEdit = currentCustomerMode === 'edit' && currentEditingCustomerId;
      try {
        const resp = await fetch(`customers_api.php?action=${isEdit ? 'update' : 'create'}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ...data, customer_id: isEdit ? currentEditingCustomerId : undefined, admin_override: adminOverrideCheckbox.checked })
        });
        const json = await resp.json();
        if (!resp.ok) {
          if (json.error === 'missing_required_fields' && !adminOverrideCheckbox.checked) {
            showAlert(modalAlert, 'Required fields are missing. Check the option below to override.', 'error');
            overrideSection.style.display = 'block';
            return;
          }
          if (json.errors) { showAlert(modalAlert, Object.values(json.errors).join('\n'), 'error'); return; }
          showAlert(modalAlert, json.error || 'Error saving customer', 'error');
          return;
        }
        // Bust signal cache for this customer since data may have changed
        if (isEdit) delete signalCache[currentEditingCustomerId];
        showAlert(alertContainer, isEdit ? 'Customer updated successfully!' : 'Customer created successfully!', 'success');
        resetForm();
        customerModal.close();
        loadCustomers(searchInput.value.trim());
      } catch (err) {
        showAlert(modalAlert, 'Error: ' + err.message, 'error');
      }
    }

    // ── Meta / signals modal ──────────────────────────────────────────────────
    function metaValue(val) {
      const v = String(val ?? '').trim();
      return v ? `<span class="meta-value">${escapeHtml(v)}</span>` : `<span class="meta-value empty">Not recorded</span>`;
    }

    const SIGNAL_TYPE_LABELS = {
      shared_email:  'Shared email address',
      shared_mobile: 'Shared mobile number',
      shared_ip:     'Shared IP address',
      shared_device: 'Shared device fingerprint',
    };

    const SIGNAL_SEVERITY_ICONS = { green: '🟢', yellow: '🟡', red: '🔴' };

    function renderMetaModalContent(customer, signals) {
      const rows = [
        { label: 'Full name',    value: customer.full_name,      span: false },
        { label: 'Email',        value: customer.email,          span: false },
        { label: 'Mobile',       value: customer.mobile,         span: false },
        { label: 'Gender',       value: formatGender(customer.gender), span: false },
        { label: 'Date of birth',value: customer.date_of_birth,  span: false },
        { label: 'Date added',   value: formatDateTime(customer.date_added), span: false },
        { label: 'Last seen',    value: formatDateTime(customer.last_seen_at), span: false },
        { label: 'IP address',   value: customer.last_ip,        span: false },
        { label: 'Timezone',     value: customer.last_timezone,  span: false },
        { label: 'Screen',       value: customer.last_screen,    span: false },
        { label: 'Locale',       value: customer.last_locale,    span: false },
        { label: 'User agent',   value: customer.last_user_agent, span: true },
      ];

      const metaHtml = `
        <div class="meta-grid">
          ${rows.map(r => `
            <div class="meta-item${r.span ? ' span-2' : ''}">
              <div class="meta-label">${escapeHtml(r.label)}</div>
              ${metaValue(r.value)}
            </div>`).join('')}
        </div>`;

      let signalsHtml = '';
      if (!signals.length) {
        signalsHtml = `<p class="no-signals">No duplicate signals detected for this customer.</p>`;
      } else {
        signalsHtml = `<div class="signals-section">` +
          signals.map(s => `
            <div class="signal-row ${s.severity}">
              <div class="signal-icon">${SIGNAL_SEVERITY_ICONS[s.severity] || '⚪'}</div>
              <div class="signal-body">
                <div class="signal-title">${escapeHtml(SIGNAL_TYPE_LABELS[s.type] || s.label)} — ${s.count} customer${s.count !== 1 ? 's' : ''} share this</div>
                <div class="signal-detail">${escapeHtml(s.value)}</div>
              </div>
            </div>`).join('') +
          `</div>`;
      }

      return `
        <div style="display:grid; gap:18px;">
          <div>
            <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); margin-bottom:10px;">Session & contact metadata</div>
            ${metaHtml}
          </div>
          <div>
            <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); margin-bottom:10px;">Duplicate signals</div>
            ${signalsHtml}
          </div>
        </div>`;
    }

    async function openMetaModal(customer) {
      metaModalTitle.textContent = `Info — ${customer.full_name || 'Customer'}`;
      metaModalBody.innerHTML = `<div style="text-align:center; color:var(--muted); padding:20px;">Loading…</div>`;
      metaModal.showModal();

      try {
        const [metaResp, sigResp] = await Promise.all([
          fetch(`customers_api.php?action=meta&id=${customer.id}`),
          fetch(`customers_api.php?action=signals&id=${customer.id}`)
        ]);

        const metaJson = metaResp.ok ? await metaResp.json() : {};
        const sigJson  = sigResp.ok  ? await sigResp.json()  : {};

        const fullCustomer = metaJson.customer || customer;
        const signals      = sigJson.signals   || [];

        // Update cache too
        signalCache[customer.id] = signals;

        metaModalBody.innerHTML = renderMetaModalContent(fullCustomer, signals);
      } catch (err) {
        metaModalBody.innerHTML = `<div class="alert error">Failed to load customer info: ${escapeHtml(err.message)}</div>`;
      }
    }

    // ── Event wiring ──────────────────────────────────────────────────────────
    addCustomerBtn.addEventListener('click', () => openCustomerModal('create'));
    cancelButton.addEventListener('click',   () => customerModal.close());
    submitButton.addEventListener('click',   submitCustomer);
    customerModal.addEventListener('close',  resetForm);
    metaModalClose.addEventListener('click',       () => metaModal.close());
    metaModalCloseFooter.addEventListener('click', () => metaModal.close());

    searchBtn.addEventListener('click', () => loadCustomers(searchInput.value.trim(), 1));
    clearBtn.addEventListener('click',  () => { searchInput.value = ''; loadCustomers('', 1); });
    sortSelect?.addEventListener('change', () => loadCustomers(searchInput.value.trim(), 1));
    searchInput.addEventListener('keypress', e => { if (e.key === 'Enter') loadCustomers(searchInput.value.trim(), 1); });

    // Handle toggle switch behavior explicitly
    showSignalsToggle.addEventListener('change', () => {
      if (showSignalsToggle.checked) {
        thSignals.style.display = '';
      } else {
        thSignals.style.display = 'none';
      }
      // Re-render the existing active layout context immediately
      loadCustomers(searchInput.value.trim(), currentPage);
    });

    loadCustomers('', 1);
  </script>
</body>
</html>