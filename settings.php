<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

require_login();
$pdo = get_pdo();

// helper
function json_response(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$allowedDateFormats = [
    'Y/m/d H:i' => 'YYYY/MM/DD HH:MM',
    'm/d/Y H:i' => 'MM/DD/YYYY HH:MM',
    'd/m/Y H:i' => 'DD/MM/YYYY HH:MM',
];

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = $_POST['tab'] ?? 'general';
    $errors = [];

    if ($tab === 'general') {
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $orgEmail = trim((string) ($_POST['org_email'] ?? ''));
        $dateFormat = (string) ($_POST['date_format'] ?? 'd/m/Y H:i');

        if ($siteName === '') {
            $errors[] = 'Site name must not be empty.';
        }

        if (!filter_var($orgEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid organization email.';
        }

        if (!array_key_exists($dateFormat, $allowedDateFormats)) {
            $errors[] = 'Select a valid date format.';
        }

        if (empty($errors)) {
            save_setting($pdo, 'site_name', $siteName);
            save_setting($pdo, 'org_email', $orgEmail);
            save_setting($pdo, 'date_format', $dateFormat);

            if ($isAjax) {
                json_response(['success' => true, 'message' => 'General settings saved.']);
            }

            flash('success', 'General settings saved.');
            header('Location: settings.php');
            exit;
        }
    }

    if ($tab === 'booking') {
        $prefixes = ['firstname', 'lastname', 'middlename'];

        foreach ($prefixes as $p) {
            $display = isset($_POST["{$p}_display"]) ? '1' : '0';
            $required = isset($_POST["{$p}_required"]) ? '1' : '0';
            $lettersOnly = isset($_POST["{$p}_letters_only"]) ? '1' : '0';
            $min = (int) ($_POST["{$p}_min_length"] ?? 1);
            $max = (int) ($_POST["{$p}_max_length"] ?? 100);

            if ($display === '0') {
                $required = '0';
            }

            if ($min < 1) {
                $errors[] = ucfirst($p) . ': min length must be at least 1.';
            }

            if ($max < $min) {
                $errors[] = ucfirst($p) . ": max length must be >= min length.";
            }

            // store as strings
            if (empty($errors)) {
                save_setting($pdo, "booking_{$p}_display", $display);
                save_setting($pdo, "booking_{$p}_required", $required);
                save_setting($pdo, "booking_{$p}_letters_only", $lettersOnly);
                save_setting($pdo, "booking_{$p}_min_length", (string) $min);
                save_setting($pdo, "booking_{$p}_max_length", (string) $max);
            }
        }

        // email group
        $emailDisplay = isset($_POST['email_display']) ? '1' : '0';
        $emailRequired = isset($_POST['email_required']) ? '1' : '0';
        if ($emailDisplay === '0') {
            $emailRequired = '0';
        }

        $rawDomains = (string) ($_POST['email_allowed_domains'] ?? '');
        $domains = array_filter(array_map('trim', preg_split('/\r?\n/', $rawDomains)), fn($d) => $d !== '');
        $domainsValue = implode("\n", $domains);

        if (empty($errors)) {
            save_setting($pdo, 'booking_email_display', $emailDisplay);
            save_setting($pdo, 'booking_email_required', $emailRequired);
            save_setting($pdo, 'booking_email_allowed_domains', $domainsValue);
        }

        // phone group
        $phoneDisplay = isset($_POST['phone_display']) ? '1' : '0';
        $phoneRequired = isset($_POST['phone_required']) ? '1' : '0';
        if ($phoneDisplay === '0') {
            $phoneRequired = '0';
        }

        $phoneNumbersOnly = isset($_POST['phone_numbers_symbols_only']) ? '1' : '0';
        $phoneMin = (int) ($_POST['phone_min_length'] ?? 1);
        $phoneMax = (int) ($_POST['phone_max_length'] ?? 100);

        if ($phoneMin < 1) {
            $errors[] = 'Phone: min length must be at least 1.';
        }

        if ($phoneMax < $phoneMin) {
            $errors[] = 'Phone: max length must be >= min length.';
        }

        if (empty($errors)) {
            save_setting($pdo, 'booking_phone_display', $phoneDisplay);
            save_setting($pdo, 'booking_phone_required', $phoneRequired);
            save_setting($pdo, 'booking_phone_numbers_symbols_only', $phoneNumbersOnly);
            save_setting($pdo, 'booking_phone_min_length', (string) $phoneMin);
            save_setting($pdo, 'booking_phone_max_length', (string) $phoneMax);
        }

        if (empty($errors)) {
            if ($isAjax) {
                json_response(['success' => true, 'message' => 'Booking settings saved.']);
            }

            flash('success', 'Booking settings saved.');
            header('Location: settings.php');
            exit;
        }
    }

    // If we reach here with errors and it's an AJAX call, return JSON
    if ($isAjax) {
        json_response(['success' => false, 'errors' => $errors]);
    }

    // Non-AJAX: render with errors below
}

// Load current values
$siteName = get_setting($pdo, 'site_name', APP_NAME);
$orgEmail = get_setting($pdo, 'org_email', 'hello@example.com');
$currentDateFormat = get_setting($pdo, 'date_format', 'd/m/Y H:i');

// Use server time as baseline for client-side preview (milliseconds)
$serverTimestampMs = (int) (strtotime(get_time()) * 1000);
// Server timezone for client-side preview (IANA name)
$serverTimeZone = date_default_timezone_get();

$booking = [];
$keys = [
    'booking_firstname_display','booking_firstname_required','booking_firstname_letters_only','booking_firstname_min_length','booking_firstname_max_length',
    'booking_lastname_display','booking_lastname_required','booking_lastname_letters_only','booking_lastname_min_length','booking_lastname_max_length',
    'booking_middlename_display','booking_middlename_required','booking_middlename_letters_only','booking_middlename_min_length','booking_middlename_max_length',
    'booking_email_display','booking_email_required','booking_email_allowed_domains',
    'booking_phone_display','booking_phone_required','booking_phone_numbers_symbols_only','booking_phone_min_length','booking_phone_max_length'
];

foreach ($keys as $k) {
    $booking[$k] = get_setting($pdo, $k, '');
}

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings · <?php echo e(APP_NAME); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <nav class="container auth-topbar">
        <ul>
            <li><strong><?php echo e(APP_NAME); ?></strong></li>
        </ul>
        <ul>
            <li><span><?php echo e((string) ($_SESSION['user_email'] ?? '')); ?></span></li>
            <li><a href="logout.php" role="button" class="secondary outline">Logout</a></li>
        </ul>
    </nav>

    <main class="container settings-shell">
        <article>
            <header>
                <p class="eyebrow">Admin dashboard</p>
                <h1>Application settings</h1>
                <p>Update the site name, booking fields and validation rules.</p>
            </header>

            <nav role="tablist" aria-label="Settings tabs">
                <button role="tab" aria-selected="true" data-tab-target="general">General</button>
                <button role="tab" aria-selected="false" data-tab-target="booking">Booking</button>
            </nav>

            <section id="general" role="tabpanel">
                <form id="general-form">
                    <input type="hidden" name="tab" value="general">

                    <label>
                        Website name
                        <input type="text" name="site_name" required value="<?php echo e($siteName); ?>">
                    </label>

                    <label>
                        Organisation email
                        <input type="email" name="org_email" required value="<?php echo e($orgEmail); ?>">
                    </label>

                    <label>
                        Time format
                        <select name="date_format" id="date_format_select">
                            <?php foreach ($allowedDateFormats as $fmt => $label): ?>
                                <option value="<?php echo e($fmt); ?>" <?php echo $currentDateFormat === $fmt ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <p>Current date and time: <strong id="current-date-preview"></strong></p>

                    <footer>
                        <button type="button" id="save-general">Save General</button>
                        <span id="general-status" role="status"></span>
                    </footer>
                </form>
            </section>

            <section id="booking" role="tabpanel" hidden>
                <form id="booking-form">
                    <input type="hidden" name="tab" value="booking">

                    <fieldset>
                        <legend>First Name</legend>
                        <label><input type="checkbox" name="firstname_display" id="firstname_display" <?php echo $booking['booking_firstname_display'] === '1' ? 'checked' : ''; ?>> Display</label>
                        <label><input type="checkbox" name="firstname_required" id="firstname_required" <?php echo $booking['booking_firstname_required'] === '1' ? 'checked' : ''; ?>> Required</label>
                        <label><input type="checkbox" name="firstname_letters_only" <?php echo $booking['booking_firstname_letters_only'] === '1' ? 'checked' : ''; ?>> Letters only</label>
                        <label>Min length <input type="number" name="firstname_min_length" min="1" value="<?php echo e((string) $booking['booking_firstname_min_length'] ?: '1'); ?>"></label>
                        <label>Max length <input type="number" name="firstname_max_length" min="1" value="<?php echo e((string) $booking['booking_firstname_max_length'] ?: '100'); ?>"></label>
                    </fieldset>

                    <fieldset>
                        <legend>Last Name</legend>
                        <label><input type="checkbox" name="lastname_display" id="lastname_display" <?php echo $booking['booking_lastname_display'] === '1' ? 'checked' : ''; ?>> Display</label>
                        <label><input type="checkbox" name="lastname_required" id="lastname_required" <?php echo $booking['booking_lastname_required'] === '1' ? 'checked' : ''; ?>> Required</label>
                        <label><input type="checkbox" name="lastname_letters_only" <?php echo $booking['booking_lastname_letters_only'] === '1' ? 'checked' : ''; ?>> Letters only</label>
                        <label>Min length <input type="number" name="lastname_min_length" min="1" value="<?php echo e((string) $booking['booking_lastname_min_length'] ?: '1'); ?>"></label>
                        <label>Max length <input type="number" name="lastname_max_length" min="1" value="<?php echo e((string) $booking['booking_lastname_max_length'] ?: '100'); ?>"></label>
                    </fieldset>

                    <fieldset>
                        <legend>Middle Name</legend>
                        <label><input type="checkbox" name="middlename_display" id="middlename_display" <?php echo $booking['booking_middlename_display'] === '1' ? 'checked' : ''; ?>> Display</label>
                        <label><input type="checkbox" name="middlename_required" id="middlename_required" <?php echo $booking['booking_middlename_required'] === '1' ? 'checked' : ''; ?>> Required</label>
                        <label><input type="checkbox" name="middlename_letters_only" <?php echo $booking['booking_middlename_letters_only'] === '1' ? 'checked' : ''; ?>> Letters only</label>
                        <label>Min length <input type="number" name="middlename_min_length" min="1" value="<?php echo e((string) $booking['booking_middlename_min_length'] ?: '1'); ?>"></label>
                        <label>Max length <input type="number" name="middlename_max_length" min="1" value="<?php echo e((string) $booking['booking_middlename_max_length'] ?: '100'); ?>"></label>
                    </fieldset>

                    <fieldset>
                        <legend>Email</legend>
                        <label><input type="checkbox" name="email_display" id="email_display" <?php echo $booking['booking_email_display'] === '1' ? 'checked' : ''; ?>> Display</label>
                        <label><input type="checkbox" name="email_required" id="email_required" <?php echo $booking['booking_email_required'] === '1' ? 'checked' : ''; ?>> Required</label>
                        <label>Allowed domains (one per line)
                            <textarea name="email_allowed_domains" rows="4"><?php echo e((string) $booking['booking_email_allowed_domains']); ?></textarea>
                        </label>
                    </fieldset>

                    <fieldset>
                        <legend>Phone Number</legend>
                        <label><input type="checkbox" name="phone_display" id="phone_display" <?php echo $booking['booking_phone_display'] === '1' ? 'checked' : ''; ?>> Display</label>
                        <label><input type="checkbox" name="phone_required" id="phone_required" <?php echo $booking['booking_phone_required'] === '1' ? 'checked' : ''; ?>> Required</label>
                        <label><input type="checkbox" name="phone_numbers_symbols_only" <?php echo $booking['booking_phone_numbers_symbols_only'] === '1' ? 'checked' : ''; ?>> Numbers and symbols only</label>
                        <label>Min length <input type="number" name="phone_min_length" min="1" value="<?php echo e((string) $booking['booking_phone_min_length'] ?: '1'); ?>"></label>
                        <label>Max length <input type="number" name="phone_max_length" min="1" value="<?php echo e((string) $booking['booking_phone_max_length'] ?: '100'); ?>"></label>
                    </fieldset>

                    <footer>
                        <button type="button" id="save-booking">Save Booking</button>
                        <span id="booking-status" role="status"></span>
                    </footer>
                </form>
            </section>
        </article>
    </main>

    <script>
    // Tab switching
    document.querySelectorAll('[role="tab"]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[role="tab"]').forEach(b => b.setAttribute('aria-selected','false'));
            btn.setAttribute('aria-selected','true');

            document.querySelectorAll('[role="tabpanel"]').forEach(panel => panel.hidden = true);
            const target = document.getElementById(btn.getAttribute('data-tab-target'));
            if (target) target.hidden = false;
        });
    });

    // Live date preview using server time baseline
    const serverNowStart = <?php echo json_encode($serverTimestampMs); ?>; // milliseconds since epoch
    let serverNow = serverNowStart;
    const dateSelect = document.getElementById('date_format_select');
    const preview = document.getElementById('current-date-preview');

    function formatPreview(fmt) {
        const now = new Date(serverNow);
        // Use Intl with server timezone to render server-local time
        const serverTZ = <?php echo json_encode($serverTimeZone); ?>;
        const opts = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: serverTZ };
        const dtf = new Intl.DateTimeFormat(undefined, opts);
        const parts = dtf.formatToParts(now).reduce((acc, p) => { acc[p.type] = p.value; return acc; }, {});
        const Y = parts.year || '';
        const m = parts.month || '';
        const d = parts.day || '';
        const H = parts.hour || '';
        const i = parts.minute || '';

        let out = '';
        if (fmt === 'd/m/Y H:i') {
            out = `${d}/${m}/${Y} ${H}:${i}`;
        } else if (fmt === 'm/d/Y H:i') {
            out = `${m}/${d}/${Y} ${H}:${i}`;
        } else if (fmt === 'Y/m/d H:i') {
            out = `${Y}/${m}/${d} ${H}:${i}`;
        }

        preview.textContent = out;
    }

    formatPreview(dateSelect.value);
    setInterval(() => { serverNow += 1000; formatPreview(dateSelect.value); }, 1000);
    dateSelect.addEventListener('change', () => formatPreview(dateSelect.value));

    // Booking form interactivity: disable required when display unchecked
    function tieDisplayRequired(displayId, requiredId, minInputs=[]) {
        const d = document.getElementById(displayId);
        const r = document.getElementById(requiredId);
        function update() {
            if (!d.checked) {
                r.checked = false;
                r.disabled = true;
                minInputs.forEach(i => i.disabled = true);
            } else {
                r.disabled = false;
                minInputs.forEach(i => i.disabled = false);
            }
        }
        d.addEventListener('change', update);
        update();
    }

    tieDisplayRequired('firstname_display','firstname_required',[document.querySelector('input[name="firstname_min_length"]'), document.querySelector('input[name="firstname_max_length"]')]);
    tieDisplayRequired('lastname_display','lastname_required',[document.querySelector('input[name="lastname_min_length"]'), document.querySelector('input[name="lastname_max_length"]')]);
    tieDisplayRequired('middlename_display','middlename_required',[document.querySelector('input[name="middlename_min_length"]'), document.querySelector('input[name="middlename_max_length"]')]);
    tieDisplayRequired('email_display','email_required',[]);
    tieDisplayRequired('phone_display','phone_required',[document.querySelector('input[name="phone_min_length"]'), document.querySelector('input[name="phone_max_length"]')]);

    // AJAX save handlers
    async function submitForm(formElem, statusElem) {
        const formData = new FormData(formElem);
        statusElem.textContent = 'Saving...';
        try {
            const res = await fetch('settings.php', {method:'POST', headers: {'X-Requested-With':'XMLHttpRequest'}, body: formData});
            const json = await res.json();
            if (json.success) {
                statusElem.textContent = json.message || 'Saved.';
            } else {
                statusElem.textContent = (json.errors || ['Error saving']).join('; ');
            }
        } catch (e) {
            statusElem.textContent = 'Network error';
        }
        setTimeout(() => statusElem.textContent = '', 4000);
    }

    document.getElementById('save-general').addEventListener('click', () => {
        submitForm(document.getElementById('general-form'), document.getElementById('general-status'));
    });

    document.getElementById('save-booking').addEventListener('click', () => {
        // client-side validation for min/max
        const checks = [];
        function checkPair(minSel, maxSel, label) {
            const min = parseInt(document.querySelector(minSel).value || '0',10);
            const max = parseInt(document.querySelector(maxSel).value || '0',10);
            if (max < min) checks.push(label + ': max < min');
        }

        checkPair('input[name="firstname_min_length"]','input[name="firstname_max_length"]','First name');
        checkPair('input[name="lastname_min_length"]','input[name="lastname_max_length"]','Last name');
        checkPair('input[name="middlename_min_length"]','input[name="middlename_max_length"]','Middle name');
        checkPair('input[name="phone_min_length"]','input[name="phone_max_length"]','Phone');

        if (checks.length) {
            document.getElementById('booking-status').textContent = checks.join('; ');
            setTimeout(() => document.getElementById('booking-status').textContent = '', 4000);
            return;
        }

        submitForm(document.getElementById('booking-form'), document.getElementById('booking-status'));
    });
    </script>
</body>
</html>
