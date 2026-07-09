<?php
require_once __DIR__ . '/functions.php';
init_session();
require_login();

if (!function_exists('normalize_booking_calendar_height_setting')) {
  function normalize_booking_calendar_height_setting($value)
  {
    $raw = strtolower(trim((string) ($value ?? '')));
    if ($raw === '') {
      return 1800;
    }

    if ($raw === 'auto') {
      return 'auto';
    }

    if (preg_match('/^(\d+)(?:px)?$/', $raw, $matches)) {
      $height = (int) $matches[1];
      if ($height >= 600 && $height <= 10000) {
        return $height;
      }
    }

    return 1800;
  }
}

$user = $_SESSION['user'];

// Load existing settings
$site_name = get_setting('site_name', APP_NAME ?? 'Boomy Appointments');
$root_url = get_setting('root_url', '');
$org_email = get_setting('organization_email', '');
$org_number = get_setting('organization_number', '');
$date_format = get_setting('date_format', 'd/m/Y H:i');
$booking_deadline = get_setting('booking_deadline_hours', '24');
$booking_max_advance_days = (int) get_setting('booking_max_advance_days', '60');

// Booking fields
$firstname_display = (int) get_setting('booking_firstname_display', '1');
$firstname_required = (int) get_setting('booking_firstname_required', '1');
$firstname_letters_only = (int) get_setting('booking_firstname_letters_only', '0');
$firstname_min = (int) get_setting('booking_firstname_min', '2');
$firstname_max = (int) get_setting('booking_firstname_max', '50');

$lastname_display = (int) get_setting('booking_lastname_display', '1');
$lastname_required = (int) get_setting('booking_lastname_required', '1');
$lastname_letters_only = (int) get_setting('booking_lastname_letters_only', '0');
$lastname_min = (int) get_setting('booking_lastname_min', '2');
$lastname_max = (int) get_setting('booking_lastname_max', '50');

$middlename_display = (int) get_setting('booking_middlename_display', '0');
$middlename_required = (int) get_setting('booking_middlename_required', '0');
$middlename_letters_only = (int) get_setting('booking_middlename_letters_only', '0');
$middlename_min = (int) get_setting('booking_middlename_min', '2');
$middlename_max = (int) get_setting('booking_middlename_max', '50');

$email_display = (int) get_setting('booking_email_display', '1');
$email_required = (int) get_setting('booking_email_required', '1');

$phone_display = (int) get_setting('booking_phone_display', '1');
$phone_required = (int) get_setting('booking_phone_required', '0');
$phone_numbers_only = (int) get_setting('booking_phone_numbers_only', '1');
$phone_min = (int) get_setting('booking_phone_min', '10');
$phone_max = (int) get_setting('booking_phone_max', '20');

$dob_display = (int) get_setting('booking_dob_display', '1');
$dob_required = (int) get_setting('booking_dob_required', '0');

$gender_display = (int) get_setting('booking_gender_display', '1');
$gender_required = (int) get_setting('booking_gender_required', '0');

$booking_show_monday = (int) get_setting('booking_show_monday', '1');
$booking_show_tuesday = (int) get_setting('booking_show_tuesday', '1');
$booking_show_wednesday = (int) get_setting('booking_show_wednesday', '1');
$booking_show_thursday = (int) get_setting('booking_show_thursday', '1');
$booking_show_friday = (int) get_setting('booking_show_friday', '1');
$booking_show_saturday = (int) get_setting('booking_show_saturday', '0');
$booking_show_sunday = (int) get_setting('booking_show_sunday', '0');
$booking_start_time = get_setting('booking_start_time', '09:00');
$booking_end_time = get_setting('booking_end_time', '21:00');
$booking_week_start_day = normalize_booking_week_start_day_setting(get_setting('booking_week_start_day', '0'));
$booking_calendar_height = normalize_booking_calendar_height_setting(get_setting('booking_calendar_height', '1800'));
$booking_show_now_indicator = (int) get_setting('booking_show_now_indicator', '1');
$booking_snap_minutes = (int) get_setting('booking_snap_minutes', '15');
$booking_snap_options = [1, 2, 5, 10, 15, 20, 30, 40, 45, 60];
$auto_no_show = (int) get_setting('auto_no_show', '0');
$prevent_same_user_booking = (int) get_setting('prevent_same_user_booking', '0');
$appointmentTypes = get_appointment_types();
$disable_online_booking = (int) get_setting('disable_online_booking', '0');

// PHPMailer/Gmail settings
$smtp_host = get_setting('smtp_host', 'smtp.gmail.com');
$smtp_port = (int) get_setting('smtp_port', '587');
$smtp_encryption = get_setting('smtp_encryption', 'tls');
$smtp_username = get_setting('smtp_username', '');
$smtp_password = get_setting('smtp_password', '');
$mail_from_address = get_setting('mail_from_address', '');
$mail_from_name = get_setting('mail_from_name', APP_NAME ?? 'Boomy Appointments');
$notify_admin_on_booking = (int) get_setting('notify_admin_on_booking', '0');

// Authentication settings
$password_min_length = (int) get_setting('password_min_length', '8');
$password_max_length = (int) get_setting('password_max_length', '128');
$password_require_lowercase = (int) get_setting('password_require_lowercase', '1');
$password_require_uppercase = (int) get_setting('password_require_uppercase', '1');
$password_require_number = (int) get_setting('password_require_number', '1');
$password_require_symbol = (int) get_setting('password_require_symbol', '1');
$email_whitelist_enabled = (int) get_setting('email_whitelist_enabled', '0');
$email_domain_whitelist = get_setting('email_domain_whitelist', '');
$email_domain_blacklist = get_setting('email_domain_blacklist', '');

$reminder_enabled     = (int) get_setting('reminder_enabled', '0');
$reminder_days_before = (int) get_setting('reminder_days_before', '1');
$reminder_time        = get_setting('reminder_time', '09:00');
$smtp_ready = (!empty($smtp_host) && !empty($smtp_username) && !empty($smtp_password));

$booking_step1_message = get_setting('booking_step1_message', '');
$booking_step4_message = get_setting('booking_step4_message', '');
$confirmation_email_message = get_setting('confirmation_email_message', '');
$reminder_email_message = get_setting('reminder_email_message', '');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Settings - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="assets/css/pico.min.css" id="pico-css" onerror="this.onerror=null; this.href='https://unpkg.com/@picocss/pico@2.*/css/pico.min.css';">
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/admin-shared.css">
  <style>
    .tabs { display:flex; gap:8px; margin-bottom:16px; flex-wrap: wrap; }
    .tab { cursor:pointer; padding: 8px 12px; }
    .tab.active { font-weight:700; text-decoration: underline; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }
    .field-group { margin-bottom: 20px; border: 1px solid #ccc; padding: 12px; border-radius: 4px; }
    .field-group legend { font-weight: bold; font-size: 1.1em; margin-bottom: 10px; }
    .field-controls { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .success-msg { color: green; margin-top: 8px; }
    .error-msg { color: red; margin-top: 8px; }
    .appointment-types-table td, .appointment-types-table th { vertical-align: middle; }
    .appointment-types-table input[type="text"],
    .appointment-types-table input[type="number"] { width: 100%; }
    .appointment-types-table input[type="color"] { width: 100%; min-width: 72px; height: 40px; padding: 2px; }
  </style>
</head>
<body>
  <?php $current_page = 'settings.php'; include __DIR__ . '/assets/nav.html'; ?>

  <main class="container">
  <header style="margin-bottom: 24px;">
    <h1>Settings</h1>
    <p>Signed in as <?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></p>
  </header>

  <nav class="tabs" role="tablist">
    <button class="tab active" data-tab="general" role="tab">General</button>
    <button class="tab" data-tab="customer_registration" role="tab">Customer Registration</button>
    <button class="tab" data-tab="booking" role="tab">Booking</button>
    <button class="tab" data-tab="authentication" role="tab">Authentication</button>
    <button class="tab" data-tab="sessions" role="tab">Sessions</button>
    <button class="tab" data-tab="phpmailer" role="tab">PHPMailer</button>
    <button class="tab" data-tab="login_attempts" role="tab">Login Attempts</button>
  </nav>

  <!-- GENERAL TAB -->
  <section id="tab-general" class="tab-panel active">
    <form id="generalForm" data-api data-no-reload="true" action="settings_api.php?action=save" method="post">
      <label for="site_name">Website Name</label>
      <input id="site_name" name="site_name" type="text" value="<?php echo htmlspecialchars($site_name); ?>" required>

      <label for="root_url">Root URL</label>
      <input id="root_url" name="root_url" type="url" value="<?php echo htmlspecialchars($root_url); ?>" placeholder="https://example.com/appointments">
      <small>Used for links in emails. Leave blank to use the current request URL automatically.</small>

      <label for="organization_email">Organisation Email</label>
      <input id="organization_email" name="organization_email" type="email" value="<?php echo htmlspecialchars($org_email); ?>" required>

      <label for="organization_number">Organisation Number</label>
      <input id="organization_number" name="organization_number" type="text" value="<?php echo htmlspecialchars($org_number); ?>" maxlength="64" placeholder="e.g. mobile number or landline">

      <label for="date_format">Time Format</label>
      <select id="date_format" name="date_format">
        <option value="d/m/Y H:i" <?php echo $date_format === 'd/m/Y H:i' ? 'selected' : ''; ?>>DD/MM/YYYY HH:MM</option>
        <option value="Y/m/d H:i" <?php echo $date_format === 'Y/m/d H:i' ? 'selected' : ''; ?>>YYYY/MM/DD HH:MM</option>
        <option value="m/d/Y H:i" <?php echo $date_format === 'm/d/Y H:i' ? 'selected' : ''; ?>>MM/DD/YYYY HH:MM</option>
      </select>

      <?php
      $appTz = new DateTimeZone(get_app_timezone_name());
      $appOffsetSeconds = $appTz->getOffset(new DateTimeImmutable('now', $appTz));
      ?>
      <p style="margin-bottom:4px;">
        <strong>Server time (<?php echo htmlspecialchars(get_app_timezone_name()); ?>):</strong>
        <span id="serverTimeDisplay"
              data-server-date-format="<?php echo htmlspecialchars($date_format); ?>"
              data-server-epoch-ms="<?php echo (int) (microtime(true) * 1000); ?>"
              data-server-offset-seconds="<?php echo (int) $appOffsetSeconds; ?>">
          <?php echo date($date_format); ?>
        </span>
      </p>
      <p style="margin-top:0;">
        <strong>Your browser time:</strong>
        <span id="browserTimeDisplay"></span>
      </p>
      <input type="hidden" name="tab" value="general">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button type="submit">Save General</button>
      <div id="generalMsg"></div>
    </form>
  </section>

  <!-- CUSTOMER REGISTRATION TAB -->
  <section id="tab-customer_registration" class="tab-panel">
    <form id="customerRegistrationForm" data-api data-no-reload="true" action="settings_api.php?action=save" method="post">
      <fieldset class="field-group">
        <legend>First Name</legend>
        <div class="field-controls">
          <label><input type="checkbox" name="booking_firstname_display" value="1" <?php echo $firstname_display ? 'checked' : ''; ?>> Display</label>
          <label><input type="checkbox" name="booking_firstname_required" value="1" <?php echo $firstname_required ? 'checked' : ''; ?> id="fname_required"> Required</label>
          <label><input type="checkbox" name="booking_firstname_letters_only" value="1" <?php echo $firstname_letters_only ? 'checked' : ''; ?>> Letters only</label>
          <div></div>
          <label>Min length <input type="number" name="booking_firstname_min" min="1" value="<?php echo $firstname_min; ?>"></label>
          <label>Max length <input type="number" name="booking_firstname_max" min="1" value="<?php echo $firstname_max; ?>"></label>
        </div>
      </fieldset>

      <fieldset class="field-group">
        <legend>Last Name</legend>
        <div class="field-controls">
          <label><input type="checkbox" name="booking_lastname_display" value="1" <?php echo $lastname_display ? 'checked' : ''; ?>> Display</label>
          <label><input type="checkbox" name="booking_lastname_required" value="1" <?php echo $lastname_required ? 'checked' : ''; ?> id="lname_required"> Required</label>
          <label><input type="checkbox" name="booking_lastname_letters_only" value="1" <?php echo $lastname_letters_only ? 'checked' : ''; ?>> Letters only</label>
          <div></div>
          <label>Min length <input type="number" name="booking_lastname_min" min="1" value="<?php echo $lastname_min; ?>"></label>
          <label>Max length <input type="number" name="booking_lastname_max" min="1" value="<?php echo $lastname_max; ?>"></label>
        </div>
      </fieldset>

      <fieldset class="field-group">
        <legend>Middle Name</legend>
        <div class="field-controls">
          <label><input type="checkbox" name="booking_middlename_display" value="1" <?php echo $middlename_display ? 'checked' : ''; ?>> Display</label>
          <label><input type="checkbox" name="booking_middlename_required" value="1" <?php echo $middlename_required ? 'checked' : ''; ?> id="mname_required"> Required</label>
          <label><input type="checkbox" name="booking_middlename_letters_only" value="1" <?php echo $middlename_letters_only ? 'checked' : ''; ?>> Letters only</label>
          <div></div>
          <label>Min length <input type="number" name="booking_middlename_min" min="1" value="<?php echo $middlename_min; ?>"></label>
          <label>Max length <input type="number" name="booking_middlename_max" min="1" value="<?php echo $middlename_max; ?>"></label>
        </div>
      </fieldset>

      <fieldset class="field-group">
        <legend>Email</legend>
        <div class="field-controls">
          <label><input type="checkbox" name="booking_email_display" value="1" <?php echo $email_display ? 'checked' : ''; ?>> Display</label>
          <label><input type="checkbox" name="booking_email_required" value="1" <?php echo $email_required ? 'checked' : ''; ?> id="email_required"> Required</label>
        </div>
        <p style="font-size: 0.9em; color: #666;"><em>Email domain validation (whitelist/blacklist) is configured in the <strong>Authentication</strong> tab.</em></p>
      </fieldset>

      <fieldset class="field-group">
        <legend>Phone Number</legend>
        <div class="field-controls">
          <label><input type="checkbox" name="booking_phone_display" value="1" <?php echo $phone_display ? 'checked' : ''; ?>> Display</label>
          <label><input type="checkbox" name="booking_phone_required" value="1" <?php echo $phone_required ? 'checked' : ''; ?> id="phone_required"> Required</label>
          <label><input type="checkbox" name="booking_phone_numbers_only" value="1" <?php echo $phone_numbers_only ? 'checked' : ''; ?>> Numbers and symbols only</label>
          <div></div>
          <label>Min length <input type="number" name="booking_phone_min" min="1" value="<?php echo $phone_min; ?>"></label>
          <label>Max length <input type="number" name="booking_phone_max" min="1" value="<?php echo $phone_max; ?>"></label>
        </div>
      </fieldset>

      <fieldset class="field-group">
        <legend>Date of Birth</legend>
        <div class="field-controls">
          <label><input type="checkbox" name="booking_dob_display" value="1" <?php echo $dob_display ? 'checked' : ''; ?>> Display</label>
          <label><input type="checkbox" name="booking_dob_required" value="1" <?php echo $dob_required ? 'checked' : ''; ?>> Required</label>
        </div>
      </fieldset>

      <fieldset class="field-group">
        <legend>Gender</legend>
        <div class="field-controls">
          <label><input type="checkbox" name="booking_gender_display" value="1" <?php echo $gender_display ? 'checked' : ''; ?>> Display</label>
          <label><input type="checkbox" name="booking_gender_required" value="1" <?php echo $gender_required ? 'checked' : ''; ?>> Required</label>
        </div>
        <p style="font-size: 0.9em; color: #666;">Gender options are male, female, other, and unknown.</p>
      </fieldset>

      <input type="hidden" name="tab" value="customer_registration">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button type="submit">Save Customer Registration</button>
      <div id="customerRegistrationMsg"></div>
    </form>
  </section>

  <!-- BOOKING TAB -->
  <section id="tab-booking" class="tab-panel">
    <form id="bookingForm" data-api data-no-reload="true" action="settings_api.php?action=save" method="post">
      <fieldset class="field-group">
        <legend>Days to Show</legend>
        <div class="field-controls">
          <label><input type="checkbox" name="booking_show_monday" value="1" <?php echo $booking_show_monday ? 'checked' : ''; ?>> Monday</label>
          <label><input type="checkbox" name="booking_show_tuesday" value="1" <?php echo $booking_show_tuesday ? 'checked' : ''; ?>> Tuesday</label>
          <label><input type="checkbox" name="booking_show_wednesday" value="1" <?php echo $booking_show_wednesday ? 'checked' : ''; ?>> Wednesday</label>
          <label><input type="checkbox" name="booking_show_thursday" value="1" <?php echo $booking_show_thursday ? 'checked' : ''; ?>> Thursday</label>
          <label><input type="checkbox" name="booking_show_friday" value="1" <?php echo $booking_show_friday ? 'checked' : ''; ?>> Friday</label>
          <label><input type="checkbox" name="booking_show_saturday" value="1" <?php echo $booking_show_saturday ? 'checked' : ''; ?>> Saturday</label>
          <label><input type="checkbox" name="booking_show_sunday" value="1" <?php echo $booking_show_sunday ? 'checked' : ''; ?>> Sunday</label>
        </div>
      </fieldset>
      <fieldset class="field-group">
        <legend>Booking Restrictions</legend>
        <label for="prevent_same_user_booking">Max Active Bookings Per Customer</label>
        <input id="prevent_same_user_booking" name="prevent_same_user_booking" type="number" min="0" step="1" value="<?php echo $prevent_same_user_booking; ?>" required>
        <p style="font-size: 0.9em; color: #666; margin-top: 4px;">
          Limits how many concurrent active bookings (unconfirmed, confirmed, booked) a single profile can hold. Set to <strong>0</strong> to completely disable this rule and allow unlimited concurrent appointments.
        </p>
      </fieldset>
      <fieldset class="field-group">
        <legend>Online Availability Control</legend>
        <label>
          <input type="hidden" name="disable_online_booking" value="0">
          <input type="checkbox" name="disable_online_booking" value="1" <?php echo $disable_online_booking === 1 ? 'checked' : ''; ?>>
          <strong>Disable public online booking</strong>
        </label>
        <p style="font-size: 0.9em; color: #666; margin-top: 4px;">
          When checked, members of the public cannot initiate new appointments through the web booking interface. Internal operations executed directly from the administrative workspace calendar remain operational.
        </p>
      </fieldset>
      <fieldset class="field-group">
        <legend>Admin Notifications</legend>
        <label>
          <input type="hidden" name="notify_admin_on_booking" value="0">
          <input type="checkbox" name="notify_admin_on_booking" value="1" <?php echo $notify_admin_on_booking === 1 ? 'checked' : ''; ?>>
          <strong>Email the admin when a customer makes a booking</strong>
        </label>
        <p style="font-size: 0.9em; color: #666; margin-top: 4px;">
          Sends a notification to the <strong>Organisation Email</strong> (set on the General tab) whenever a customer creates a new appointment online — including pending bookings awaiting verification.
        </p>
      </fieldset>
      <fieldset class="field-group">
        <legend>Automated Catch-up Maintenance</legend>
        <label>
          <input type="hidden" name="auto_no_show" value="0">
          <input type="checkbox" name="auto_no_show" value="1" <?php echo $auto_no_show === 1 ? 'checked' : ''; ?>> 
          Automatically transition expired appointments to "No Show" status
        </label>
        <p style="font-size: 0.9em; color: #666; margin-top: 4px;">
          When checked, any appointment left in an unconfirmed, confirmed, or booked state whose end-time has drifted past the current server time will be updated automatically during page load.
        </p>
      </fieldset>

      <fieldset class="field-group">
        <legend>Live Time Marker</legend>
        <label><input type="checkbox" name="booking_show_now_indicator" value="1" <?php echo $booking_show_now_indicator ? 'checked' : ''; ?>> Show red line for the current time</label>
      </fieldset>
      <fieldset class="field-group">
        <legend>Standard Breaks</legend>
        <p style="font-size: 0.9em; color: #666; margin-bottom: 12px;">Choose a day to view or create recurring breaks. Leave it blank to work with everyday breaks.</p>
        <div class="field-controls" style="align-items: end; gap: 12px;">
          <label>
            Day
            <select id="breakDaySelect">
              <option value="">Every day</option>
              <option value="1">Monday</option>
              <option value="2">Tuesday</option>
              <option value="3">Wednesday</option>
              <option value="4">Thursday</option>
              <option value="5">Friday</option>
              <option value="6">Saturday</option>
              <option value="7">Sunday</option>
            </select>
          </label>
          <label>Start time <input type="time" id="breakStartTime" value="12:00"></label>
          <label>End time <input type="time" id="breakEndTime" value="13:00"></label>
          <button type="button" id="createBreakBtn">Create Break</button>
        </div>
        <div id="breaksMsg" style="margin-top: 12px;"></div>
        <div style="overflow-x:auto; margin-top: 12px;">
          <table class="admin-table" style="min-width: 720px; margin-top: 0;">
            <thead>
              <tr>
                <th style="width: 180px;">Day</th>
                <th style="width: 160px;">Start</th>
                <th style="width: 160px;">End</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="breaksTableBody">
              <tr><td colspan="4">Loading breaks...</td></tr>
            </tbody>
          </table>
        </div>
      </fieldset>

      <fieldset class="field-group">
        <legend>Visible Time Range</legend>
        <div class="field-controls">
          <label>Booking deadline (hours) <input type="number" name="booking_deadline_hours" min="0" step="1" value="<?php echo htmlspecialchars($booking_deadline); ?>"></label>
          <label>Max advance booking (days) <input type="number" name="booking_max_advance_days" min="1" step="1" value="<?php echo (int) $booking_max_advance_days; ?>"></label>
          <label>Default start time <input type="time" name="booking_start_time" value="<?php echo htmlspecialchars($booking_start_time); ?>"></label>
          <label>Default end time <input type="time" name="booking_end_time" value="<?php echo htmlspecialchars($booking_end_time); ?>"></label>
        </div>
        <p style="font-size: 0.9em; color: #666;">The calendar will only render this time range in day and week views.</p>
      </fieldset>

      <fieldset class="field-group">
        <legend>Week Start</legend>
        <label for="booking_week_start_day">Start of week</label>
        <select id="booking_week_start_day" name="booking_week_start_day">
          <option value="0" <?php echo $booking_week_start_day === 0 ? 'selected' : ''; ?>>Sunday</option>
          <option value="1" <?php echo $booking_week_start_day === 1 ? 'selected' : ''; ?>>Monday</option>
          <option value="2" <?php echo $booking_week_start_day === 2 ? 'selected' : ''; ?>>Tuesday</option>
          <option value="3" <?php echo $booking_week_start_day === 3 ? 'selected' : ''; ?>>Wednesday</option>
          <option value="4" <?php echo $booking_week_start_day === 4 ? 'selected' : ''; ?>>Thursday</option>
          <option value="5" <?php echo $booking_week_start_day === 5 ? 'selected' : ''; ?>>Friday</option>
          <option value="6" <?php echo $booking_week_start_day === 6 ? 'selected' : ''; ?>>Saturday</option>
        </select>
        <p style="font-size: 0.9em; color: #666;">This controls the first column in week view. Sunday is the default.</p>
      </fieldset>

      <fieldset class="field-group">
        <legend>Calendar Height</legend>
        <label for="booking_calendar_height">Calendar height</label>
        <input id="booking_calendar_height" name="booking_calendar_height" type="text" value="<?php echo htmlspecialchars((string) $booking_calendar_height); ?>" placeholder="auto or 1800">
        <p style="font-size: 0.9em; color: #666;">Use <strong>auto</strong> or a value between 600 and 10000 pixels.</p>
      </fieldset>

      <fieldset class="field-group">
        <legend>Appointment Snap Window</legend>
        <label for="booking_snap_minutes">Snap interval</label>
        <select id="booking_snap_minutes" name="booking_snap_minutes">
          <?php foreach ($booking_snap_options as $optionMinutes): ?>
            <option value="<?php echo (int) $optionMinutes; ?>" <?php echo $booking_snap_minutes === (int) $optionMinutes ? 'selected' : ''; ?>>
              <?php echo (int) $optionMinutes; ?> minute<?php echo (int) $optionMinutes === 1 ? '' : 's'; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p style="font-size: 0.9em; color: #666;">Calendar clicks and drag selection will snap to this interval. The default is 15 minutes.</p>
      </fieldset>

      <fieldset class="field-group">
        <legend>Live Time Marker</legend>
        <label><input type="checkbox" name="booking_show_now_indicator" value="1" <?php echo $booking_show_now_indicator ? 'checked' : ''; ?>> Show red line for the current time</label>
      </fieldset>

      <fieldset class="field-group">
      <legend>Custom Booking Messages</legend>
      <label for="booking_step1_message">Step 1 message (shown when choosing appointment type/provider)</label>
      <textarea id="booking_step1_message" name="booking_step1_message" rows="2" placeholder="e.g. All appointments are free of charge"><?php echo htmlspecialchars($booking_step1_message); ?></textarea>

      <label for="booking_step4_message" style="margin-top:12px; display:block;">Step 4 message (shown on the summary/review step)</label>
      <textarea id="booking_step4_message" name="booking_step4_message" rows="2" placeholder="e.g. Please bring your items with you"><?php echo htmlspecialchars($booking_step4_message); ?></textarea>
      <p style="font-size: 0.9em; color: #666; margin-top: 4px;">
        Leave blank to hide. Both messages are shown to customers on the public booking page.
      </p>
    </fieldset>

      <fieldset class="field-group">
        <legend>Appointment Types</legend>
        <p style="font-size: 0.9em; color: #666; margin-bottom: 12px;">Manage the types used by the calendar. New types default to 30 minutes unless you change the duration.</p>
        <div style="overflow-x:auto;">
          <table class="admin-table appointment-types-table" style="min-width: 760px; margin-top: 0;">
            <thead>
              <tr>
                <th style="width: 88px;">Delete</th>
                <th>Appointment type name</th>
                <th style="width: 180px;">Default duration</th>
                <th style="width: 180px;">Appointment colour</th>
                <th style="width: 88px;">Active</th>
                <th style="width: 88px;">Bookable</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($appointmentTypes as $appointmentType): ?>
                <?php $typeId = (int) $appointmentType['id']; ?>
                <tr>
                  <td>
                    <label style="display:inline-flex; align-items:center; gap:6px; font-weight:600; margin:0; color: var(--muted);">
                      <input type="checkbox" name="appt_type_<?php echo $typeId; ?>_delete" value="1">
                      Delete
                    </label>
                  </td>
                  <td>
                    <input type="text" name="appt_type_<?php echo $typeId; ?>_name" value="<?php echo htmlspecialchars($appointmentType['name']); ?>" required>
                  </td>
                  <td>
                    <input type="number" name="appt_type_<?php echo $typeId; ?>_duration_minutes" min="1" step="1" value="<?php echo (int) $appointmentType['duration_minutes']; ?>" required>
                  </td>
                  <td>
                    <input type="color" name="appt_type_<?php echo $typeId; ?>_color_hex" value="<?php echo htmlspecialchars($appointmentType['color_hex'] ?: '#2457ff'); ?>">
                  </td>
                  <td>
                    <label style="display:inline-flex; align-items:center; gap:6px; font-weight:600; margin:0; color: var(--muted);">
                      <input type="checkbox" name="appt_type_<?php echo $typeId; ?>_is_active" value="1" <?php echo !empty($appointmentType['is_active']) ? 'checked' : ''; ?>>
                      Active
                    </label>
                  </td>
                  <td>
                    <label style="display:inline-flex; align-items:center; gap:6px; font-weight:600; margin:0; color: var(--muted);">
                      <input type="checkbox" name="appt_type_<?php echo $typeId; ?>_is_bookable" value="1" <?php echo !empty($appointmentType['is_bookable']) ? 'checked' : ''; ?>>
                      Bookable
                    </label>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr>
                <td></td>
                <td>
                  <input type="text" name="new_appt_type_name" value="" placeholder="New appointment type name">
                </td>
                <td>
                  <input type="number" name="new_appt_type_duration_minutes" min="1" step="1" value="30">
                </td>
                <td>
                  <input type="color" name="new_appt_type_color_hex" value="#2457ff">
                </td>
                <td>
                  <label style="display:inline-flex; align-items:center; gap:6px; font-weight:600; margin:0; color: var(--muted);">
                    <input type="checkbox" name="new_appt_type_is_active" value="1" checked>
                    Active
                  </label>
                </td>
                <td>
                  <label style="display:inline-flex; align-items:center; gap:6px; font-weight:600; margin:0; color: var(--muted);">
                    <input type="checkbox" name="new_appt_type_is_bookable" value="1" checked>
                    Bookable
                  </label>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </fieldset>

      <input type="hidden" name="tab" value="booking">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button type="submit">Save Booking &amp; Types</button>
      <div id="bookingMsg"></div>
    </form>
  </section>

  <!-- AUTHENTICATION TAB -->
  <section id="tab-authentication" class="tab-panel">
    <form id="authenticationForm" data-api data-no-reload="true" action="settings_api.php?action=save" method="post">
      <fieldset class="field-group">
        <legend>Email Validation</legend>
        <label><input type="checkbox" name="email_whitelist_enabled" value="1" <?php echo $email_whitelist_enabled ? 'checked' : ''; ?>> Enable email domain whitelist</label>
        
        <label for="email_domain_whitelist">Allowed domains (one per line)</label>
        <textarea id="email_domain_whitelist" name="email_domain_whitelist" rows="4" placeholder="gmail.com&#10;outlook.com&#10;example.com"><?php echo htmlspecialchars($email_domain_whitelist); ?></textarea>
        
        <label for="email_domain_blacklist">Blocked domains (one per line)</label>
        <textarea id="email_domain_blacklist" name="email_domain_blacklist" rows="4" placeholder="tempmail.com&#10;throwaway.com"><?php echo htmlspecialchars($email_domain_blacklist); ?></textarea>
        <p style="font-size: 0.9em; color: #666;"><em>These rules apply to registration and password recovery flows, not login.</em></p>
      </fieldset>

      <fieldset class="field-group">
        <legend>Password Requirements</legend>
        <div class="field-controls">
          <label>Min length <input type="number" name="password_min_length" min="1" max="128" value="<?php echo $password_min_length; ?>"></label>
          <label>Max length <input type="number" name="password_max_length" min="1" max="128" value="<?php echo $password_max_length; ?>"></label>
        </div>
        <div style="margin-top: 12px;">
          <label><input type="checkbox" name="password_require_lowercase" value="1" <?php echo $password_require_lowercase ? 'checked' : ''; ?>> Require lowercase letter (a-z)</label>
          <label><input type="checkbox" name="password_require_uppercase" value="1" <?php echo $password_require_uppercase ? 'checked' : ''; ?>> Require uppercase letter (A-Z)</label>
          <label><input type="checkbox" name="password_require_number" value="1" <?php echo $password_require_number ? 'checked' : ''; ?>> Require number (0-9)</label>
          <label><input type="checkbox" name="password_require_symbol" value="1" <?php echo $password_require_symbol ? 'checked' : ''; ?>> Require symbol (!@#$%...)</label>
        </div>
      </fieldset>

      <input type="hidden" name="tab" value="authentication">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button type="submit">Save Authentication</button>
      <div id="authenticationMsg"></div>
    </form>

    <hr>
    <form id="changePasswordForm" data-api data-no-reload="true" action="settings_api.php?action=change_password" method="post">
      <fieldset class="field-group">
        <legend>Change Your Password</legend>
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" required>

        <label for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" required>

        <label for="new_password_confirm">Confirm new password</label>
        <input id="new_password_confirm" name="new_password_confirm" type="password" required>
        
        <div id="changePasswordHints" style="margin-top:8px;"></div>

        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <button type="submit">Change Password</button>
        <div id="changePasswordMsg"></div>
      </fieldset>
    </form>

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        if (!window.App || !window.App.attachPasswordHints) return;
        const pwReq = <?php echo json_encode(get_password_requirements()); ?>;
        const form = document.getElementById('changePasswordForm');
        if (form) {
          App.attachPasswordHints(form, '#new_password', '#new_password_confirm', '#changePasswordHints', pwReq);
        }
      });
    </script>
  </section>
<!-- SESSIONS TAB -->
<section id="tab-sessions" class="tab-panel">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 style="margin:0 0 4px;">Active Sessions</h2>
      <p style="margin:0; color:#666; font-size:0.9em;">Your currently active login sessions. If both IP and user-agent change simultaneously, the session is automatically invalidated.</p>
    </div>
    <button type="button" id="revokeAllSessionsBtn" class="danger-btn">Revoke all other sessions</button>
  </div>

  <form id="sessionLifetimeForm" data-api data-no-reload="true" action="settings_api.php?action=save" method="post" style="margin-bottom:24px;">
    <fieldset class="field-group">
      <legend>Session Lifetime</legend>
      <label for="session_lifetime_hours">Lifetime (hours)</label>
      <input id="session_lifetime_hours" name="session_lifetime_hours" type="number" min="1" max="2000"
             value="<?php echo (int) get_setting('session_lifetime_hours', '18'); ?>">
      <p style="font-size:0.9em; color:#666; margin-top:4px;">
        Sessions expire if inactive for this many hours. Set to <strong>2000</strong> to effectively never expire.
        Active sessions extend automatically on each request.
      </p>
      <input type="hidden" name="tab" value="sessions">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button type="submit" style="margin-top:12px;">Save Session Settings</button>
      <div id="sessionsMsg"></div>
    </fieldset>
  </form>

  <div id="sessionsTableContainer">Loading sessions...</div>
</section>
  <!-- PHPMAILER TAB -->
  <section id="tab-phpmailer" class="tab-panel">
    <form id="phpmailerForm" data-api data-no-reload="true" action="settings_api.php?action=save" method="post">
      <label for="smtp_host">SMTP Host</label>
      <input id="smtp_host" name="smtp_host" type="text" value="<?php echo htmlspecialchars($smtp_host); ?>" placeholder="smtp.gmail.com" required>

      <label for="smtp_port">SMTP Port</label>
      <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?php echo $smtp_port; ?>" required>

      <label for="smtp_encryption">Encryption</label>
      <select id="smtp_encryption" name="smtp_encryption" required>
        <option value="tls" <?php echo $smtp_encryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
        <option value="ssl" <?php echo $smtp_encryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
        <option value="none" <?php echo $smtp_encryption === 'none' ? 'selected' : ''; ?>>None</option>
      </select>

      <label for="smtp_username">Username (usually email)</label>
      <input id="smtp_username" name="smtp_username" type="text" value="<?php echo htmlspecialchars($smtp_username); ?>" placeholder="your-email@gmail.com" required>

      <label for="smtp_password">Password (use app password for Gmail)</label>
      <input id="smtp_password" name="smtp_password" type="password" value="<?php echo htmlspecialchars($smtp_password); ?>" placeholder="••••••••">

      <label for="mail_from_address">From Email Address</label>
      <input id="mail_from_address" name="mail_from_address" type="email" value="<?php echo htmlspecialchars($mail_from_address); ?>" placeholder="noreply@example.com" required>

      <label for="mail_from_name">From Name</label>
      <input id="mail_from_name" name="mail_from_name" type="text" value="<?php echo htmlspecialchars($mail_from_name); ?>" placeholder="Boomy Appointments" required>

      <input type="hidden" name="tab" value="phpmailer">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button type="submit">Save PHPMailer</button>
      <div id="phpmailerMsg"></div>

      <hr>
      <h3>Test Email Configuration</h3>
      <p>Send a test email to verify your SMTP settings are correct.</p>
      <button type="button" id="testEmailBtn" onclick="testEmailConfiguration(event)">Send Test Email</button>
      <div id="testEmailMsg"></div>
    </form>
      <hr>
      <h3>Confirmation Email Message</h3>
      <label for="confirmation_email_message">Custom message added to booking confirmation emails</label>
      <textarea id="confirmation_email_message" name="confirmation_email_message" rows="3" form="phpmailerForm" placeholder="e.g. We have a cancellation fee"><?php echo htmlspecialchars($confirmation_email_message); ?></textarea>
      <p style="font-size: 0.9em; color: #666; margin-top: 4px;">Leave blank to omit.</p>
      <hr>
      <h3>Appointment Reminders</h3>
 
      <?php if (!$smtp_ready): ?>
        <div class="notice is-warning" style="margin-bottom:20px;">
          ⚠️ Reminder emails require SMTP to be fully configured (host, username, password, and PHPMailer files present). Configure and save those settings first.
        </div>
      <?php endif; ?>
 
      <form id="reminderForm" data-api data-no-reload="true"
            action="settings_api.php?action=save" method="post"
            <?php echo !$smtp_ready ? 'style="opacity:.55;pointer-events:none;"' : ''; ?>>
 
        <fieldset class="field-group">
          <legend>Email Reminders</legend>
          <label>
            <input type="hidden" name="reminder_enabled" value="0">
            <input type="checkbox" name="reminder_enabled" value="1"
                   id="reminderEnabledCheck"
                   <?php echo $reminder_enabled ? 'checked' : ''; ?>
                   <?php echo !$smtp_ready ? 'disabled' : ''; ?>>
            Enable appointment reminder emails
          </label>
          <p style="font-size:.9em;color:#666;margin-top:4px;">
            Sends each customer a reminder — including a one-click cancel link — a set number of days before their appointment.
            Requires <code>cron_reminders.php</code> to be called by your cron job once per hour. <code>5 * * * * php /config/www/appointments/cron_reminders.php >> /var/log/cron_reminders.log 2>&1</code>
          </p>
 
          <div class="field-controls" style="margin-top:16px;" id="reminderOptions">
            <label>Days before appointment
              <input type="number" name="reminder_days_before" min="0" max="30" step="1"
                     value="<?php echo (int) $reminder_days_before; ?>">
            </label>
            <label>Send time (server time)
              <input type="time" name="reminder_time"
                     value="<?php echo htmlspecialchars($reminder_time, ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <label for="reminder_email_message" style="margin-top:12px; display:block;">Custom message added to reminder emails</label>
              <textarea id="reminder_email_message" name="reminder_email_message" rows="3" placeholder="e.g. Please be on time"><?php echo htmlspecialchars($reminder_email_message); ?></textarea>
          </div>
          <p style="font-size:.9em;color:#666;margin-top:8px;">
            The cron script only sends during the 60-minute window starting at <em>Send time</em>.
            Each appointment receives at most one reminder (tracked in <code>appointment_reminders</code>).
          </p>
        </fieldset>
 
        <input type="hidden" name="tab" value="reminders">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" <?php echo !$smtp_ready ? 'disabled' : ''; ?>>Save Reminder Settings</button>
        <div id="reminderMsg"></div>
      </form>
 
      <script>
      (function () {
        var check   = document.getElementById('reminderEnabledCheck');
        var options = document.getElementById('reminderOptions');
        if (!check || !options) return;
        function sync() {
          var on = check.checked;
          options.style.opacity = on ? '1' : '0.45';
          options.querySelectorAll('input').forEach(function(el) { el.disabled = !on; });
        }
        check.addEventListener('change', sync);
        sync();
 
        // Inline success/error message wiring
        var form   = document.getElementById('reminderForm');
        var msgDiv = document.getElementById('reminderMsg');
        if (!form || !msgDiv || form.__inlineStatusAttached) return;
        form.__inlineStatusAttached = true;
        window.addEventListener('api:success', function (e) {
          if (e.detail.form !== form) return;
          msgDiv.innerHTML = '<p class="success-msg">✓ ' + ((e.detail.json || {}).message || 'Saved') + '</p>';
          setTimeout(function () { msgDiv.innerHTML = ''; }, 5000);
        });
        window.addEventListener('api:error', function (e) {
          if (e.detail.form !== form) return;
          msgDiv.innerHTML = '<p class="error-msg">✗ ' + ((e.detail.json || {}).error || 'Error saving') + '</p>';
        });
      })();
      </script>

  </section>

  <!-- LOGIN ATTEMPTS TAB -->
  <section id="tab-login_attempts" class="tab-panel">
    <h2>Login Attempts</h2>
    <p>Shows recent login attempts (IP, username, success).</p>
    <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center;">
      <button id="refreshAttemptsBtn">Refresh</button>
      <button id="deleteAllAttemptsBtn">Delete All</button>
      <button id="deleteOldAttemptsBtn">Delete Older Than 7 Days</button>
      <div id="attemptsMsg" style="margin-left:12px;"></div>
    </div>
    <div id="attemptsTable"></div>
    <div id="attemptsPagination" style="margin-top:12px;"></div>
  </section>

</main>
<script src="assets/js/global.js"></script>
<script>
// Tab switching
document.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => {
  document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  const t = btn.getAttribute('data-tab');
  document.getElementById('tab-' + t).classList.add('active');
}));

// When date format changes, update server time display
const dateFormatSelect = document.getElementById('date_format');
const serverTimeEl = document.getElementById('serverTimeDisplay');
const browserTimeEl = document.getElementById('browserTimeDisplay');

let currentDateFormat = serverTimeEl ? serverTimeEl.getAttribute('data-server-date-format') : 'd/m/Y H:i';
const serverEpochMs = serverTimeEl ? (parseInt(serverTimeEl.getAttribute('data-server-epoch-ms'), 10) || Date.now()) : Date.now();
const serverOffsetSeconds = serverTimeEl ? (parseInt(serverTimeEl.getAttribute('data-server-offset-seconds'), 10) || 0) : 0;
const clientLoadMs = Date.now();

function pad(n) { return String(n).padStart(2, '0'); }

// Reads UTC getters on a Date that already has the app's offset baked in,
// so the browser's own timezone never gets applied on top.
function formatServerWallClock(fmt, wallClockMs) {
  const d = new Date(wallClockMs);
  const day = pad(d.getUTCDate());
  const month = pad(d.getUTCMonth() + 1);
  const year = d.getUTCFullYear();
  const hours = pad(d.getUTCHours());
  const minutes = pad(d.getUTCMinutes());
  return fmt.replace('d', day).replace('m', month).replace('Y', year).replace('H', hours).replace('i', minutes);
}

function formatBrowserLocal(fmt, date) {
  const day = pad(date.getDate());
  const month = pad(date.getMonth() + 1);
  const year = date.getFullYear();
  const hours = pad(date.getHours());
  const minutes = pad(date.getMinutes());
  return fmt.replace('d', day).replace('m', month).replace('Y', year).replace('H', hours).replace('i', minutes);
}

function tickTimeDisplays() {
  if (serverTimeEl) {
    const elapsedMs = Date.now() - clientLoadMs;
    const serverWallClockMs = serverEpochMs + (serverOffsetSeconds * 1000) + elapsedMs;
    serverTimeEl.textContent = formatServerWallClock(currentDateFormat, serverWallClockMs);
  }
  if (browserTimeEl) {
    browserTimeEl.textContent = formatBrowserLocal(currentDateFormat, new Date());
  }
}

if (dateFormatSelect) {
  dateFormatSelect.addEventListener('change', (e) => {
    currentDateFormat = e.target.value;
    tickTimeDisplays();
  });
}

tickTimeDisplays();
setInterval(tickTimeDisplays, 1000);
// Handle Display/Required checkbox dependencies
function setupFieldDependencies(fieldPrefix) {
  const displayCheckbox = document.querySelector(`input[name="${fieldPrefix}_display"]`);
  const requiredCheckbox = document.querySelector(`input[name="${fieldPrefix}_required"]`);
  if (!displayCheckbox || !requiredCheckbox) return;

  const updateRequiredState = () => {
    if (!displayCheckbox.checked) {
      requiredCheckbox.checked = false;
      requiredCheckbox.disabled = true;
    } else {
      requiredCheckbox.disabled = false;
    }
  };

  displayCheckbox.addEventListener('change', updateRequiredState);
  updateRequiredState();
}

setupFieldDependencies('booking_firstname');
setupFieldDependencies('booking_lastname');
setupFieldDependencies('booking_middlename');
setupFieldDependencies('booking_email');
setupFieldDependencies('booking_phone');

// Form submission handlers for inline messages
['generalForm', 'customerRegistrationForm', 'bookingForm', 'authenticationForm', 'phpmailerForm', 'changePasswordForm'].forEach(formId => {
  const form = document.getElementById(formId);
  const msgDiv = document.getElementById(formId.replace('Form', 'Msg'));
  if (!form || !msgDiv || form.__inlineStatusAttached) return;
  form.__inlineStatusAttached = true;

  window.addEventListener('api:success', (evt) => {
    if (evt.detail.form !== form) return;
    const json = evt.detail.json || {};
    msgDiv.innerHTML = '<p class="success-msg">✓ ' + (json.message || 'Saved successfully') + '</p>';
    setTimeout(() => { msgDiv.innerHTML = ''; }, 5000);

    if (formId === 'authenticationForm' && window.App && window.App.attachPasswordHints) {
      const authReq = {
        min_length: parseInt(form.querySelector('[name="password_min_length"]')?.value || '8', 10),
        max_length: parseInt(form.querySelector('[name="password_max_length"]')?.value || '128', 10),
        require_lowercase: !!form.querySelector('[name="password_require_lowercase"]')?.checked,
        require_uppercase: !!form.querySelector('[name="password_require_uppercase"]')?.checked,
        require_number: !!form.querySelector('[name="password_require_number"]')?.checked,
        require_symbol: !!form.querySelector('[name="password_require_symbol"]')?.checked
      };
      const changeForm = document.getElementById('changePasswordForm');
      if (changeForm) {
        App.attachPasswordHints(changeForm, '#new_password', '#new_password_confirm', '#changePasswordHints', authReq);
      }
    }
  });

  window.addEventListener('api:error', (evt) => {
    if (evt.detail.form !== form) return;
    msgDiv.innerHTML = '<p class="error-msg">✗ ' + (evt.detail.json.error || 'Error saving') + '</p>';
  });
});

// Test email configuration
async function testEmailConfiguration(e) {
  e.preventDefault();
  const btn = document.getElementById('testEmailBtn');
  const msgDiv = document.getElementById('testEmailMsg');
  btn.disabled = true;
  btn.textContent = 'Sending...';
  msgDiv.innerHTML = '';
  
  try {
    const response = await fetch('settings_api.php?action=test_email', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ csrf_token: document.querySelector('input[name="csrf_token"]').value })
    });
    const data = await response.json();
    
    if (data.ok) {
      msgDiv.innerHTML = '<p class="success-msg">✓ Test email sent successfully to ' + (data.sent_to || 'admin') + '</p>';
      // If diagnostics were returned, show them under the success message
      if (data.debug && Array.isArray(data.debug)) {
        let debugHtml = '<div style="background: #f0fff4; border-left: 4px solid #28a745; padding: 12px; margin-top: 12px; border-radius: 4px;">';
        debugHtml += '<p style="color: #155724; margin: 0 0 8px 0; font-weight: bold;">Diagnostics:</p>';
        debugHtml += '<pre style="margin: 0; white-space: pre-wrap; word-break: break-word; font-size: 0.9em; color: #0b3a23;">';
        data.debug.forEach(line => {
          debugHtml += htmlspecialchars(line) + '\n';
        });
        debugHtml += '</pre></div>';
        msgDiv.innerHTML += debugHtml;
      }
      setTimeout(() => msgDiv.innerHTML = '', 10000);
    } else if (data.debug && Array.isArray(data.debug)) {
      // Show debug messages
      let debugHtml = '<div style="background: #f5f5f5; border-left: 4px solid #dc3545; padding: 12px; margin-top: 12px; border-radius: 4px;">';
      debugHtml += '<p style="color: #dc3545; margin: 0 0 8px 0; font-weight: bold;">Debug Information:</p>';
      debugHtml += '<pre style="margin: 0; white-space: pre-wrap; word-break: break-word; font-size: 0.9em; color: #333;">';
      data.debug.forEach(line => {
        debugHtml += htmlspecialchars(line) + '\n';
      });
      debugHtml += '</pre></div>';
      msgDiv.innerHTML = debugHtml;
    } else {
      msgDiv.innerHTML = '<p class="error-msg">✗ ' + (data.error || 'Failed to send test email') + '</p>';
    }
  } catch (err) {
    msgDiv.innerHTML = '<p class="error-msg">✗ Error: ' + err.message + '</p>';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Send Test Email';
  }
}

// Login attempts UI
async function loadLoginAttempts(page = 1) {
  const container = document.getElementById('attemptsTable');
  const pagDiv = document.getElementById('attemptsPagination');
  const msgDiv = document.getElementById('attemptsMsg');
  container.innerHTML = 'Loading...';
  pagDiv.innerHTML = '';
  try {
    const res = await fetch('settings_api.php?action=login_attempts&page=' + encodeURIComponent(page), { credentials: 'include' });
    const data = await res.json();
    if (!data.ok) {
      container.innerHTML = '<p class="error-msg">' + (data.error || 'Failed to load') + '</p>';
      return;
    }
    const rows = data.attempts || [];
    if (!rows.length) {
      container.innerHTML = '<p>No login attempts recorded.</p>';
    } else {
      let html = '<table><thead><tr><th>Time</th><th>IP</th><th>Username</th><th>Success</th><th>User Agent</th></tr></thead><tbody>';
      rows.forEach(r => {
        html += '<tr>' +
          '<td>' + (r.attempted_at || '') + '</td>' +
          '<td>' + (r.ip_address || '') + '</td>' +
          '<td>' + (r.username || '') + '</td>' +
          '<td>' + (r.success == 1 ? '<span style="color:green;">✓</span>' : '<span style="color:red;">✗</span>') + '</td>' +
          '<td>' + (r.user_agent ? htmlspecialchars(r.user_agent) : '') + '</td>' +
          '</tr>';
      });
      html += '</tbody></table>';
      container.innerHTML = html;
    }

    // pagination
    const total = data.total || 0;
    const totalPages = data.total_pages || 1;
    const current = data.page || 1;
    // build pages array: first 5, ellipsis, last 5 (if large)
    const pages = [];
    if (totalPages <= 11) {
      for (let i=1;i<=totalPages;i++) pages.push(i);
    } else {
      for (let i=1;i<=5;i++) pages.push(i);
      pages.push('...');
      const start = Math.max(6, totalPages - 4);
      for (let i=start;i<=totalPages;i++) pages.push(i);
    }
    let pagHtml = '';
    pages.forEach(p => {
      if (p === '...') {
        pagHtml += '<span style="margin:0 6px;">...</span>';
      } else {
        pagHtml += '<button class="pageBtn" data-page="' + p + '"' + (p===current? ' disabled' : '') + '>' + p + '</button>';
      }
    });
    pagDiv.innerHTML = pagHtml;
    Array.from(pagDiv.querySelectorAll('.pageBtn')).forEach(b => b.addEventListener('click', (ev) => {
      const p = parseInt(ev.target.getAttribute('data-page'), 10);
      loadLoginAttempts(p);
    }));

  } catch (err) {
    container.innerHTML = '<p class="error-msg">Error loading login attempts</p>';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const refreshBtn = document.getElementById('refreshAttemptsBtn');
  const deleteAllBtn = document.getElementById('deleteAllAttemptsBtn');
  const deleteOldBtn = document.getElementById('deleteOldAttemptsBtn');
  if (refreshBtn) refreshBtn.addEventListener('click', () => loadLoginAttempts(1));
  if (deleteAllBtn) deleteAllBtn.addEventListener('click', async () => {
    if (!confirm('Delete all login attempts? This cannot be undone.')) return;
    const res = await fetch('settings_api.php?action=login_attempts', { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ csrf_token: document.querySelector('input[name="csrf_token"]').value, command: 'delete_all' }) });
    const j = await res.json();
    if (j.ok) { document.getElementById('attemptsMsg').innerHTML = '<span style="color:green;">Deleted all login attempts</span>'; loadLoginAttempts(1); } else { document.getElementById('attemptsMsg').innerHTML = '<span style="color:red;">Failed to delete</span>'; }
  });
  if (deleteOldBtn) deleteOldBtn.addEventListener('click', async () => {
    if (!confirm('Delete login attempts older than 7 days?')) return;
    const res = await fetch('settings_api.php?action=login_attempts', { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ csrf_token: document.querySelector('input[name="csrf_token"]').value, command: 'delete_older_7_days' }) });
    const j = await res.json();
    if (j.ok) { document.getElementById('attemptsMsg').innerHTML = '<span style="color:green;">Deleted older attempts</span>'; loadLoginAttempts(1); } else { document.getElementById('attemptsMsg').innerHTML = '<span style="color:red;">Failed to delete</span>'; }
  });
  // if tab is present and active, load
  if (document.getElementById('tab-login_attempts') && document.querySelector('.tab.active').getAttribute('data-tab') === 'login_attempts') {
    loadLoginAttempts(1);
  }
  // when switching tabs, if login_attempts tab activated, load
  document.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => {
    if (btn.getAttribute('data-tab') === 'login_attempts') loadLoginAttempts(1);
  }));
});

document.addEventListener('DOMContentLoaded', function () {
  const breakDaySelect = document.getElementById('breakDaySelect');
  const breakStartTime = document.getElementById('breakStartTime');
  const breakEndTime = document.getElementById('breakEndTime');
  const createBreakBtn = document.getElementById('createBreakBtn');
  const breaksTableBody = document.getElementById('breaksTableBody');
  const breaksMsg = document.getElementById('breaksMsg');
  const bookingTokenInput = document.querySelector('#bookingForm input[name="csrf_token"]');

  if (!breakDaySelect || !breakStartTime || !breakEndTime || !createBreakBtn || !breaksTableBody || !breaksMsg || !bookingTokenInput) {
    return;
  }

  const dayLabels = {
    '': 'Every day',
    '1': 'Monday',
    '2': 'Tuesday',
    '3': 'Wednesday',
    '4': 'Thursday',
    '5': 'Friday',
    '6': 'Saturday',
    '7': 'Sunday'
  };

  function setBreakMessage(type, message) {
    breaksMsg.innerHTML = message ? '<p class="' + (type === 'error' ? 'error-msg' : 'success-msg') + '">' + (type === 'error' ? '✗ ' : '✓ ') + message + '</p>' : '';
  }

  function renderBreakRows(rows) {
    if (!rows || !rows.length) {
      breaksTableBody.innerHTML = '<tr><td colspan="4">No standard breaks for this selection.</td></tr>';
      return;
    }

    breaksTableBody.innerHTML = rows.map(function (row) {
      const breakId = row.id;
      const startTime = (row.start_time || '').slice(0, 5);
      const endTime = (row.end_time || '').slice(0, 5);
      const dayLabel = row.day_label || dayLabels[String(row.day_of_week ?? '')] || 'Every day';
      return '<tr>' +
        '<td>' + dayLabel + '</td>' +
        '<td>' + startTime + '</td>' +
        '<td>' + endTime + '</td>' +
        '<td><button type="button" data-break-id="' + breakId + '" class="delete-break-btn">Delete</button></td>' +
        '</tr>';
    }).join('');
  }

  async function loadBreaks() {
    breaksTableBody.innerHTML = '<tr><td colspan="4">Loading breaks...</td></tr>';
    const dayValue = breakDaySelect.value;
    const url = 'settings_api.php?action=breaks&day=' + encodeURIComponent(dayValue);
    const response = await fetch(url, { credentials: 'include' });
    const data = await response.json();
    if (!data.ok) {
      throw new Error(data.error || 'Failed to load breaks');
    }
    renderBreakRows(data.breaks || []);
  }

  async function saveBreak(command, breakId) {
    const payload = {
      csrf_token: bookingTokenInput.value,
      command: command,
      day_of_week: breakDaySelect.value,
      start_time: breakStartTime.value,
      end_time: breakEndTime.value
    };

    if (command === 'delete') {
      payload.break_id = breakId;
    }

    const response = await fetch('settings_api.php?action=breaks', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Failed to save break');
    }

    setBreakMessage('success', data.message || 'Break saved');
    await loadBreaks();
  }

  breakDaySelect.addEventListener('change', async function () {
    try {
      setBreakMessage('', '');
      await loadBreaks();
    } catch (error) {
      setBreakMessage('error', error.message);
    }
  });

  createBreakBtn.addEventListener('click', async function () {
    try {
      setBreakMessage('', '');
      await saveBreak('create');
    } catch (error) {
      setBreakMessage('error', error.message);
    }
  });

  breaksTableBody.addEventListener('click', async function (event) {
    const target = event.target;
    if (!target || !target.classList.contains('delete-break-btn')) {
      return;
    }

    const breakId = target.getAttribute('data-break-id');
    if (!breakId || !window.confirm('Delete this break?')) {
      return;
    }

    try {
      setBreakMessage('', '');
      await saveBreak('delete', breakId);
    } catch (error) {
      setBreakMessage('error', error.message);
    }
  });

  loadBreaks().catch(function (error) {
    setBreakMessage('error', error.message);
    breaksTableBody.innerHTML = '<tr><td colspan="4">Failed to load breaks.</td></tr>';
  });
});

function htmlspecialchars(str) {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return str.replace(/[&<>"']/g, m => map[m]);
}

async function loadSessions() {
  const container = document.getElementById('sessionsTableContainer');
  if (!container) return;
  container.innerHTML = 'Loading...';
  try {
    const res = await fetch('settings_api.php?action=sessions', { credentials: 'include' });
    const data = await res.json();
    if (!data.ok) { container.innerHTML = '<p class="error-msg">' + (data.error || 'Failed to load') + '</p>'; return; }

    const rows = data.sessions || [];
    if (!rows.length) { container.innerHTML = '<p>No active sessions found.</p>'; return; }

    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    let html = '<table style="width:100%; border-collapse:collapse;">'
      + '<thead><tr>'
      + '<th style="text-align:left; padding:8px; border-bottom:1px solid #ccc;">IP Address</th>'
      + '<th style="text-align:left; padding:8px; border-bottom:1px solid #ccc;">Browser / Device</th>'
      + '<th style="text-align:left; padding:8px; border-bottom:1px solid #ccc;">Started</th>'
      + '<th style="text-align:left; padding:8px; border-bottom:1px solid #ccc;">Last Active</th>'
      + '<th style="text-align:left; padding:8px; border-bottom:1px solid #ccc;">Expires</th>'
      + '<th style="text-align:left; padding:8px; border-bottom:1px solid #ccc;">Action</th>'
      + '</tr></thead><tbody>';

    rows.forEach(s => {
      const isCurrent = s.is_current == 1;
      const ua = parseUserAgent(s.user_agent || '');
      html += '<tr style="' + (isCurrent ? 'background:rgba(36,87,255,0.06);' : '') + '">'
        + '<td style="padding:8px; border-bottom:1px solid #eee;">' + htmlspecialchars (s.ip_address) + '</td>'
        + '<td style="padding:8px; border-bottom:1px solid #eee;"><span title="' + htmlspecialchars (s.user_agent) + '">' + htmlspecialchars (ua) + '</span></td>'
        + '<td style="padding:8px; border-bottom:1px solid #eee;">' + htmlspecialchars (s.created_at) + '</td>'
        + '<td style="padding:8px; border-bottom:1px solid #eee;">' + htmlspecialchars (s.last_activity_at) + '</td>'
        + '<td style="padding:8px; border-bottom:1px solid #eee;">' + htmlspecialchars (s.expires_at) + '</td>'
        + '<td style="padding:8px; border-bottom:1px solid #eee;">'
        + (isCurrent
            ? '<span style="color:#667085; font-size:0.85em;">Current session</span>'
            : '<button type="button" class="revoke-session-btn" data-session-id="' + htmlspecialchars (s.session_id) + '">Revoke</button>')
        + '</td>'
        + '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;

    container.querySelectorAll('.revoke-session-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Revoke this session?')) return;
        btn.disabled = true;
        btn.textContent = 'Revoking...';
        try {
          const r = await fetch('settings_api.php?action=sessions', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrfToken, command: 'revoke', session_id: btn.dataset.sessionId })
          });
          const j = await r.json();
          if (j.ok) { loadSessions(); } else { alert(j.error || 'Failed to revoke'); btn.disabled = false; btn.textContent = 'Revoke'; }
        } catch { btn.disabled = false; btn.textContent = 'Revoke'; }
      });
    });

  } catch (err) {
    container.innerHTML = '<p class="error-msg">Error loading sessions: ' + htmlspecialchars (err.message) + '</p>';
  }
}

function parseUserAgent(ua) {
  if (!ua) return 'Unknown';
  if (/Chrome\//.test(ua) && !/Edg\//.test(ua) && !/OPR\//.test(ua)) return 'Chrome';
  if (/Edg\//.test(ua)) return 'Edge';
  if (/Firefox\//.test(ua)) return 'Firefox';
  if (/Safari\//.test(ua) && !/Chrome/.test(ua)) return 'Safari';
  if (/OPR\//.test(ua) || /Opera/.test(ua)) return 'Opera';
  return ua.slice(0, 48);
}

// Wire up tab switching and revoke-all button
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.tab').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.getAttribute('data-tab') === 'sessions') loadSessions();
    });
  });

  const revokeAllBtn = document.getElementById('revokeAllSessionsBtn');
  if (revokeAllBtn) {
    revokeAllBtn.addEventListener('click', async () => {
      if (!confirm('Revoke all sessions except the current one?')) return;
      const csrfToken = document.querySelector('input[name="csrf_token"]').value;
      revokeAllBtn.disabled = true;
      try {
        const r = await fetch('settings_api.php?action=sessions', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf_token: csrfToken, command: 'revoke_all_others' })
        });
        const j = await r.json();
        if (j.ok) { loadSessions(); } else { alert(j.error || 'Failed'); }
      } finally { revokeAllBtn.disabled = false; }
    });
  }

  // Wire up save message for session lifetime form
  const sessionForm = document.getElementById('sessionLifetimeForm');
  const sessionMsg  = document.getElementById('sessionsMsg');
  if (sessionForm && sessionMsg && !sessionForm.__inlineStatusAttached) {
    sessionForm.__inlineStatusAttached = true;
    window.addEventListener('api:success', (evt) => {
      if (evt.detail.form !== sessionForm) return;
      sessionMsg.innerHTML = '<p class="success-msg">✓ Session settings saved</p>';
      setTimeout(() => { sessionMsg.innerHTML = ''; }, 5000);
    });
    window.addEventListener('api:error', (evt) => {
      if (evt.detail.form !== sessionForm) return;
      sessionMsg.innerHTML = '<p class="error-msg">✗ ' + (evt.detail.json?.error || 'Error saving') + '</p>';
    });
  }
});

</script>
</body>
</html>
