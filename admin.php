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
$todayAppointmentCount = 0;
$todaySeenCount = 0;
$todayPendingCount = 0;
$todayNoShowCount = 0;
$todayCancelledCount = 0;
$nextAppointment = null;
$upcomingToday = [];
$weekStart = null;
$weekTotal = 0;
$lastWeekTotal = 0;
$activeWeekAverage = 0.0;
$activeDayAverage = 0.0;
$busiestTimeSlot = null;
$currentWeekNoShowRate = 0.0;
$currentMonthNoShowRate = 0.0;
$currentYearNoShowRate = 0.0;
$currentWeekCompletedRate = 0.0;
$currentMonthCompletedRate = 0.0;
$currentYearCompletedRate = 0.0;
$weekChart = [];
$maxChartValue = 1;
$activeWeeks = 0;
$firstAppointmentDate = null;
$customerStats = [
  'total' => 0,
  'email_yes' => 0,
  'email_no' => 0,
  'dummy_email' => 0,
  'unique_email' => 0,
  'mobile_yes' => 0,
  'mobile_no' => 0,
  'invalid_email' => 0,
  'recent' => 0,
  'active' => 0,
];
$customerGenderCounts = ['Male' => 0, 'Female' => 0, 'Other' => 0, 'Unknown' => 0];
$customerAgeCounts = ['0-4' => 0, '4-12' => 0, '12-21' => 0, '21-35' => 0, '35-50' => 0, '50-65' => 0, '65+' => 0, 'Unknown' => 0];
$emailQualityPie = '#334155';
$mobilePie = '#334155';
$customerScopeSql = '';
$customerScopeParams = [];

$dashboardTimezone = new DateTimeZone(get_app_timezone_name());
$dashboardNow = new DateTimeImmutable('now', $dashboardTimezone);
$todayStart = $dashboardNow->setTime(0, 0, 0);
$tomorrowStart = $todayStart->modify('+1 day');
$appointmentScopeSql = '';
$appointmentScopeParams = [];
if (($user['role'] ?? '') === 'provider') {
  $appointmentScopeSql = ' AND a.provider_id = ?';
  $appointmentScopeParams[] = (int) ($user['id'] ?? 0);
  $customerScopeSql = ' AND EXISTS (SELECT 1 FROM appointments ca WHERE ca.customer_id = c.id AND ca.provider_id = ?)';
  $customerScopeParams[] = (int) ($user['id'] ?? 0);
}

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

try {
  $customerStatsStmt = $db->prepare("SELECT
    COUNT(*) AS total_count,
    SUM(CASE WHEN TRIM(COALESCE(c.email, '')) <> '' THEN 1 ELSE 0 END) AS email_yes,
    SUM(CASE WHEN TRIM(COALESCE(c.email, '')) = '' THEN 1 ELSE 0 END) AS email_no,
    SUM(CASE WHEN TRIM(COALESCE(c.mobile, '')) <> '' THEN 1 ELSE 0 END) AS mobile_yes,
    SUM(CASE WHEN TRIM(COALESCE(c.mobile, '')) = '' THEN 1 ELSE 0 END) AS mobile_no,
    SUM(CASE WHEN c.email_invalid = 1 THEN 1 ELSE 0 END) AS invalid_email,
    SUM(CASE WHEN c.date_added >= ? THEN 1 ELSE 0 END) AS recent_count,
    SUM(CASE WHEN c.last_seen_at >= ? THEN 1 ELSE 0 END) AS active_count
    FROM customers c
    WHERE 1=1{$customerScopeSql}");
  $customerStatsStmt->execute(array_merge([
    $dashboardNow->modify('-30 days')->format('Y-m-d H:i:s'),
    $dashboardNow->modify('-90 days')->format('Y-m-d H:i:s')
  ], $customerScopeParams));
  $customerStatsRow = $customerStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
  foreach ($customerStats as $key => $value) {
    $customerStats[$key] = (int) ($customerStatsRow[$key === 'total' ? 'total_count' : $key . '_count'] ?? $customerStatsRow[$key] ?? 0);
  }

  $dummyEmailStmt = $db->prepare("SELECT COUNT(*)
    FROM customers c
    INNER JOIN (
      SELECT LOWER(TRIM(email)) AS email_key
      FROM customers
      WHERE TRIM(COALESCE(email, '')) <> ''
      GROUP BY LOWER(TRIM(email))
      HAVING COUNT(*) >= 10
    ) repeated_emails ON repeated_emails.email_key = LOWER(TRIM(c.email))
    WHERE TRIM(COALESCE(c.email, '')) <> ''{$customerScopeSql}");
  $dummyEmailStmt->execute($customerScopeParams);
  $customerStats['dummy_email'] = (int) $dummyEmailStmt->fetchColumn();
  $customerStats['unique_email'] = max(0, $customerStats['email_yes'] - $customerStats['dummy_email']);

  $genderStmt = $db->prepare("SELECT LOWER(COALESCE(c.gender, 'unknown')) AS gender_key, COUNT(*) AS total_count
    FROM customers c
    WHERE 1=1{$customerScopeSql}
    GROUP BY LOWER(COALESCE(c.gender, 'unknown'))");
  $genderStmt->execute($customerScopeParams);
  foreach ($genderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $genderLabel = match (strtolower((string) $row['gender_key'])) {
      'male' => 'Male',
      'female' => 'Female',
      'other' => 'Other',
      default => 'Unknown',
    };
    $customerGenderCounts[$genderLabel] += (int) $row['total_count'];
  }

  $ageStmt = $db->prepare("SELECT
      CASE
        WHEN c.date_of_birth IS NULL OR c.date_of_birth > CURDATE() THEN 'Unknown'
        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 4 THEN '0-4'
        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 12 THEN '4-12'
        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 21 THEN '12-21'
        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 35 THEN '21-35'
        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 50 THEN '35-50'
        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 65 THEN '50-65'
        ELSE '65+'
      END AS age_band,
      COUNT(*) AS total_count
    FROM customers c
    WHERE 1=1{$customerScopeSql}
    GROUP BY age_band");
  $ageStmt->execute($customerScopeParams);
  foreach ($ageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $ageBand = (string) ($row['age_band'] ?? 'Unknown');
    if (array_key_exists($ageBand, $customerAgeCounts)) $customerAgeCounts[$ageBand] = (int) $row['total_count'];
  }
} catch (Throwable $e) {}

$makePieGradient = static function (array $counts, array $colors): string {
  $total = array_sum($counts);
  if ($total <= 0) return '#334155';
  $segments = [];
  $offset = 0.0;
  $index = 0;
  foreach ($counts as $count) {
    $next = $offset + ((int) $count / $total) * 100;
    $segments[] = $colors[$index % count($colors)] . ' ' . $offset . '% ' . $next . '%';
    $offset = $next;
    $index++;
  }
  return 'conic-gradient(' . implode(', ', $segments) . ')';
};
$agePie = $makePieGradient(array_values($customerAgeCounts), ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b', '#0ea5e9', '#f97316', '#475569']);
$emailQualityPie = $makePieGradient([$customerStats['unique_email'], $customerStats['dummy_email'], $customerStats['email_no']], ['#10b981', '#f59e0b', '#64748b']);
$mobilePie = $makePieGradient([$customerStats['mobile_yes'], $customerStats['mobile_no']], ['#2563eb', '#64748b']);
$genderPie = $makePieGradient(array_values($customerGenderCounts), ['#2563eb', '#ec4899', '#f59e0b', '#64748b']);

// Booking channel: how appointments created in each period were booked (cancelled excluded).
$channelPeriods = [];
$channelLabels = ['online' => 'Booked online', 'staff' => 'Booked by staff', 'unknown' => 'Unknown (before tracking)'];
foreach ([7, 30, 90] as $channelDays) {
  $counts = ['Booked online' => 0, 'Booked by staff' => 0, 'Unknown (before tracking)' => 0];
  try {
    $channelStmt = $db->prepare("SELECT a.booking_source, COUNT(*) AS total_count
      FROM appointments a
      WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL {$channelDays} DAY) AND a.status <> 'cancelled'{$appointmentScopeSql}
      GROUP BY a.booking_source");
    $channelStmt->execute($appointmentScopeParams);
    foreach ($channelStmt->fetchAll(PDO::FETCH_ASSOC) as $channelRow) {
      $channelKey = $channelLabels[$channelRow['booking_source']] ?? 'Unknown (before tracking)';
      $counts[$channelKey] += (int) $channelRow['total_count'];
    }
  } catch (Throwable $e) {}
  $channelPeriods[(string) $channelDays] = $counts;
}
$channelCounts = $channelPeriods['90'];
$channelColors = ['#2563eb', '#10b981', '#94a3b8'];
$channelPie = $makePieGradient(array_values($channelCounts), $channelColors);
$channelTotal = array_sum($channelCounts);

try {
  $todayStatsStmt = $db->prepare("SELECT
    SUM(CASE WHEN a.status <> 'cancelled' THEN 1 ELSE 0 END) AS total_count,
    SUM(CASE WHEN a.status IN ('present', 'completed') THEN 1 ELSE 0 END) AS seen_count,
    SUM(CASE WHEN a.status = 'unconfirmed' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN a.status = 'no_show' THEN 1 ELSE 0 END) AS no_show_count,
    SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
    FROM appointments a
    WHERE a.start_time >= ? AND a.start_time < ?{$appointmentScopeSql}");
  $todayStatsStmt->execute(array_merge([
    $todayStart->format('Y-m-d H:i:s'),
    $tomorrowStart->format('Y-m-d H:i:s')
  ], $appointmentScopeParams));
  $todayStats = $todayStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $todayAppointmentCount = (int) ($todayStats['total_count'] ?? 0);
  $todaySeenCount = (int) ($todayStats['seen_count'] ?? 0);
  $todayPendingCount = (int) ($todayStats['pending_count'] ?? 0);
  $todayNoShowCount = (int) ($todayStats['no_show_count'] ?? 0);
  $todayCancelledCount = (int) ($todayStats['cancelled_count'] ?? 0);

  $nextStmt = $db->prepare("SELECT a.start_time, a.end_time, c.full_name AS customer_name, t.name AS type_name
    FROM appointments a
    LEFT JOIN customers c ON c.id = a.customer_id
    LEFT JOIN appointment_types t ON t.id = a.appointment_type_id
    WHERE a.start_time >= ?
      AND a.status NOT IN ('completed', 'no_show', 'cancelled'){$appointmentScopeSql}
    ORDER BY a.start_time ASC
    LIMIT 1");
  $nextStmt->execute(array_merge([$dashboardNow->format('Y-m-d H:i:s')], $appointmentScopeParams));
  $nextAppointment = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;

  // Everything still to come today (including one that has started but is not yet marked present), soonest first
  $upcomingStmt = $db->prepare("SELECT a.start_time, a.status, c.full_name AS customer_name, t.name AS type_name
    FROM appointments a
    LEFT JOIN customers c ON c.id = a.customer_id
    LEFT JOIN appointment_types t ON t.id = a.appointment_type_id
    WHERE a.start_time >= ? AND a.start_time < ? AND a.end_time > ?
      AND a.status NOT IN ('present', 'completed', 'no_show', 'cancelled'){$appointmentScopeSql}
    ORDER BY a.start_time ASC
    LIMIT 50");
  $upcomingStmt->execute(array_merge([
    $todayStart->format('Y-m-d H:i:s'),
    $tomorrowStart->format('Y-m-d H:i:s'),
    $dashboardNow->format('Y-m-d H:i:s')
  ], $appointmentScopeParams));
  $upcomingToday = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
  $weekStart = $todayStart->modify('monday this week');
  $lastWeekStart = $weekStart->modify('-7 days');
  $nextWeekStart = $weekStart->modify('+7 days');
  $monthStart = $todayStart->modify('first day of this month');
  $nextMonthStart = $monthStart->modify('+1 month');
  $yearStart = $todayStart->setDate((int) $todayStart->format('Y'), 1, 1);

  $firstAppointmentStmt = $db->prepare("SELECT MIN(a.start_time) FROM appointments a WHERE 1=1{$appointmentScopeSql}");
  $firstAppointmentStmt->execute($appointmentScopeParams);
  $firstAppointmentRaw = $firstAppointmentStmt->fetchColumn();
  $firstAppointmentDate = $firstAppointmentRaw
    ? new DateTimeImmutable((string) $firstAppointmentRaw, $dashboardTimezone)
    : null;
  $averageStart = $yearStart;
  if ($firstAppointmentDate && $firstAppointmentDate > $yearStart) {
    $averageStart = $firstAppointmentDate->setTime(0, 0, 0)->modify('monday this week');
  }

  $countByWeekday = function (DateTimeImmutable $start, DateTimeImmutable $end) use ($db, $appointmentScopeSql, $appointmentScopeParams): array {
    $stmt = $db->prepare("SELECT WEEKDAY(a.start_time) AS weekday_number, COUNT(*) AS total_count
      FROM appointments a
      WHERE a.start_time >= ? AND a.start_time < ?{$appointmentScopeSql}
      GROUP BY WEEKDAY(a.start_time)");
    $stmt->execute(array_merge([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], $appointmentScopeParams));
    $counts = array_fill(0, 7, 0);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $weekday = (int) $row['weekday_number'];
      if ($weekday >= 0 && $weekday <= 6) $counts[$weekday] = (int) $row['total_count'];
    }
    return $counts;
  };

  $weekCounts = $countByWeekday($weekStart, $nextWeekStart);
  $lastWeekCounts = $countByWeekday($lastWeekStart, $weekStart);
  $weeklyStmt = $db->prepare("SELECT DATE_FORMAT(a.start_time, '%x-%v') AS week_key, COUNT(*) AS total_count
    FROM appointments a
    WHERE a.start_time >= ? AND a.start_time < ?{$appointmentScopeSql}
    GROUP BY DATE_FORMAT(a.start_time, '%x-%v')");
  $weeklyStmt->execute(array_merge([$averageStart->format('Y-m-d H:i:s'), $weekStart->format('Y-m-d H:i:s')], $appointmentScopeParams));
  $activeWeeks = 0;
  $weeklyTotal = 0;
  foreach ($weeklyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $weekCount = (int) $row['total_count'];
    if ($weekCount > 0) {
      $activeWeeks++;
      $weeklyTotal += $weekCount;
    }
  }
  $activeWeekAverage = $activeWeeks > 0 ? $weeklyTotal / $activeWeeks : 0.0;
  $weekTotal = array_sum($weekCounts);
  $lastWeekTotal = array_sum($lastWeekCounts);

  $weekdayAverageStmt = $db->prepare("SELECT WEEKDAY(a.start_time) AS weekday_number, COUNT(*) AS total_count
    FROM appointments a
    WHERE a.start_time >= ? AND a.start_time < ?{$appointmentScopeSql}
    GROUP BY WEEKDAY(a.start_time)");
  $weekdayAverageStmt->execute(array_merge([$averageStart->format('Y-m-d H:i:s'), $weekStart->format('Y-m-d H:i:s')], $appointmentScopeParams));
  $weekdayTotals = array_fill(0, 7, 0);
  foreach ($weekdayAverageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $weekday = (int) $row['weekday_number'];
    if ($weekday >= 0 && $weekday <= 6) $weekdayTotals[$weekday] = (int) $row['total_count'];
  }
  $weekdayAverages = array_map(static fn(int $count): float => $activeWeeks > 0 ? $count / $activeWeeks : 0.0, $weekdayTotals);
  $weekChart = array_map(static function (int $weekday) use ($weekCounts, $lastWeekCounts, $weekdayAverages): array {
    return [
      'label' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$weekday],
      'current' => $weekCounts[$weekday],
      'previous' => $lastWeekCounts[$weekday],
      'average' => $weekdayAverages[$weekday],
    ];
  }, range(0, 6));
  $maxChartValue = max(1, ...array_map(static fn(array $day): float => max($day['current'], $day['previous'], $day['average']), $weekChart));

  $activeDayStmt = $db->prepare("SELECT COUNT(*) AS total_count, COUNT(DISTINCT DATE(a.start_time)) AS active_days
    FROM appointments a
    WHERE a.start_time >= ? AND a.start_time < ?{$appointmentScopeSql}");
  $activeDayStmt->execute(array_merge([$weekStart->format('Y-m-d H:i:s'), $nextWeekStart->format('Y-m-d H:i:s')], $appointmentScopeParams));
  $activeDayStats = $activeDayStmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $activeDayAverage = ((int) ($activeDayStats['active_days'] ?? 0)) > 0
    ? (int) ($activeDayStats['total_count'] ?? 0) / (int) $activeDayStats['active_days']
    : 0.0;

  $busiestStmt = $db->prepare("SELECT HOUR(a.start_time) AS appointment_hour, COUNT(*) AS total_count
    FROM appointments a
    WHERE a.start_time >= ? AND a.start_time < ?{$appointmentScopeSql}
    GROUP BY HOUR(a.start_time)
    ORDER BY total_count DESC, appointment_hour ASC
    LIMIT 1");
  $busiestStmt->execute(array_merge([$weekStart->format('Y-m-d H:i:s'), $nextWeekStart->format('Y-m-d H:i:s')], $appointmentScopeParams));
  $busiestRow = $busiestStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($busiestRow) {
    $hour = (int) $busiestRow['appointment_hour'];
    $busiestTimeSlot = [
      'label' => date('g A', mktime($hour, 0)) . ' - ' . date('g A', mktime(($hour + 1) % 24, 0)),
      'count' => (int) $busiestRow['total_count'],
    ];
  }

  $rateForPeriod = function (DateTimeImmutable $start, DateTimeImmutable $end) use ($db, $dashboardNow, $appointmentScopeSql, $appointmentScopeParams): array {
    $effectiveEnd = $end < $dashboardNow ? $end : $dashboardNow;
    if ($effectiveEnd <= $start) return ['no_show_rate' => 0.0, 'completed_rate' => 0.0];
    $stmt = $db->prepare("SELECT
      SUM(CASE WHEN a.status = 'no_show' THEN 1 ELSE 0 END) AS no_show_count,
      SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
      SUM(CASE WHEN a.status IN ('completed', 'no_show') THEN 1 ELSE 0 END) AS resolved_count
      FROM appointments a
      WHERE a.start_time >= ? AND a.start_time < ?{$appointmentScopeSql}");
    $stmt->execute(array_merge([$start->format('Y-m-d H:i:s'), $effectiveEnd->format('Y-m-d H:i:s')], $appointmentScopeParams));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $resolved = (int) ($row['resolved_count'] ?? 0);
    return [
      'no_show_rate' => $resolved > 0 ? ((int) ($row['no_show_count'] ?? 0) / $resolved) * 100 : 0.0,
      'completed_rate' => $resolved > 0 ? ((int) ($row['completed_count'] ?? 0) / $resolved) * 100 : 0.0,
    ];
  };
  $weekRates = $rateForPeriod($weekStart, $nextWeekStart);
  $monthRates = $rateForPeriod($monthStart, $nextMonthStart);
  $yearRates = $rateForPeriod($yearStart, $todayStart->modify('+1 day'));
  $currentWeekNoShowRate = $weekRates['no_show_rate'];
  $currentMonthNoShowRate = $monthRates['no_show_rate'];
  $currentYearNoShowRate = $yearRates['no_show_rate'];
  $currentWeekCompletedRate = $weekRates['completed_rate'];
  $currentMonthCompletedRate = $monthRates['completed_rate'];
  $currentYearCompletedRate = $yearRates['completed_rate'];
} catch (Throwable $e) {}

$nextAppointmentTime = $nextAppointment
  ? (new DateTimeImmutable((string) $nextAppointment['start_time'], $dashboardTimezone))->format('g:i A')
  : null;
$nextAppointmentDay = null;
if ($nextAppointment) {
  $nextAppointmentDate = new DateTimeImmutable((string) $nextAppointment['start_time'], $dashboardTimezone);
  $nextAppointmentDaysAway = (int) $todayStart->diff($nextAppointmentDate->setTime(0, 0, 0))->format('%r%a');
  $nextAppointmentDay = match ($nextAppointmentDaysAway) {
    0 => 'Today',
    1 => 'Tomorrow',
    default => $nextAppointmentDate->format('l, j F') . ' (in ' . $nextAppointmentDaysAway . ' days)',
  };
}
$nextAppointmentName = $nextAppointment['customer_name'] ?? 'Customer';
$nextAppointmentType = $nextAppointment['type_name'] ?? 'Appointment';
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
  <style>
    .dashboard-section-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
    .dashboard-section-heading h2 { margin:0 0 4px; }
    .dashboard-section-heading p { margin:0; color:var(--muted); }
    .dashboard-section-heading a { color:var(--accent); font-weight:600; white-space:nowrap; }
    .dashboard-today-grid { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:10px; }
    .dashboard-today-stat { min-height:88px; padding:14px; border:1px solid var(--panel-border); border-radius:10px; background:rgba(36,87,255,0.04); }
    .dashboard-today-stat strong { display:block; font-size:1.55rem; line-height:1.1; color:var(--text); }
    .dashboard-today-stat span { display:block; margin-top:8px; color:var(--muted); font-size:.88rem; }
    .dashboard-today-stat-primary { background:rgba(36,87,255,0.1); border-color:rgba(36,87,255,0.25); }
    .dashboard-next-appointment { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px 14px; margin-top:16px; padding:14px 16px; border-left:4px solid var(--accent); background:var(--panel-muted, rgba(36,87,255,0.05)); }
    .dashboard-next-appointment strong { color:var(--text); }
    .dashboard-next-appointment span { color:var(--muted); }
    .dashboard-next-label { flex-basis:100%; font-size:.78rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
    .dashboard-upcoming-list { flex-basis:100%; list-style:none; margin:4px 0 0; padding:0; }
    .dashboard-upcoming-list li { display:flex; flex-wrap:wrap; align-items:baseline; gap:2px 12px; margin:0; padding:7px 0; border-bottom:1px solid var(--panel-border); }
    .dashboard-upcoming-list li:last-child { border-bottom:0; }
    .dashboard-upcoming-list li.is-next { font-size:1.05rem; }
    .dashboard-upcoming-list .dashboard-upcoming-time { min-width:76px; font-weight:700; color:var(--text); }
    .dashboard-upcoming-list .dashboard-upcoming-name { font-weight:600; color:var(--text); }
    .dashboard-upcoming-list .dashboard-upcoming-type { font-size:.88rem; color:var(--muted); }
    .dashboard-upcoming-list .dashboard-upcoming-flag { font-size:.78rem; font-weight:600; color:#b45309; }
    .dashboard-upcoming-list .dashboard-upcoming-badge { padding:1px 8px; border-radius:99px; background:var(--accent); color:#fff; font-size:.7rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
    @media (max-width: 720px) {
      .dashboard-today-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
      .dashboard-today-stat-primary { grid-column:span 2; }
    }
    .week-modal { display:none; position:fixed; inset:0; z-index:1000; padding:24px; background:rgba(15,23,42,.58); overflow:auto; }
    .week-modal.is-open { display:flex; align-items:flex-start; justify-content:center; }
    .week-modal-dialog { width:min(860px, 100%); margin:2vh auto; padding:18px; border:1px solid var(--panel-border); border-radius:12px; background:#fff; color:#0f172a; box-shadow:0 24px 70px rgba(15,23,42,.25); }
    .week-modal-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:16px; }
    .week-modal-header h2 { margin:0 0 4px; }
    .week-modal-header p { margin:0; color:var(--muted); }
    .week-modal-close { margin:0; padding:6px 10px; }
    .week-stat-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:8px; margin-bottom:16px; }
    .week-stat { padding:10px 12px; border:1px solid var(--panel-border); border-radius:8px; background:rgba(36,87,255,.04); }
    .week-stat strong { display:block; font-size:1.25rem; color:var(--text); }
    .week-stat span { display:block; margin-top:4px; color:var(--muted); font-size:.8rem; }
    .week-chart { padding:14px 12px 8px; border:1px solid var(--panel-border); border-radius:8px; background:rgba(36,87,255,.025); }
    .week-chart-legend { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:10px; color:var(--muted); font-size:.78rem; }
    .week-chart-legend span { display:inline-flex; align-items:center; gap:6px; }
    .week-chart-key { width:10px; height:10px; border-radius:50%; }
    .week-chart-key-current { background:#2457ff; }
    .week-chart-key-previous { background:#94a3b8; }
    .week-chart-key-average { background:#f59e0b; }
    .week-chart-bars { display:grid; grid-template-columns:repeat(7, minmax(0, 1fr)); gap:8px; align-items:end; min-height:170px; }
    .week-chart-day { display:flex; flex-direction:column; justify-content:flex-end; min-width:0; height:170px; }
    .week-chart-columns { display:flex; align-items:end; justify-content:center; gap:2px; height:140px; }
    .week-chart-bar { width:clamp(8px, 2vw, 18px); min-height:2px; border-radius:4px 4px 0 0; }
    .week-chart-bar-current { background:#2457ff; }
    .week-chart-bar-previous { background:#94a3b8; }
    .week-chart-bar-average { background:#f59e0b; }
    .week-chart-day-label { margin-top:8px; text-align:center; color:var(--muted); font-size:.8rem; }
    .week-chart-day-total { margin-top:3px; text-align:center; color:var(--text); font-size:.75rem; }
    .week-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:12px; }
    .week-detail-card { padding:12px; border:1px solid var(--panel-border); border-radius:8px; }
    .week-detail-card h3 { margin:0 0 12px; font-size:1rem; }
    .week-detail-card table { width:100%; margin:0; }
    .week-detail-card td { padding:6px 0; border-bottom:1px solid var(--panel-border); }
    .week-detail-card td:last-child { text-align:right; font-weight:700; }
    .week-modal-note { margin:16px 0 0; color:var(--muted); font-size:.84rem; }
    html[data-theme="dark"] .week-modal-dialog { background:#111827; color:#e5e7eb; border-color:#334155; }
    html[data-theme="dark"] .week-stat,
    html[data-theme="dark"] .week-chart,
    html[data-theme="dark"] .week-detail-card { background:#0b1220; border-color:#334155; }
    html[data-theme="dark"] .week-stat strong,
    html[data-theme="dark"] .week-detail-card td:last-child { color:#f8fafc; }
    html[data-theme="dark"] .week-detail-card td { border-color:#334155; }
    .customer-stat-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:8px; margin-bottom:14px; }
    .customer-stat { padding:10px 12px; border:1px solid var(--panel-border); border-radius:8px; background:rgba(36,87,255,.04); }
    .customer-stat strong { display:block; font-size:1.25rem; color:var(--text); }
    .customer-stat span { display:block; margin-top:4px; color:var(--muted); font-size:.8rem; }
    .customer-chart-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
    .customer-chart-card { padding:12px; border:1px solid var(--panel-border); border-radius:8px; background:rgba(36,87,255,.025); }
    .customer-chart-card h3 { margin:0 0 10px; font-size:.95rem; }
    .customer-chart-content { display:flex; align-items:center; gap:14px; }
    .customer-pie { flex:0 0 92px; width:92px; height:92px; border-radius:50%; }
    .customer-age-bars { width:100%; }
    .customer-age-row { display:grid; grid-template-columns:58px 1fr 28px; align-items:center; gap:7px; margin:6px 0; color:var(--muted); font-size:.75rem; }
    .customer-age-track { height:8px; overflow:hidden; border-radius:999px; background:rgba(100,116,139,.2); }
    .customer-age-fill { display:block; height:100%; min-width:2px; border-radius:999px; }
    .customer-chart-legend { min-width:0; list-style:none; padding:0; margin:0; font-size:.78rem; color:var(--muted); }
    .customer-chart-legend li { display:flex; align-items:center; gap:6px; margin:4px 0; white-space:nowrap; }
    .customer-chart-swatch { width:9px; height:9px; border-radius:50%; flex:0 0 9px; }
    html[data-theme="dark"] .customer-stat,
    html[data-theme="dark"] .customer-chart-card { background:#0b1220; border-color:#334155; }
    html[data-theme="dark"] .customer-stat strong { color:#f8fafc; }
    @media (max-width: 720px) {
      .week-modal { padding:10px; }
      .week-modal-dialog { padding:14px; margin:1vh auto; }
      .week-stat-grid, .week-detail-grid { grid-template-columns:1fr 1fr; }
      .week-chart { overflow-x:auto; }
      .week-chart-bars { min-width:520px; }
      .customer-stat-grid, .customer-chart-grid { grid-template-columns:1fr 1fr; }
      .customer-chart-content { align-items:flex-start; flex-direction:column; }
    }
  </style>
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

    <div class="admin-panel dashboard-today-panel">
      <div class="dashboard-section-heading">
        <div>
          <h2>Today at a glance</h2>
          <p><?php echo htmlspecialchars($todayStart->format('l, j F Y')); ?></p>
        </div>
        <a href="calendar.php">Open calendar</a>
      </div>

      <div class="dashboard-today-grid">
        <div class="dashboard-today-stat dashboard-today-stat-primary">
          <strong><?php echo $todayAppointmentCount; ?></strong>
          <span>Appointments today</span>
        </div>
        <div class="dashboard-today-stat">
          <strong><?php echo $todaySeenCount; ?> / <?php echo $todayAppointmentCount; ?></strong>
          <span>Seen or completed</span>
        </div>
        <div class="dashboard-today-stat">
          <strong><?php echo $todayPendingCount; ?></strong>
          <span>Awaiting confirmation</span>
        </div>
        <div class="dashboard-today-stat">
          <strong><?php echo $todayNoShowCount; ?></strong>
          <span>No-shows</span>
        </div>
        <div class="dashboard-today-stat">
          <strong><?php echo $todayCancelledCount; ?></strong>
          <span>Cancelled today</span>
        </div>
      </div>

      <div class="dashboard-next-appointment">
        <span class="dashboard-next-label"><?php echo $upcomingToday ? 'Upcoming today (' . count($upcomingToday) . ')' : 'Next appointment'; ?></span>
        <?php if ($upcomingToday): ?>
          <ul class="dashboard-upcoming-list">
            <?php foreach ($upcomingToday as $upcomingIndex => $upcomingRow): ?>
              <li<?php echo $upcomingIndex === 0 ? ' class="is-next"' : ''; ?>>
                <span class="dashboard-upcoming-time"><?php echo htmlspecialchars((new DateTimeImmutable((string) $upcomingRow['start_time'], $dashboardTimezone))->format('g:i A')); ?></span>
                <span class="dashboard-upcoming-name"><?php echo htmlspecialchars(trim((string) ($upcomingRow['customer_name'] ?? '')) !== '' ? $upcomingRow['customer_name'] : 'Customer'); ?></span>
                <span class="dashboard-upcoming-type"><?php echo htmlspecialchars($upcomingRow['type_name'] ?? 'Appointment'); ?></span>
                <?php if (($upcomingRow['status'] ?? '') === 'unconfirmed'): ?><span class="dashboard-upcoming-flag">Awaiting confirmation</span><?php endif; ?>
                <?php if ($upcomingIndex === 0): ?><span class="dashboard-upcoming-badge">Next</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($nextAppointment): ?>
          <strong><?php echo htmlspecialchars($nextAppointmentDay . ' at ' . $nextAppointmentTime . ' - ' . $nextAppointmentName); ?></strong>
          <span><?php echo htmlspecialchars($nextAppointmentType); ?></span>
        <?php else: ?>
          <strong>No more appointments today</strong>
          <span>The schedule is clear for the rest of the day.</span>
        <?php endif; ?>
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
        <button type="button" id="weekAtGlanceButton" aria-haspopup="dialog" style="display:flex; width:100%; height:100%; margin:0; flex-direction:column; align-items:center; justify-content:center; gap:6px; padding:16px; border:1px solid var(--panel-border); background:rgba(36,87,255,0.06); border-radius:12px; color:var(--accent); font:inherit; font-weight:600; cursor:pointer; appearance:none; transition:all 0.2s ease;">
          <span style="font-size:1.6rem;">📊</span>
          <span>Week at a glance</span>
        </button>
        <button type="button" id="customerStatsButton" aria-haspopup="dialog" style="display:flex; width:100%; height:100%; margin:0; flex-direction:column; align-items:center; justify-content:center; gap:6px; padding:16px; border:1px solid var(--panel-border); background:rgba(36,87,255,0.06); border-radius:12px; color:var(--accent); font:inherit; font-weight:600; cursor:pointer; appearance:none; transition:all 0.2s ease;">
          <span style="font-size:1.6rem;">👥</span>
          <span>Customer statistics</span>
        </button>
      </div>
    </div>
  </div>

  <div class="week-modal" id="weekAtGlanceModal" role="dialog" aria-modal="true" aria-labelledby="weekAtGlanceTitle" hidden>
    <div class="week-modal-dialog">
      <div class="week-modal-header">
        <div>
          <h2 id="weekAtGlanceTitle">Week at a glance</h2>
          <p><?php echo htmlspecialchars($weekStart ? $weekStart->format('j F') . ' - ' . $nextWeekStart->modify('-1 day')->format('j F Y') : 'Weekly operations overview'); ?></p>
        </div>
        <button type="button" class="week-modal-close" id="weekAtGlanceClose" aria-label="Close">Close</button>
      </div>

      <div class="week-stat-grid">
        <div class="week-stat"><strong><?php echo $weekTotal; ?></strong><span>This week</span></div>
        <div class="week-stat"><strong><?php echo $lastWeekTotal; ?></strong><span>Last week</span></div>
        <div class="week-stat"><strong><?php echo number_format($activeWeekAverage, 1); ?></strong><span>Average active week</span></div>
        <div class="week-stat"><strong><?php echo number_format($activeDayAverage, 1); ?></strong><span>Average per active day</span></div>
      </div>

      <div class="week-chart" aria-label="Appointment comparison chart">
        <div class="week-chart-legend">
          <span><i class="week-chart-key week-chart-key-current"></i>This week</span>
          <span><i class="week-chart-key week-chart-key-previous"></i>Last week</span>
          <span><i class="week-chart-key week-chart-key-average"></i>Year average per weekday</span>
        </div>
        <div class="week-chart-bars">
          <?php foreach ($weekChart as $day): ?>
            <div class="week-chart-day">
              <div class="week-chart-columns">
                <i class="week-chart-bar week-chart-bar-current" title="This week: <?php echo (int) $day['current']; ?>" style="height:<?php echo ($day['current'] / $maxChartValue) * 100; ?>%"></i>
                <i class="week-chart-bar week-chart-bar-previous" title="Last week: <?php echo (int) $day['previous']; ?>" style="height:<?php echo ($day['previous'] / $maxChartValue) * 100; ?>%"></i>
                <i class="week-chart-bar week-chart-bar-average" title="Year average: <?php echo number_format($day['average'], 1); ?>" style="height:<?php echo ($day['average'] / $maxChartValue) * 100; ?>%"></i>
              </div>
              <div class="week-chart-day-label"><?php echo htmlspecialchars($day['label']); ?></div>
              <div class="week-chart-day-total"><?php echo (int) $day['current']; ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="week-detail-grid">
        <div class="week-detail-card">
          <h3>Operational patterns</h3>
          <table>
            <tr><td>Busiest time slot</td><td><?php echo $busiestTimeSlot ? htmlspecialchars($busiestTimeSlot['label'] . ' (' . $busiestTimeSlot['count'] . ')') : 'No visits'; ?></td></tr>
            <tr><td>Active weeks in average</td><td><?php echo $activeWeeks; ?></td></tr>
            <tr><td>First recorded appointment</td><td><?php echo $firstAppointmentDate ? htmlspecialchars($firstAppointmentDate->format('j M Y')) : 'No data'; ?></td></tr>
          </table>
        </div>
        <div class="week-detail-card">
          <h3>Outcome rates</h3>
          <table>
            <tr><td>No-show rate</td><td>Week <?php echo number_format($currentWeekNoShowRate, 1); ?>% · Month <?php echo number_format($currentMonthNoShowRate, 1); ?>% · Year <?php echo number_format($currentYearNoShowRate, 1); ?>%</td></tr>
            <tr><td>Completed rate</td><td>Week <?php echo number_format($currentWeekCompletedRate, 1); ?>% · Month <?php echo number_format($currentMonthCompletedRate, 1); ?>% · Year <?php echo number_format($currentYearCompletedRate, 1); ?>%</td></tr>
          </table>
        </div>
        <div class="week-detail-card" style="grid-column:1 / -1;">
          <h3>Booking channel
            <select id="bookingChannelPeriod" style="font-size:.8rem; margin-left:8px; width:auto;">
              <option value="7">Last 7 days</option>
              <option value="30">Last 30 days</option>
              <option value="90" selected>Last 90 days</option>
            </select>
          </h3>
          <div class="customer-chart-content">
            <div id="bookingChannelPie" class="customer-pie" role="img" aria-label="Booking channel distribution" style="background:<?php echo htmlspecialchars($channelPie, ENT_QUOTES, 'UTF-8'); ?>"></div>
            <ul id="bookingChannelLegend" class="customer-chart-legend">
              <?php $channelIndex = 0; foreach ($channelCounts as $channelLabel => $channelCount): ?>
                <li>
                  <i class="customer-chart-swatch" style="background:<?php echo $channelColors[$channelIndex % count($channelColors)]; ?>"></i>
                  <span><?php echo htmlspecialchars($channelLabel); ?>: <?php echo (int) $channelCount; ?><?php echo $channelTotal > 0 ? ' (' . number_format(((int) $channelCount / $channelTotal) * 100, 0) . '%)' : ''; ?></span>
                </li>
                <?php $channelIndex++; ?>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
      <p class="week-modal-note">Rates use resolved appointments only: completed and no-show. Average weeks exclude weeks with no appointments, and the year comparison starts from the first recorded appointment.</p>
    </div>
  </div>

  <div class="week-modal customer-modal" id="customerStatsModal" role="dialog" aria-modal="true" aria-labelledby="customerStatsTitle" hidden>
    <div class="week-modal-dialog">
      <div class="week-modal-header">
        <div>
          <h2 id="customerStatsTitle">Customer statistics</h2>
          <p>Patient demographics, contact quality, and recent activity</p>
        </div>
        <button type="button" class="week-modal-close" id="customerStatsClose" aria-label="Close">Close</button>
      </div>

      <div class="customer-stat-grid">
        <div class="customer-stat"><strong><?php echo $customerStats['total']; ?></strong><span>Total customers</span></div>
        <div class="customer-stat"><strong><?php echo $customerStats['recent']; ?></strong><span>Added in 30 days</span></div>
        <div class="customer-stat"><strong><?php echo $customerStats['active']; ?></strong><span>Seen in 90 days</span></div>
        <div class="customer-stat"><strong><?php echo $customerStats['invalid_email']; ?></strong><span>Invalid email flags</span></div>
      </div>

      <div class="customer-chart-grid">
        <?php
        $customerChartDefinitions = [
          ['title' => 'Age', 'counts' => $customerAgeCounts, 'colors' => ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b', '#0ea5e9', '#f97316', '#475569'], 'pie' => $agePie],
          ['title' => 'Email quality', 'counts' => ['Unique' => $customerStats['unique_email'], 'Dummy / reused' => $customerStats['dummy_email'], 'Missing' => $customerStats['email_no']], 'colors' => ['#10b981', '#f59e0b', '#64748b'], 'pie' => $emailQualityPie],
          ['title' => 'Mobile available', 'counts' => ['Yes' => $customerStats['mobile_yes'], 'No' => $customerStats['mobile_no']], 'colors' => ['#2563eb', '#64748b'], 'pie' => $mobilePie],
          ['title' => 'Gender', 'counts' => $customerGenderCounts, 'colors' => ['#2563eb', '#ec4899', '#f59e0b', '#64748b'], 'pie' => $genderPie],
        ];
        foreach ($customerChartDefinitions as $customerChart):
          $customerChartTotal = array_sum($customerChart['counts']);
        ?>
          <div class="customer-chart-card">
            <h3><?php echo htmlspecialchars($customerChart['title']); ?></h3>
            <?php if ($customerChart['title'] === 'Age'): ?>
              <?php $customerAgeMax = max(1, ...array_values($customerChart['counts'])); ?>
              <div class="customer-age-bars" aria-label="Age distribution bar chart">
                <?php $customerAgeIndex = 0; foreach ($customerChart['counts'] as $customerLabel => $customerCount): ?>
                  <div class="customer-age-row">
                    <span><?php echo htmlspecialchars($customerLabel); ?></span>
                    <div class="customer-age-track"><i class="customer-age-fill" style="width:<?php echo ((int) $customerCount / $customerAgeMax) * 100; ?>%; background:<?php echo $customerChart['colors'][$customerAgeIndex % count($customerChart['colors'])]; ?>"></i></div>
                    <strong><?php echo (int) $customerCount; ?></strong>
                  </div>
                  <?php $customerAgeIndex++; ?>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="customer-chart-content">
                <div class="customer-pie" role="img" aria-label="<?php echo htmlspecialchars($customerChart['title']); ?> distribution" style="background:<?php echo htmlspecialchars($customerChart['pie'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <ul class="customer-chart-legend">
                  <?php $customerLegendIndex = 0; foreach ($customerChart['counts'] as $customerLabel => $customerCount): ?>
                    <li>
                      <i class="customer-chart-swatch" style="background:<?php echo $customerChart['colors'][$customerLegendIndex % count($customerChart['colors'])]; ?>"></i>
                      <span><?php echo htmlspecialchars($customerLabel); ?>: <?php echo (int) $customerCount; ?><?php echo $customerChartTotal > 0 ? ' (' . number_format(((int) $customerCount / $customerChartTotal) * 100, 0) . '%)' : ''; ?></span>
                    </li>
                    <?php $customerLegendIndex++; ?>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <p class="week-modal-note">An email reused by 10 or more customer records is classified as dummy/reused. Age excludes missing or future dates of birth. Provider accounts see customers linked to their appointments; administrators see the full customer base.</p>
    </div>
  </div>

  <script src="assets/js/global.js"></script>
  <script>
    (function () {
      var periodSelect = document.getElementById('bookingChannelPeriod');
      var pie = document.getElementById('bookingChannelPie');
      var legend = document.getElementById('bookingChannelLegend');
      if (!periodSelect || !pie || !legend) return;
      var periods = <?php echo json_encode($channelPeriods, 15); ?>;
      var colors = <?php echo json_encode($channelColors, 15); ?>;
      var labels = <?php echo json_encode(array_keys($channelCounts), 15); ?>;

      function render(period) {
        var counts = periods[period] || {};
        var values = labels.map(function (label) { return Number(counts[label] || 0); });
        var total = values.reduce(function (sum, value) { return sum + value; }, 0);
        var offset = 0;
        var segments = values.map(function (value, index) {
          var next = offset + (total ? value / total * 100 : 0);
          var segment = colors[index] + ' ' + offset + '% ' + next + '%';
          offset = next;
          return segment;
        });
        pie.style.background = total ? 'conic-gradient(' + segments.join(', ') + ')' : '#334155';
        legend.innerHTML = labels.map(function (label, index) {
          var percent = total ? Math.round(values[index] / total * 100) : 0;
          return '<li><i class="customer-chart-swatch" style="background:' + colors[index] + '"></i><span>' + label + ': ' + values[index] + (total ? ' (' + percent + '%)' : '') + '</span></li>';
        }).join('');
      }
      periodSelect.addEventListener('change', function () { render(periodSelect.value); });
    })();

    (function () {
      var openButton = document.getElementById('weekAtGlanceButton');
      var modal = document.getElementById('weekAtGlanceModal');
      var closeButton = document.getElementById('weekAtGlanceClose');
      if (!openButton || !modal || !closeButton) return;

      function closeModal() {
        modal.classList.remove('is-open');
        modal.hidden = true;
      }

      openButton.addEventListener('click', function () {
        modal.hidden = false;
        modal.classList.add('is-open');
        closeButton.focus();
      });
      closeButton.addEventListener('click', closeModal);
      modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
      });
    })();

    (function () {
      var openButton = document.getElementById('customerStatsButton');
      var modal = document.getElementById('customerStatsModal');
      var closeButton = document.getElementById('customerStatsClose');
      if (!openButton || !modal || !closeButton) return;

      function closeModal() {
        modal.classList.remove('is-open');
        modal.hidden = true;
      }

      openButton.addEventListener('click', function () {
        modal.hidden = false;
        modal.classList.add('is-open');
        closeButton.focus();
      });
      closeButton.addEventListener('click', closeModal);
      modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
      });
    })();
  </script>
</body>
</html>
