<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
init_session();

// Fetch system branding dynamically from settings registry
$siteName = trim((string) get_setting('site_name', APP_NAME ?? 'Boomy Appointments'));
if ($siteName === '') {
    $siteName = APP_NAME ?? 'Boomy Appointments';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> - Welcome</title>
  
  <link rel="stylesheet" href="assets/css/pico.min.css" onerror="this.onerror=null; this.href='https://unpkg.com/@picocss/pico@2.1.2/css/pico.min.css';">
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/admin-shared.css">
  
  <style>
    /* Flashy Animated Fluid Canvas Background using your existing theme tokens */
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      position: relative;
      overflow: hidden;
      background: linear-gradient(-45deg, #f4f7fb, #eef2f7, rgba(36, 87, 255, 0.08), rgba(245, 158, 11, 0.06));
      background-size: 400% 400%;
      animation: gradientFlow 15s ease infinite;
    }

    @media (prefers-color-scheme: dark) {
      body {
        background: linear-gradient(-45deg, #0b1220, #0f172a, rgba(36, 87, 255, 0.15), rgba(15, 23, 42, 0.8));
        background-size: 400% 400%;
        animation: gradientFlow 15s ease infinite;
      }
    }

    @keyframes gradientFlow {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    /* Central Grand Portal Layout Card */
    .splash-container {
      width: min(580px, calc(100% - 32px));
      padding: 40px;
      border: 1px solid rgba(18, 27, 43, 0.12);
      border-radius: 28px;
      background: rgba(255, 255, 255, 0.92);
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
      backdrop-filter: blur(18px);
      text-align: center;
      transform: translateY(0);
      animation: heroEntrance 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @media (prefers-color-scheme: dark) {
      .splash-container {
        background: rgba(15, 23, 42, 0.88);
        border: 1px solid rgba(255, 255, 255, 0.12);
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
      }
    }

    @keyframes heroEntrance {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .splash-eyebrow {
      display: inline-flex;
      padding: 6px 16px;
      border-radius: 999px;
      background: rgba(36, 87, 255, 0.08);
      color: #2457ff;
      font-size: 0.85rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      margin-bottom: 24px;
    }

    @media (prefers-color-scheme: dark) {
      .splash-eyebrow {
        background: rgba(124, 162, 255, 0.16);
        color: #7ca2ff;
      }
    }

    .splash-title {
      font-size: clamp(2.2rem, 5vw, 3rem);
      font-weight: 800;
      line-height: 1.1;
      letter-spacing: -0.04em;
      margin-bottom: 16px;
      color: inherit;
    }

    .splash-desc {
      color: #667085;
      font-size: 1.1rem;
      line-height: 1.6;
      margin-bottom: 32px;
    }

    @media (prefers-color-scheme: dark) {
      .splash-desc {
        color: #a8b3c7;
      }
      .splash-title {
        color: #2457ff;
      }
    }

    /* Grand Action Button with your internal custom classes */
    .btn-grand {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      padding: 16px 32px;
      font-size: 1.1rem;
      font-weight: 700;
      border-radius: 999px;
      background: linear-gradient(135deg, #2457ff, #1639b5);
      color: #ffffff;
      text-decoration: none;
      box-shadow: 0 8px 24px rgba(36, 87, 255, 0.25);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .btn-grand:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 32px rgba(36, 87, 255, 0.4);
      color: #ffffff;
    }

    .btn-grand:active {
      transform: translateY(0);
    }

    /* Hidden Admin Portal Backdoor Anchor */
    .backdoor-anchor {
      position: fixed;
      bottom: 16px;
      right: 16px;
      font-size: 0.75rem;
      color: transparent;
      text-decoration: none;
      user-select: none;
      cursor: default;
      transition: color 0.3s ease;
      z-index: 10;
    }

    /* Only turns subtly legible if an admin explicitly hovers directly over the tiny corner space */
    .backdoor-anchor:hover {
      color: rgba(102, 112, 133, 0.35);
      cursor: pointer;
    }

    @media (prefers-color-scheme: dark) {
      .backdoor-anchor:hover {
        color: rgba(168, 179, 199, 0.25);
      }
    }
  </style>
</head>
<body>

  <main class="splash-container">
    <div class="splash-eyebrow">Online Scheduler</div>
    <h1 class="splash-title"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="splash-desc">Welcome to our secure booking platform. Arrange your next appointment with our professional providers in just a few simple steps.</p>
    
    <a href="booking.php" class="btn-grand">
      Book an Appointment
    </a>
  </main>

  <a href="login.php" class="backdoor-anchor" title="System Administration Management Link">
    Management Console
  </a>

</body>
</html>