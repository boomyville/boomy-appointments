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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Users - <?php echo htmlspecialchars(APP_NAME); ?></title>
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

    .toolbar a {
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

    .search-box {
      display: flex;
      gap: 10px;
      margin-bottom: 18px;
      flex-wrap: wrap;
    }

    .search-box input {
      flex: 1;
      min-width: 200px;
      border: 1px solid rgba(15, 23, 42, 0.15);
      border-radius: 16px;
      padding: 13px 14px;
      font: inherit;
      color: var(--text);
      background: #fff;
    }

    .search-box input:focus {
      outline: 3px solid rgba(36, 87, 255, 0.14);
      border-color: rgba(36, 87, 255, 0.5);
    }

    .search-box button {
      border: 1px solid var(--panel-border);
      background: rgba(255, 255, 255, 0.8);
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
      background: rgba(255, 255, 255, 0.8);
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
  </style>
</head>
<body>
  <?php $current_page = 'users.php'; include __DIR__ . '/assets/nav.html'; ?>
  
  <div class="admin-container">
    <div class="admin-header">
      <div>
        <h1>User Management</h1>
        <p>View all admin and provider users.</p>
      </div>
    </div>

    <div class="admin-panel">
      <h2>Users</h2>
      <div id="resultCount" style="color: var(--muted); font-size: 0.9rem; margin-top: 12px;">Loading...</div>
    </div>

    <div class="admin-panel">
      <h2>All Users</h2>
      <div id="usersLoading" style="text-align:center; color:var(--muted); padding:20px;">Loading users...</div>
      <table class="admin-table" id="usersTable" style="display:none;">
        <thead>
          <tr>
            <th>Full Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Role</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
          </thead>
          <tbody id="usersTableBody">
          </tbody>
        </table>
      <div id="paginationContainer" style="margin-top: 20px; display: flex; gap: 8px; flex-wrap: wrap; justify-content: center;"></div>
    </div>
  </div>

  <script src="assets/js/global.js"></script>
  <script>
    const usersTable = document.getElementById('usersTable');
    const usersTableBody = document.getElementById('usersTableBody');
    const usersLoading = document.getElementById('usersLoading');
    const resultCount = document.getElementById('resultCount');
    const paginationContainer = document.getElementById('paginationContainer');

    let currentOffset = 0;
    const pageSize = 50;

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

    async function loadUsers(offset = 0) {
      try {
        usersLoading.style.display = 'block';
        usersTable.style.display = 'none';
        paginationContainer.innerHTML = '';

        const params = new URLSearchParams({ q: '', offset, limit: pageSize });
        const resp = await fetch(`users_api.php?action=search&${params}`);
        if (!resp.ok) throw new Error('Failed to load users');

        const json = await resp.json();
        const users = json.users || [];
        const total = json.total || 0;

        usersLoading.style.display = 'none';
        populateTable(users, total, offset);
      } catch (err) {
        usersLoading.innerHTML = `<div style="color: red;">Error: ${escapeHtml(err.message)}</div>`;
      }
    }

    function populateTable(users, total, offset) {
      usersTableBody.innerHTML = '';

      if (users.length === 0) {
        usersTableBody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--muted);">No users found</td></tr>';
        usersTable.style.display = 'table';
        resultCount.textContent = total > 0 ? `Showing 0 of ${total} results` : 'No users found';
        return;
      }

      users.forEach(u => {
        const row = document.createElement('tr');
        const isActive = u.is_active ? 'active' : 'inactive';
        const activeText = u.is_active ? 'Active' : 'Inactive';

        row.innerHTML = `
          <td>${escapeHtml(u.full_name)}</td>
          <td>${escapeHtml(u.email)}</td>
          <td>${escapeHtml(u.phone || '-')}</td>
          <td><span class="role-badge">${escapeHtml(u.role)}</span></td>
          <td><span class="status-badge ${isActive}">${activeText}</span></td>
          <td>${formatDate(u.created_at)}</td>
        `;
        usersTableBody.appendChild(row);
      });

      usersTable.style.display = 'table';
      resultCount.textContent = `Showing ${offset + 1}–${offset + users.length} of ${total} results`;

      // Pagination
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

    // Load users on page load
    loadUsers(0);
  </script>
</body>
</html>
