<?php
require_once __DIR__ . '/functions.php';
init_session();
require_login();
$user = $_SESSION['user'];

// Load existing settings
$site_name = get_setting('site_name', APP_NAME ?? 'Boomy Appointments');
$org_email = get_setting('organization_email', '');
$date_format = get_setting('date_format', 'd/m/Y H:i');
$booking_deadline = get_setting('booking_deadline_hours', '24');

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
$email_allowed_domains = get_setting('booking_email_allowed_domains', '');

$phone_display = (int) get_setting('booking_phone_display', '1');
$phone_required = (int) get_setting('booking_phone_required', '0');
$phone_numbers_only = (int) get_setting('booking_phone_numbers_only', '1');
$phone_min = (int) get_setting('booking_phone_min', '10');
$phone_max = (int) get_setting('booking_phone_max', '20');

// PHPMailer/Gmail settings
$smtp_host = get_setting('smtp_host', 'smtp.gmail.com');
$smtp_port = (int) get_setting('smtp_port', '587');
$smtp_encryption = get_setting('smtp_encryption', 'tls');
$smtp_username = get_setting('smtp_username', '');
$smtp_password = get_setting('smtp_password', '');
$mail_from_address = get_setting('mail_from_address', '');
$mail_from_name = get_setting('mail_from_name', APP_NAME ?? 'Boomy Appointments');

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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Settings - <?php echo htmlspecialchars(APP_NAME); ?></title>
  <link rel="stylesheet" href="https://unpkg.com/@picocss/pico@1.*/css/pico.min.css">
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
  </style>
</head>
<body>
<main class="container">
  <header>
    <h1>Settings</h1>
    <p>Signed in as <?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></p>
  </header>

  <nav class="tabs" role="tablist">
    <button class="tab active" data-tab="general" role="tab">General</button>
    <button class="tab" data-tab="booking" role="tab">Booking</button>
    <button class="tab" data-tab="authentication" role="tab">Authentication</button>
    <button class="tab" data-tab="phpmailer" role="tab">PHPMailer</button>
  </nav>

  <!-- GENERAL TAB -->
  <section id="tab-general" class="tab-panel active">
    <form id="generalForm" data-api action="settings_api.php?action=save" method="post">
      <label for="site_name">Website Name</label>
      <input id="site_name" name="site_name" type="text" value="<?php echo htmlspecialchars($site_name); ?>" required>

      <label for="organization_email">Organisation Email</label>
      <input id="organization_email" name="organization_email" type="email" value="<?php echo htmlspecialchars($org_email); ?>" required>

      <label for="date_format">Time Format</label>
      <select id="date_format" name="date_format">
        <option value="d/m/Y H:i" <?php echo $date_format === 'd/m/Y H:i' ? 'selected' : ''; ?>>DD/MM/YYYY HH:MM</option>
        <option value="Y/m/d H:i" <?php echo $date_format === 'Y/m/d H:i' ? 'selected' : ''; ?>>YYYY/MM/DD HH:MM</option>
        <option value="m/d/Y H:i" <?php echo $date_format === 'm/d/Y H:i' ? 'selected' : ''; ?>>MM/DD/YYYY HH:MM</option>
      </select>

      <p><strong>Current date and time:</strong> <span data-server-time-format="<?php echo htmlspecialchars($date_format); ?>"></span></p>

      <input type="hidden" name="tab" value="general">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button type="submit">Save General</button>
      <div id="generalMsg"></div>
    </form>
  </section>

  <!-- BOOKING TAB -->
  <section id="tab-booking" class="tab-panel">
    <form id="bookingForm" data-api action="settings_api.php?action=save" method="post">
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
        <label for="booking_email_allowed_domains">Allowed domains (one per line, leave empty for all)</label>
        <textarea id="booking_email_allowed_domains" name="booking_email_allowed_domains" rows="4"><?php echo htmlspecialchars($email_allowed_domains); ?></textarea>
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

      <input type="hidden" name="tab" value="booking">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button type="submit">Save Booking</button>
      <div id="bookingMsg"></div>
    </form>
  </section>

  <!-- AUTHENTICATION TAB -->
  <section id="tab-authentication" class="tab-panel">
    <form id="authenticationForm" data-api action="settings_api.php?action=save" method="post">
      <fieldset class="field-group">
        <legend>Email Validation</legend>
        <label><input type="checkbox" name="email_whitelist_enabled" value="1" <?php echo $email_whitelist_enabled ? 'checked' : ''; ?>> Enable email domain whitelist</label>
        
        <label for="email_domain_whitelist">Allowed domains (one per line)</label>
        <textarea id="email_domain_whitelist" name="email_domain_whitelist" rows="4" placeholder="gmail.com&#10;outlook.com&#10;example.com"><?php echo htmlspecialchars($email_domain_whitelist); ?></textarea>
        
        <label for="email_domain_blacklist">Blocked domains (one per line)</label>
        <textarea id="email_domain_blacklist" name="email_domain_blacklist" rows="4" placeholder="tempmail.com&#10;throwaway.com"><?php echo htmlspecialchars($email_domain_blacklist); ?></textarea>
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
  </section>

  <!-- PHPMAILER TAB -->
  <section id="tab-phpmailer" class="tab-panel">
    <form id="phpmailerForm" data-api action="settings_api.php?action=save" method="post">
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
    </form>
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
if (dateFormatSelect) {
  dateFormatSelect.addEventListener('change', (e) => {
    const f = e.target.value;
    const el = document.querySelector('[data-server-time-format]');
    if (el) el.setAttribute('data-server-time-format', f);
    if (window.App && window.App.attachServerTime) window.App.attachServerTime();
  });
}

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
['generalForm', 'bookingForm', 'authenticationForm', 'phpmailerForm'].forEach(formId => {
  const form = document.getElementById(formId);
  if (form) {
    form.addEventListener('submit', function(e) {
      const msgDiv = document.getElementById(formId.replace('Form', 'Msg'));
      if (!msgDiv) return;

      // Listen to API response
      window.addEventListener('api:success', (evt) => {
        if (evt.detail.form === form) {
          msgDiv.innerHTML = '<p class="success-msg">✓ Saved successfully</p>';
          setTimeout(() => msgDiv.innerHTML = '', 3000);
        }
      });

      window.addEventListener('api:error', (evt) => {
        if (evt.detail.form === form) {
          msgDiv.innerHTML = '<p class="error-msg">✗ ' + (evt.detail.json.error || 'Error saving') + '</p>';
        }
      });
    });
  }
});

</script>
</body>
</html>
