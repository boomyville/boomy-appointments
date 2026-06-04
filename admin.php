<?php
require_once __DIR__ . '/functions.php';
init_session();
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}
$user = $_SESSION['user'];
$db = get_db();

// Get some stats for the dashboard
$userCount = 0;
$customerCount = 0;
$appointmentCount = 0;
$appointmentTypeCount = 0;

try {
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM users');
    $userCount = (int) ($stmt->fetch()['cnt'] ?? 0);
} catch (Throwable $e) {}

try {
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM customers');
    $customerCount = (int) ($stmt->fetch()['cnt'] ?? 0);
} catch (Throwable $e) {}

try {
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM appointments');
    $appointmentCount = (int) ($stmt->fetch()['cnt'] ?? 0);
} catch (Throwable $e) {}

try {
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM appointment_types WHERE is_active = 1');
    $appointmentTypeCount = (int) ($stmt->fetch()['cnt'] ?? 0);
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Dashboard - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="assets/css/pico.min.css" id="pico-css" onerror="this.onerror=null; this.href='https://unpkg.com/@picocss/pico@2.*/css/pico.min.css';">
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/admin-shared.css">
</head>
<body>
  <?php $current_page = 'admin.php'; include __DIR__ . '/assets/nav.html'; ?>

  <div class="admin-container">
    <div class="admin-header">
      <div>
        <h1>Dashboard</h1>
        <p>Overview of your appointment system</p>
      </div>
      <div class="admin-user-info">
        <small>Signed in as</small>
        <strong><?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></strong>
      </div>
    </div>

    <div class="admin-grid">
      <div class="admin-card">
        <h3>Total Users</h3>
        <div class="number"><?php echo $userCount; ?></div>
        <p>Admins and providers</p>
      </div>

      <div class="admin-card">
        <h3>Total Customers</h3>
        <div class="number"><?php echo $customerCount; ?></div>
        <p>Registered customers</p>
      </div>

      <div class="admin-card">
        <h3>Appointments</h3>
        <div class="number"><?php echo $appointmentCount; ?></div>
        <p>Total bookings</p>
      </div>

      <div class="admin-card">
        <h3>Appointment Types</h3>
        <div class="number"><?php echo $appointmentTypeCount; ?></div>
        <p>Active service types</p>
      </div>
    </div>

    <div class="admin-panel">
      <h2>Quick Actions</h2>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px;">
        <a href="users.php" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:16px; border:1px solid var(--panel-border); background:rgba(36,87,255,0.06); border-radius:12px; text-decoration:none; color:var(--accent); font-weight:600; transition:all 0.2s ease;">
          <span style="font-size:1.6rem;">👥</span>
          <span>Users</span>
        </a>
        <a href="customers.php" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:16px; border:1px solid var(--panel-border); background:rgba(36,87,255,0.06); border-radius:12px; text-decoration:none; color:var(--accent); font-weight:600; transition:all 0.2s ease;">
          <span style="font-size:1.6rem;">👤</span>
          <span>Customers</span>
        </a>
        <a href="calendar.php" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:16px; border:1px solid var(--panel-border); background:rgba(36,87,255,0.06); border-radius:12px; text-decoration:none; color:var(--accent); font-weight:600; transition:all 0.2s ease;">
          <span style="font-size:1.6rem;">📅</span>
          <span>Calendar</span>
        </a>
        <a href="settings.php" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:16px; border:1px solid var(--panel-border); background:rgba(36,87,255,0.06); border-radius:12px; text-decoration:none; color:var(--accent); font-weight:600; transition:all 0.2s ease;">
          <span style="font-size:1.6rem;">⚙️</span>
          <span>Settings</span>
        </a>
      </div>
    </div>
  </div>

  <script src="assets/js/global.js"></script>
</body>
</html>
