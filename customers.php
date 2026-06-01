<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();
require_login();

$user = $_SESSION['user'] ?? [];
$role = (string) ($user['role'] ?? '');

// Only admins can access this page
if ($role !== 'admin') {
    header('Location: admin.php');
    exit;
}

$settings = get_customer_field_settings();

// FIX: Moved the layout rendering helper safely into your PHP code processing block
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
      background: transparent;
      color: var(--text);
      border-bottom: 1px solid var(--panel-border);
    }

    .modal-card__head h2 {
      margin: 0;
      font-size: 1.15rem;
      letter-spacing: -0.02em;
    }

    .modal-card__body {
      padding: 16px 18px 18px;
      display: grid;
      gap: 12px;
    }

    #customerForm {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    #customerForm .field {
      min-width: 0;
    }

    @media (max-width: 640px) {
      #customerForm {
        grid-template-columns: 1fr;
      }
    }

    .field label {
      display: block;
      font-size: 0.8rem;
      font-weight: 700;
      margin-bottom: 4px;
      color: var(--label);
    }

    .field input,
    .field textarea {
      width: 100%;
      border: 1px solid rgba(15, 23, 42, 0.15);
      border-radius: 14px;
      padding: 10px 12px;
      font: inherit;
      color: var(--text);
      background: var(--panel);
      box-sizing: border-box;
      font-size: 0.92rem;
    }

    .field input:focus,
    .field textarea:focus {
      outline: 3px solid rgba(36, 87, 255, 0.14);
      border-color: rgba(36, 87, 255, 0.5);
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
      background: rgba(226, 232, 240, 0.9);
      color: var(--text);
      font: inherit;
      font-weight: 700;
      cursor: pointer;
    }

    .modal-card__footer button.primary {
      background: var(--accent);
      color: #fff;
    }

    .customers-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.95rem;
    }

    .customers-table thead {
      background: rgba(15, 23, 42, 0.04);
    }

    .customers-table th {
      padding: 12px;
      text-align: left;
      font-weight: 700;
      color: var(--text);
      border-bottom: 2px solid var(--panel-border);
    }

    .customers-table td {
      padding: 12px;
      border-bottom: 1px solid var(--panel-border);
    }

    .customers-table tbody tr:hover {
      background: rgba(36, 87, 255, 0.03);
    }

    .alert {
      padding: 14px 16px;
      border-radius: 16px;
      margin-bottom: 16px;
    }

    .alert.info {
      background: rgba(36, 87, 255, 0.08);
      border: 1px solid rgba(36, 87, 255, 0.2);
      color: var(--text);
    }

    .alert.error {
      background: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.2);
      color: #7f1d1d;
    }

    .alert.success {
      background: rgba(16, 185, 129, 0.08);
      border: 1px solid rgba(16, 185, 129, 0.2);
      color: #065f46;
    }

    .override-note {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-left: 3px solid rgba(245, 158, 11, 0.6);
      padding: 12px 14px;
      border-radius: 16px;
      margin: 10px 0;
      font-size: 0.9rem;
      color: var(--text);
    }

    html[data-theme="dark"] .modal-dialog {
      box-shadow: 0 28px 80px rgba(0, 0, 0, 0.32);
    }

    html[data-theme="dark"] .modal-card__footer button {
      background: rgba(30, 50, 80, 0.6);
      color: var(--text);
    }

    html[data-theme="dark"] .modal-card__footer button.primary {
      background: var(--accent);
      color: #fff;
    }

    html[data-theme="dark"] .override-note {
      background: rgba(20, 40, 70, 0.45);
    }

    html[data-theme="dark"] .field input,
    html[data-theme="dark"] .field textarea {
      background: rgba(15, 23, 42, 0.5);
      border-color: rgba(226, 232, 240, 0.12);
    }
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
      <div class="panel-header">
        <small>Search & Filter Options</small>
      </div>
      <div class="panel-body">
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
          <input type="text" id="searchInput" placeholder="Search by name, email, or mobile..." style="flex:1; min-width:200px; margin-bottom:0;" />
          <select id="sortSelect" style="min-width: 220px; width:auto; margin-bottom:0;">
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
        <div id="resultCount" style="color: var(--muted); font-size: 0.9rem; margin-top: 12px;">Loading...</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <small>Customer Registry</small>
      </div>
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
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="customersTableBody">
          </tbody>
        </table>
        <div id="customersPagination" style="display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap; margin-top: 14px;"></div>
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
            <p style="margin: 6px 0 0;">Some required fields are missing. As an admin, you can create this customer anyway. This option is not available to regular users.</p>
          </div>
          <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" id="adminOverrideCheckbox" style="margin-bottom:0;" />
            Yes, create this customer
          </label>
        </div>
      </div>
      <div class="modal-card__footer">
        <button type="button" id="cancelButton" class="secondary">Cancel</button>
        <button type="button" id="submitButton" class="primary">Create Customer</button>
      </div>
    </div>
  </dialog>

  <script src="assets/js/global.js"></script>
  <script>
    const customerModal = document.getElementById('customerModal');
    const addCustomerBtn = document.getElementById('addCustomerBtn');
    const cancelButton = document.getElementById('cancelButton');
    const submitButton = document.getElementById('submitButton');
    const modalTitle = document.getElementById('modalTitle');
    const customerForm = document.getElementById('customerForm');
    const overrideSection = document.getElementById('overrideSection');
    const adminOverrideCheckbox = document.getElementById('adminOverrideCheckbox');
    const searchInput = document.getElementById('searchInput');
    const searchBtn = document.getElementById('searchBtn');
    const clearBtn = document.getElementById('clearBtn');
    const sortSelect = document.getElementById('sortSelect');
    const resultCount = document.getElementById('resultCount');
    const customersTable = document.getElementById('customersTable');
    const customersTableBody = document.getElementById('customersTableBody');
    const customersLoading = document.getElementById('customersLoading');
    const alertContainer = document.getElementById('alertContainer');
    const modalAlert = document.getElementById('modalAlert');
    const customersPagination = document.getElementById('customersPagination');

    const settings = <?php echo json_encode($settings); ?>;
    const pageSize = 20;
    let currentPage = 1;
    let currentSearch = '';
    let currentCustomerMode = 'create';
    let currentEditingCustomerId = null;

    const sortLabels = {
      full_name: 'Full name',
      first_name: 'First name',
      last_name: 'Last name',
      mobile: 'Mobile',
      email: 'Email',
      date_added_desc: 'Date added (newest)',
      date_added_asc: 'Date added (oldest)',
      gender: 'Gender'
    };

    function showAlert(container, message, type = 'info') {
      const alert = document.createElement('div');
      alert.className = `alert ${type}`;
      alert.textContent = message;
      container.innerHTML = '';
      container.appendChild(alert);
    }

    // Safely reads visibility metrics to avoid JavaScript array length calculation mismatches
    const isDobColumnVisible = Boolean(settings.date_of_birth && settings.date_of_birth.display);

    function getFormData() {
      const data = {};
      const fields = customerForm.querySelectorAll('input, select');
      fields.forEach(field => {
        data[field.name] = field.value;
      });
      return data;
    }

    function resetForm() {
      customerForm.querySelectorAll('input, select').forEach(field => field.value = '');
      adminOverrideCheckbox.checked = false;
      overrideSection.style.display = 'none';
      modalAlert.innerHTML = '';
      currentCustomerMode = 'create';
      currentEditingCustomerId = null;
      if (modalTitle) {
        modalTitle.textContent = 'Create New Customer';
      }
      submitButton.textContent = 'Create Customer';
    }

    function buildPagination(total) {
      if (!customersPagination) {
        return;
      }

      const totalPages = Math.max(1, Math.ceil(total / pageSize));
      const buttons = [];

      buttons.push(`<span style="color:var(--muted);">Page ${currentPage} of ${totalPages}</span>`);
      buttons.push(`<span style="color:var(--muted);">${sortLabels[sortSelect.value] || 'Full name'}</span>`);
      buttons.push(`<div style="display:flex; gap:8px; flex-wrap:wrap; margin-left:auto;">`);
      buttons.push(`<button type="button" id="prevPageBtn" ${currentPage <= 1 ? 'disabled' : ''}>Previous</button>`);
      buttons.push(`<button type="button" id="nextPageBtn" ${currentPage >= totalPages ? 'disabled' : ''}>Next</button>`);
      buttons.push(`</div>`);

      customersPagination.innerHTML = buttons.join('');

      const prevPageBtn = document.getElementById('prevPageBtn');
      const nextPageBtn = document.getElementById('nextPageBtn');
      if (prevPageBtn) {
        prevPageBtn.addEventListener('click', () => {
          if (currentPage > 1) {
            currentPage -= 1;
            loadCustomers(currentSearch, currentPage);
          }
        });
      }
      if (nextPageBtn) {
        nextPageBtn.addEventListener('click', () => {
          if (currentPage < totalPages) {
            currentPage += 1;
            loadCustomers(currentSearch, currentPage);
          }
        });
      }
    }

    async function loadCustomers(query = '', page = 1) {
      try {
        customersLoading.style.display = 'block';
        customersTable.style.display = 'none';

        currentSearch = query;
        currentPage = Math.max(1, page);

        const params = new URLSearchParams({
          action: 'list',
          q: query,
          page: String(currentPage),
          limit: String(pageSize),
          sort: sortSelect ? sortSelect.value : 'full_name'
        });
        const resp = await fetch(`customers_api.php?${params}`);
        if (!resp.ok) throw new Error('Failed to load customers');
        const json = await resp.json();
        const customers = json.customers || [];
        const total = Number(json.total || 0);
        populateTable(customers);
        buildPagination(total);

        if (total === 0) {
          resultCount.textContent = query ? 'No customers found for current search.' : 'No customers found.';
        } else {
          const startRow = ((currentPage - 1) * pageSize) + 1;
          const endRow = Math.min(currentPage * pageSize, total);
          resultCount.textContent = query
            ? `Showing ${startRow}-${endRow} of ${total} matching customers`
            : `Showing ${startRow}-${endRow} of ${total} customers`;
        }
      } catch (err) {
        showAlert(alertContainer, 'Error: ' + err.message, 'error');
        resultCount.textContent = 'Failed to load customers.';
        if (customersPagination) {
          customersPagination.innerHTML = '';
        }
      } finally {
        customersLoading.style.display = 'none';
      }
    }

    function populateTable(customers) {
      customersTableBody.innerHTML = '';
      if (customers.length === 0) {
        // Calculate dynamic colspan based on whether DOB is enabled
        const activeColspan = isDobColumnVisible ? 7 : 6;
        customersTableBody.innerHTML = `<tr><td colspan="${activeColspan}" style="text-align:center; color:var(--muted);">No customers yet</td></tr>`;
        customersTable.style.display = 'table';
        return;
      }

      customers.forEach(c => {
        const row = document.createElement('tr');
        
        // FIX: Build cell tracks sequentially to respect the display settings config variables
        let rowHtml = `<td>${escapeHtml(c.full_name)}</td>`;
        if (isDobColumnVisible) {
          rowHtml += `<td>${escapeHtml(c.date_of_birth || '-')}</td>`;
        }
        rowHtml += `
          <td>${escapeHtml(formatGender(c.gender))}</td>
          <td>${escapeHtml(c.email || '-')}</td>
          <td>${escapeHtml(c.mobile || '-')}</td>
          <td>${escapeHtml(formatDateTime(c.date_added || c.created_at || '-'))}</td>
          <td><button type="button" class="secondary customer-edit-btn" style="padding:4px 8px; font-size:0.85rem; margin-bottom:0;">Edit</button></td>
        `;
        
        row.innerHTML = rowHtml;
        const editButton = row.querySelector('.customer-edit-btn');
        if (editButton) {
          editButton.addEventListener('click', () => openCustomerModal('edit', c));
        }
        customersTableBody.appendChild(row);
      });
      customersTable.style.display = 'table';
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
      if (Number.isNaN(parsed.getTime())) {
        return raw;
      }
      return parsed.toLocaleString();
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    function splitFullName(fullName) {
      const parts = String(fullName || '').trim().split(/\s+/).filter(Boolean);
      if (parts.length === 0) {
        return { firstName: '', middleName: '', lastName: '' };
      }
      if (parts.length === 1) {
        return { firstName: parts[0], middleName: '', lastName: '' };
      }
      if (parts.length === 2) {
        return { firstName: parts[0], middleName: '', lastName: parts[1] };
      }
      return {
        firstName: parts[0],
        middleName: parts.slice(1, -1).join(' '),
        lastName: parts[parts.length - 1]
      };
    }

    function setFieldValueById(id, value) {
      const field = document.getElementById(id);
      if (field) {
        field.value = value ?? '';
      }
    }

    function setCustomerModalMode(mode, customer = null) {
      currentCustomerMode = mode;
      currentEditingCustomerId = mode === 'edit' && customer ? Number(customer.id || 0) : null;
      if (modalTitle) {
        modalTitle.textContent = mode === 'edit' ? 'Edit Customer' : 'Create New Customer';
      }
      submitButton.textContent = mode === 'edit' ? 'Save Customer' : 'Create Customer';

      if (mode === 'edit' && customer) {
        const nameParts = splitFullName(customer.full_name);
        setFieldValueById('firstName', nameParts.firstName);
        setFieldValueById('middleName', nameParts.middleName);
        setFieldValueById('lastName', nameParts.lastName);
        setFieldValueById('dateOfBirth', customer.date_of_birth || '');
        setFieldValueById('gender', customer.gender || '');
        setFieldValueById('email', customer.email || '');
        setFieldValueById('mobile', customer.mobile || '');
      }
    }

    function openCustomerModal(mode = 'create', customer = null) {
      resetForm();
      setCustomerModalMode(mode, customer);
      customerModal.showModal();
    }

    async function submitCustomer() {
      const data = getFormData();
      const adminOverride = adminOverrideCheckbox.checked;
      const isEditMode = currentCustomerMode === 'edit' && currentEditingCustomerId;

      try {
        const resp = await fetch(`customers_api.php?action=${isEditMode ? 'update' : 'create'}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            ...data,
            customer_id: isEditMode ? currentEditingCustomerId : undefined,
            admin_override: adminOverride
          })
        });

        const json = await resp.json();

        if (!resp.ok) {
          if (json.error === 'missing_required_fields' && !adminOverride) {
            showAlert(modalAlert, 'Required fields are missing. Check the option below if you want to override.', 'error');
            overrideSection.style.display = 'block';
            return;
          }

          if (json.error === 'validation_error' || json.errors) {
            const errorLines = Object.values(json.errors || {}).join('\n');
            showAlert(modalAlert, errorLines, 'error');
            return;
          }

          showAlert(modalAlert, json.error || (isEditMode ? 'Error updating customer' : 'Error creating customer'), 'error');
          return;
        }

        showAlert(alertContainer, isEditMode ? 'Customer updated successfully!' : 'Customer created successfully!', 'success');
        resetForm();
        customerModal.close();
        loadCustomers(searchInput.value.trim());
      } catch (err) {
        showAlert(modalAlert, 'Error: ' + err.message, 'error');
      }
    }

    addCustomerBtn.addEventListener('click', () => {
      openCustomerModal('create');
    });

    cancelButton.addEventListener('click', () => {
      customerModal.close();
    });

    submitButton.addEventListener('click', submitCustomer);

    customerModal.addEventListener('close', () => {
      resetForm();
    });

    searchBtn.addEventListener('click', () => {
      loadCustomers(searchInput.value.trim(), 1);
    });

    clearBtn.addEventListener('click', () => {
      searchInput.value = '';
      currentSearch = '';
      loadCustomers('', 1);
    });

    if (sortSelect) {
      sortSelect.addEventListener('change', () => {
        loadCustomers(searchInput.value.trim(), 1);
      });
    }

    searchInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        loadCustomers(searchInput.value.trim(), 1);
      }
    });

    loadCustomers('', 1);
  </script>
</body>
</html>