<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
init_session();
require_login();

$user = $_SESSION['user'] ?? [];
$role = (string) ($user['role'] ?? '');
$emailConfigured = email_is_configured();

// Only admins can access this page
if ($role !== 'admin') {
    header('Location: admin.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Users - <?php echo htmlspecialchars(APP_NAME); ?></title>
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
      --label: #0f172a;
      --accent: #2457ff;
      --accent-2: #10b981;
      --shadow: 0 24px 70px rgba(15, 23, 42, 0.12);
      --input-bg: #ffffff;
      --input-text: #0f172a;
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #0b0f19;
        --panel: rgba(21, 31, 58, 0.88);
        --panel-border: rgba(255, 255, 255, 0.08);
        --text: #f8fafc;
        --muted: #94a3b8;
        --label: #f8fafc;
        --accent: #3b82f6;
        --accent-2: #10b981;
        --shadow: 0 24px 70px rgba(0, 0, 0, 0.4);
        --input-bg: #1e293b;
        --input-text: #f8fafc;
      }
    }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(36, 87, 255, 0.14), transparent 35%),
        radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 28%),
        linear-gradient(180deg, var(--bg) 0%, var(--bg) 100%);
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

    .toolbar a, .toolbar button {
      border: 1px solid var(--panel-border);
      background: var(--panel);
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

    .search-box {
      display: flex;
      gap: 10px;
      margin-bottom: 18px;
      flex-wrap: wrap;
    }

    .search-box input {
      flex: 1;
      min-width: 200px;
      border: 1px solid var(--panel-border);
      border-radius: 16px;
      padding: 13px 14px;
      font: inherit;
      color: var(--input-text) !important;
      background: var(--input-bg) !important;
    }

    .search-box input:focus {
      outline: 3px solid rgba(36, 87, 255, 0.14);
      border-color: rgba(36, 87, 255, 0.5);
    }

    .search-box button {
      border: 1px solid var(--panel-border);
      background: var(--panel);
      color: var(--text);
      border-radius: 999px;
      padding: 10px 14px;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
    }

    .users-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.95rem;
    }

    .users-table thead {
      background: rgba(15, 23, 42, 0.04);
    }

    .users-table th {
      padding: 12px;
      text-align: left;
      font-weight: 700;
      color: var(--text);
      border-bottom: 2px solid var(--panel-border);
    }

    .users-table td {
      padding: 12px;
      border-bottom: 1px solid var(--panel-border);
    }

    .users-table tbody tr:hover {
      background: rgba(36, 87, 255, 0.03);
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 600;
    }

    .status-badge.active {
      background: rgba(16, 185, 129, 0.12);
      color: #065f46;
    }

    .status-badge.inactive {
      background: rgba(239, 68, 68, 0.12);
      color: #7f1d1d;
    }

    .role-badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 600;
      background: rgba(36, 87, 255, 0.12);
      color: #1e3a8a;
    }

    .pagination {
      display: flex;
      gap: 8px;
      justify-content: center;
      margin-top: 18px;
      flex-wrap: wrap;
    }

    .pagination button {
      border: 1px solid var(--panel-border);
      background: var(--panel);
      color: var(--text);
      border-radius: 6px;
      padding: 8px 12px;
      font: inherit;
      font-weight: 600;
      cursor: pointer;
      font-size: 0.9rem;
    }

    .pagination button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .pagination button.active {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    .info-text {
      text-align: center;
      color: var(--muted);
      padding: 20px;
    }

    .search-panel {
      display: grid;
      gap: 12px;
    }

    .search-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
    }

    .search-row input {
      flex: 1;
      min-width: 220px;
      border: 1px solid var(--panel-border);
      border-radius: 16px;
      padding: 13px 14px;
      font: inherit;
      color: var(--input-text) !important;
      background: var(--input-bg) !important;
      box-sizing: border-box;
    }

    .search-row input::placeholder {
      color: var(--muted);
    }

    .search-row input:focus {
      outline: 3px solid rgba(36, 87, 255, 0.14);
      border-color: rgba(36, 87, 255, 0.5);
    }

    .search-row button {
      border: 1px solid var(--panel-border);
      background: var(--panel);
      color: var(--text);
      border-radius: 999px;
      padding: 10px 14px;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
    }

    .admin-table .actions-cell {
      white-space: nowrap;
    }

    .action-btn {
      border: 1px solid var(--panel-border);
      background: var(--panel);
      color: var(--text);
      border-radius: 999px;
      padding: 8px 12px;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      margin-right: 8px;
    }

    .action-btn.primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    .user-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 600;
    }

    .user-badge.active {
      background: rgba(16, 185, 129, 0.12);
      color: #10b981;
    }

    .user-badge.disabled {
      background: rgba(239, 68, 68, 0.12);
      color: #ef4444;
    }

    .modal-dialog {
      width: min(720px, calc(100vw - 24px));
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
      background: rgba(15, 23, 42, 0.6);
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
      margin: 0;
    }

    .modal-card__head {
      padding: 24px 24px 16px;
      border-bottom: 1px solid var(--panel-border);
    }

    .modal-card__head-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
    }

    .modal-card__head h2 {
      margin: 0;
      font-size: 1.5rem;
      letter-spacing: -0.02em;
      color: var(--text);
    }

    .modal-card__close {
      border: 0;
      background: transparent;
      color: var(--muted);
      font-size: 1.8rem;
      line-height: 1;
      cursor: pointer;
      padding: 0;
      margin: 0;
    }
    
    .modal-card__close:hover {
      color: var(--text);
      background: transparent;
    }

    .modal-card__body {
      padding: 24px;
    }

    #userFormFields {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    #userFormFields .field.full-width {
      grid-column: 1 / -1;
    }

    @media (max-width: 700px) {
      #userFormFields {
        grid-template-columns: 1fr;
      }
    }

    .field label {
      display: block;
      font-size: 0.85rem;
      font-weight: 700;
      margin-bottom: 6px;
      color: var(--label);
    }

    .field input,
    .field select {
      width: 100%;
      border: 1px solid var(--panel-border);
      border-radius: 14px;
      padding: 12px;
      font: inherit;
      color: var(--input-text) !important;
      background: var(--input-bg) !important;
      box-sizing: border-box;
      font-size: 0.95rem;
      margin-bottom: 0;
    }

    .field input::placeholder {
      color: var(--muted);
    }

    .field input:focus,
    .field select:focus {
      outline: 3px solid rgba(36, 87, 255, 0.24);
      border-color: var(--accent);
    }

    .modal-card__footer {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      padding: 0 24px 24px;
    }

    .modal-card__footer button {
      border: 1px solid var(--panel-border);
      border-radius: 999px;
      padding: 10px 20px;
      background: rgba(226, 232, 240, 0.9);
      color: #0f172a;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
    }

    .modal-card__footer button.primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
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
      color: #ef4444;
    }

    .alert.success {
      background: rgba(16, 185, 129, 0.08);
      border: 1px solid rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
  </style>
</head>
<body>
  <?php $current_page = 'users.php'; include __DIR__ . '/assets/nav.html'; ?>
  
  <div class="admin-container">
    <div class="admin-header">
      <div>
        <h1>User Management</h1>
        <p>View and manage admin and provider users.</p>
        <?php if (!$emailConfigured): ?>
          <div class="alert error" style="margin: 14px 0 0;">
            New user creation is disabled until email is configured, because new accounts are created through a password reset invitation.
          </div>
        <?php endif; ?>
      </div>
      <div class="toolbar">
        <button type="button" id="addUserBtn" <?php echo $emailConfigured ? '' : 'disabled'; ?> title="<?php echo $emailConfigured ? 'Create a new user' : 'Configure email to enable new user creation'; ?>">+ Add User</button>
      </div>
    </div>

    <div id="alertContainer"></div>

    <div class="admin-panel">
      <h2>Search Users</h2>
      <div class="search-panel">
        <div class="search-row">
          <input type="text" id="searchInput" placeholder="Search by name, email, phone, or role..." />
          <button type="button" id="searchBtn">Search</button>
          <button type="button" id="clearBtn" class="secondary">Clear</button>
        </div>
        <div id="resultCount" style="color: var(--muted); font-size: 0.9rem;">Loading...</div>
      </div>
    </div>

    <div class="admin-panel">
      <h2>Users</h2>
      <div id="usersLoading" style="text-align:center; color:var(--muted); padding:20px;">Loading users...</div>
      <table class="admin-table" id="usersTable" style="display:none;">
        <thead>
          <tr>
            <th>Full Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Role</th>
            <th>Status</th>
            <th>Bookable</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="usersTableBody">
        </tbody>
      </table>
      <div id="paginationContainer" style="margin-top: 20px; display: flex; gap: 8px; flex-wrap: wrap; justify-content: center;"></div>
    </div>
  </div>

 <dialog id="userModal" class="modal-dialog">
    <form id="userForm" class="modal-card">
      <div class="modal-card__head">
        <div class="modal-card__head-row">
          <h2 id="modalTitle">Create New User</h2>
          <button type="button" id="closeUserModalTop" class="modal-card__close" aria-label="Close modal">&times;</button>
        </div>
        <p id="modalSubtitle" style="margin: 0; color: var(--muted); font-size: 0.95rem; line-height: 1.4;">Fill in the required user details. A password reset link will be generated after creation.</p>
      </div>
      
      <div class="modal-card__body">
        <div id="modalAlert"></div>

        <div id="userFormFields">
          <input type="hidden" id="userId" value="">

          <div class="field">
            <label for="firstName">First Name</label>
            <input id="firstName" name="first_name" type="text" placeholder="John" required />
          </div>

          <div class="field">
            <label for="lastName">Last Name</label>
            <input id="lastName" name="last_name" type="text" placeholder="Smith" required />
          </div>

          <div class="field full-width">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" placeholder="john@example.com" required />
          </div>

          <div class="field">
            <label for="phone">Phone</label>
            <input id="phone" name="phone" type="tel" placeholder="(555) 123-4567" required />
          </div>

          <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role" required>
              <option value="admin">Admin</option>
              <option value="provider">Provider</option>
            </select>
          </div>

          <div class="field full-width">
            <label for="isActive">Status</label>
            <select id="isActive" name="is_active" required>
              <option value="1">Active</option>
              <option value="0">Disabled</option>
            </select>
          </div>

          <div class="field full-width">
            <label for="isBookable">Bookable</label>
            <select id="isBookable" name="bookable" required>
              <option value="1">Bookable</option>
              <option value="0">Not bookable</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-card__footer">
        <button type="button" id="cancelUserBtn">Cancel</button>
        <button type="submit" id="saveUserBtn" class="primary">Create User</button>
      </div>
    </form>
  </dialog>

  <script src="assets/js/global.js"></script>
  <script>
    const usersTable = document.getElementById('usersTable');
    const usersTableBody = document.getElementById('usersTableBody');
    const usersLoading = document.getElementById('usersLoading');
    const resultCount = document.getElementById('resultCount');
    const paginationContainer = document.getElementById('paginationContainer');
    const alertContainer = document.getElementById('alertContainer');
    const searchInput = document.getElementById('searchInput');
    const searchBtn = document.getElementById('searchBtn');
    const clearBtn = document.getElementById('clearBtn');
    const addUserBtn = document.getElementById('addUserBtn');
    const userModal = document.getElementById('userModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalSubtitle = document.getElementById('modalSubtitle');
    const modalAlert = document.getElementById('modalAlert');
    const userForm = document.getElementById('userForm');
    const userIdInput = document.getElementById('userId');
    const firstNameInput = document.getElementById('firstName');
    const lastNameInput = document.getElementById('lastName');
    const emailInput = document.getElementById('email');
    const phoneInput = document.getElementById('phone');
    const roleInput = document.getElementById('role');
    const isActiveInput = document.getElementById('isActive');
    const isBookableInput = document.getElementById('isBookable');
    const saveUserBtn = document.getElementById('saveUserBtn');
    const cancelUserBtn = document.getElementById('cancelUserBtn');
    const closeUserModalTop = document.getElementById('closeUserModalTop');
    const emailConfigured = <?php echo json_encode($emailConfigured); ?>;

    let currentOffset = 0;
    let currentQuery = '';
    let currentUsers = [];
    const pageSize = 50;
    let currentMode = 'create';

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    function formatDate(isoDate) {
      try {
        const date = new Date(isoDate);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
      } catch {
        return isoDate;
      }
    }

    function setPageAlert(message, type = 'info', allowHtml = false) {
      alertContainer.innerHTML = `<div class="alert ${type}">${allowHtml ? message : escapeHtml(message)}</div>`;
    }

    function clearPageAlert() {
      alertContainer.innerHTML = '';
    }

    function setModalAlert(message, type = 'error', allowHtml = false) {
      modalAlert.innerHTML = `<div class="alert ${type}">${allowHtml ? message : escapeHtml(message)}</div>`;
    }

    function clearModalAlert() {
      modalAlert.innerHTML = '';
    }

    function getUserDisplayName(user) {
      if (user.full_name && String(user.full_name).trim() !== '') {
        return String(user.full_name);
      }
      return [user.first_name, user.last_name].filter(Boolean).join(' ').trim() || '-';
    }

    function openUserModal(mode, user = null) {
      if (!emailConfigured && mode === 'create') {
        setPageAlert('New user creation is disabled until email is configured.', 'error');
        return;
      }

      currentMode = mode;
      clearModalAlert();

      if (mode === 'edit' && user) {
        modalTitle.textContent = 'Edit User';
        modalSubtitle.textContent = 'Update the user profile and status.';
        userIdInput.value = String(user.id || '');
        firstNameInput.value = user.first_name || '';
        lastNameInput.value = user.last_name || '';
        emailInput.value = user.email || '';
        phoneInput.value = user.phone || '';
        roleInput.value = user.role || 'provider';
        isActiveInput.value = String(user.is_active ? 1 : 0);
        isBookableInput.value = String(user.bookable ? 1 : 0);
        saveUserBtn.textContent = 'Save Changes';
      } else {
        modalTitle.textContent = 'Create New User';
        modalSubtitle.textContent = 'Fill in the required user details. A password reset link will be generated after creation.';
        userIdInput.value = '';
        userForm.reset();
        roleInput.value = 'provider';
        isActiveInput.value = '1';
        isBookableInput.value = '1';
        saveUserBtn.textContent = 'Create User';
      }

      if (typeof userModal.showModal === 'function') {
        userModal.showModal();
      } else {
        userModal.setAttribute('open', 'open');
      }

      firstNameInput.focus();
    }

    function closeUserModal() {
      clearModalAlert();
      if (userModal.open) {
        userModal.close();
      } else {
        userModal.removeAttribute('open');
      }
    }

    async function loadUsers(offset = 0) {
      try {
        usersLoading.style.display = 'block';
        usersTable.style.display = 'none';
        paginationContainer.innerHTML = '';
        currentOffset = offset;
        currentQuery = searchInput.value.trim();

        const params = new URLSearchParams({ q: currentQuery, offset, limit: pageSize });
        const resp = await fetch(`users_api.php?action=search&${params}`);
        if (!resp.ok) throw new Error('Failed to load users');

        const json = await resp.json();
        const users = json.users || [];
        const total = json.total || 0;
        currentUsers = users;

        usersLoading.style.display = 'none';
        populateTable(users, total, offset);
      } catch (err) {
        usersLoading.innerHTML = `<div style="color: red;">Error: ${escapeHtml(err.message)}</div>`;
      }
    }

    function populateTable(users, total, offset) {
      usersTableBody.innerHTML = '';

      if (users.length === 0) {
        usersTableBody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:var(--muted);">No users found</td></tr>';
        usersTable.style.display = 'table';
        resultCount.textContent = total > 0 ? `Showing 0 of ${total} results` : 'No users found';
        return;
      }

      users.forEach(u => {
        const row = document.createElement('tr');
        const isActive = u.is_active ? 'active' : 'disabled';
        const activeText = u.is_active ? 'Active' : 'Disabled';
        const isBookable = u.bookable ? 'active' : 'disabled';
        const bookableText = u.bookable ? 'Bookable' : 'Not bookable';

        row.innerHTML = `
          <td>${escapeHtml(getUserDisplayName(u))}</td>
          <td>${escapeHtml(u.email)}</td>
          <td>${escapeHtml(u.phone || '-')}</td>
          <td><span class="role-badge">${escapeHtml(u.role)}</span></td>
          <td><span class="user-badge ${isActive}">${activeText}</span></td>
          <td><span class="user-badge ${isBookable}">${bookableText}</span></td>
          <td>${formatDate(u.created_at)}</td>
          <td class="actions-cell"><button type="button" class="action-btn primary" data-action="edit" data-user-id="${u.id}">Edit</button></td>
        `;
        usersTableBody.appendChild(row);
      });

      usersTableBody.querySelectorAll('button[data-action="edit"]').forEach(button => {
        button.addEventListener('click', () => {
          const userId = Number(button.getAttribute('data-user-id') || 0);
          const user = currentUsers.find(item => Number(item.id) === userId);
          if (user) {
            openUserModal('edit', user);
          }
        });
      });

      usersTable.style.display = 'table';
      resultCount.textContent = `Showing ${offset + 1}–${offset + users.length} of ${total} results`;

      const totalPages = Math.ceil(total / pageSize);
      paginationContainer.innerHTML = '';

      if (totalPages <= 1) return;

      const prevBtn = document.createElement('button');
      prevBtn.textContent = '← Previous';
      prevBtn.disabled = offset === 0;
      prevBtn.addEventListener('click', () => loadUsers(offset - pageSize));
      paginationContainer.appendChild(prevBtn);

      for (let page = 0; page < totalPages; page++) {
        const pageOffset = page * pageSize;
        const btn = document.createElement('button');
        btn.textContent = String(page + 1);
        btn.className = pageOffset === offset ? 'active' : '';
        btn.disabled = pageOffset === offset;
        btn.addEventListener('click', () => loadUsers(pageOffset));
        paginationContainer.appendChild(btn);
      }

      const nextBtn = document.createElement('button');
      nextBtn.textContent = 'Next →';
      nextBtn.disabled = offset + pageSize >= total;
      nextBtn.addEventListener('click', () => loadUsers(offset + pageSize));
      paginationContainer.appendChild(nextBtn);
    }

    async function submitUserForm(event) {
      event.preventDefault();
      clearModalAlert();

      const payload = {
        user_id: userIdInput.value.trim(),
        first_name: firstNameInput.value.trim(),
        last_name: lastNameInput.value.trim(),
        email: emailInput.value.trim(),
        phone: phoneInput.value.trim(),
        role: roleInput.value,
        is_active: isActiveInput.value,
        bookable: isBookableInput.value,
      };

      const action = currentMode === 'edit' ? 'update' : 'create';
      saveUserBtn.disabled = true;

      try {
        const response = await fetch(`users_api.php?action=${action}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(payload),
        });

        const json = await response.json();
        if (!response.ok) {
          if (json.errors && typeof json.errors === 'object') {
            const errorMessage = Object.values(json.errors)
              .map(value => `• ${String(value)}`)
              .join('<br>');
            setModalAlert(errorMessage, 'error', true);
          } else {
            setModalAlert(json.error || 'Unable to save user', 'error');
          }
          return;
        }

        closeUserModal();

        if (json.reset_link) {
          setPageAlert(`${escapeHtml(json.message || 'User created successfully')} <a href="${escapeHtml(json.reset_link)}" target="_blank" rel="noopener">Open reset link</a>`, 'success', true);
        } else {
          setPageAlert(json.message || 'User saved successfully', 'success');
        }

        await loadUsers(currentOffset);
      } catch (err) {
        setModalAlert(err.message || 'Network error', 'error');
      } finally {
        saveUserBtn.disabled = false;
      }
    }

    addUserBtn.addEventListener('click', () => openUserModal('create'));
    searchBtn.addEventListener('click', () => loadUsers(0));
    clearBtn.addEventListener('click', () => {
      searchInput.value = '';
      loadUsers(0);
    });
    searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        loadUsers(0);
      }
    });
    userForm.addEventListener('submit', submitUserForm);
    cancelUserBtn.addEventListener('click', closeUserModal);
    closeUserModalTop.addEventListener('click', closeUserModal);
    userModal.addEventListener('click', (event) => {
      if (event.target === userModal) {
        closeUserModal();
      }
    });

    loadUsers(0);
  </script>
</body>
</html>