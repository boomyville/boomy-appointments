const { test, expect } = require('@playwright/test');
const H = require('./helpers');

/**
 * Baseline web-security checks that should always pass. API level, no browser.
 * (security.spec.js covers the specific fixes for the audit; this file covers the general hygiene.)
 *
 * Safe to run against the live site: nothing here creates data except one throw-away customer in the
 * "known gap" test, which removes itself, and there are no lockouts or e-mails.
 */

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  expect(H.baseURL, 'Set the E2E_BASE_URL GitHub secret').not.toBe('/');
});

const LEAK_PATTERN = /Stack trace|PDOException|SQLSTATE|Fatal error|Parse error|Warning:|Notice:|Deprecated:|on line \d+|\/var\/www|\/home\/|\/srv\//i;

/* ------------------------------------------------------------------------------------------ CSRF */

test.describe('CSRF protection', () => {
  const publicPosts = [
    ['auth.php?action=login', { email: 'nobody@example.invalid', password: 'x' }],
    ['auth.php?action=forgot_password', { email: 'nobody@example.invalid' }],
    ['auth.php?action=reset_password', { code: 'x', password: 'x', password_confirm: 'x' }],
    ['auth.php?action=logout', {}],
    ['booking_api.php?action=create', { provider_id: 1, appointment_type_id: 1, start_time: '2030-01-01 10:00:00' }],
    ['booking_api.php?action=customer_check', { customer_mode: 'new', customer_email: 'a@example.invalid', customer_mobile: '0400000000' }],
    ['booking_api.php?action=verify', { token: 'x', customer_option: 'current' }],
  ];

  test('login, reset and booking POSTs refuse a missing or wrong CSRF token', async () => {
    const api = await H.newClient();
    await H.getInit(api); // starts a session so the token check is real, not "no session"

    for (const [path, body] of publicPosts) {
      for (const [label, extra] of [['no token', {}], ['wrong token', { csrf_token: 'definitely-not-the-token' }]]) {
        const res = await H.postJson(api, path, { ...body, ...extra });
        expect([400, 403], `${path} with ${label} must be refused`).toContain(res.status());
        expect(await res.text(), `${path} with ${label}`).toMatch(/csrf/i);
      }
    }
    await api.dispose();
  });

  test('staff POSTs refuse a missing or wrong CSRF token', async () => {
    test.skip(!H.hasAdmin, 'Admin credentials not provided');
    const admin = await H.adminClient();

    const staffPosts = [
      ['settings_api.php?action=save', { settings: {} }],
      ['settings_api.php?action=change_password', { current_password: 'x', new_password: 'x' }],
      ['appointments_api.php?action=create', {}],
      ['appointments_api.php?action=update', { id: 999999999 }],
      ['appointments_api.php?action=delete', { id: 999999999 }],
    ];

    for (const [path, body] of staffPosts) {
      for (const [label, extra] of [['no token', {}], ['wrong token', { csrf_token: 'definitely-not-the-token' }]]) {
        const res = await H.postJson(admin, path, { ...body, ...extra });
        expect([400, 403], `${path} with ${label} must be refused`).toContain(res.status());
        expect(await res.text(), `${path} with ${label}`).toMatch(/csrf/i);
      }
    }
    await admin.dispose();
  });

  // KNOWN GAP (found in the audit): customers_api.php and users_api.php change data without checking
  // a CSRF token. SameSite=Lax cookies blunt most of the risk, but it is still missing defence in depth.
  // test.fail() makes this pass while the gap exists and flags it the day it is fixed.
  test('customer delete refuses a request without a CSRF token', async () => {
    test.skip(!H.hasAdmin, 'Admin credentials not provided');
    test.fail(true, 'customers_api.php has no CSRF check yet. Remove this line once it does.');

    const admin = await H.adminClient();
    const token = await H.csrfToken(admin);
    let customerId = null;
    try {
      const created = await H.postJson(admin, 'customers_api.php?action=create', {
        first_name: 'Playwright',
        last_name: `E2E csrf-probe ${Date.now()}`,
        email: H.fakeEmail('csrf'),
        mobile: H.randomMobile(),
        date_of_birth: '1990-01-01',
        gender: 'unknown',
        csrf_token: token,
      });
      // If the probe customer cannot be created the test cannot say anything: skip rather than
      // "fail as expected" for the wrong reason.
      test.skip(created.status() >= 300, `could not create the probe customer: ${await created.text()}`);
      customerId = (await created.json()).customer?.id;
      test.skip(!customerId, 'could not read the probe customer id');

      const res = await H.postJson(admin, 'customers_api.php?action=delete', { customer_id: customerId });
      expect([400, 403], 'a delete without a CSRF token must be refused').toContain(res.status());
      customerId = null; // refused, so the customer still exists and the sweep below removes it
    } finally {
      await H.sweepPlaywrightData(admin).catch(() => {});
      await admin.dispose();
    }
  });
});

/* ------------------------------------------------------------------------- information leaks */

test.describe('information leaks and injection', () => {
  test('malformed input never exposes stack traces, SQL errors or server paths', async () => {
    const api = await H.newClient();
    const init = await H.getInit(api);
    const csrf = init.csrf_token;

    const probes = [
      () => api.get('booking_api.php?action=month&provider_id=abc&appointment_type_id[]=1&month=../../etc/passwd'),
      () => api.get('booking_api.php?action=day_slots&provider_id=1&appointment_type_id=1&date=9999-99-99'),
      () => api.get(`booking_api.php?action=request&token=${'A'.repeat(5000)}`),
      () => api.get('booking_api.php?action=does_not_exist'),
      () => api.get('auth.php?action=does_not_exist'),
      () => H.postJson(api, 'booking_api.php?action=customer_check', {
        csrf_token: csrf, customer_mode: ['x'], customer_email: { a: 1 }, customer_mobile: [[]],
      }),
      () => api.post('auth.php?action=login', { data: '{not json', headers: { 'Content-Type': 'application/json' } }),
      () => api.post('booking_api.php?action=customer_check', { data: 'x=1&y[]=2', headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }),
    ];

    for (const [i, probe] of probes.entries()) {
      const res = await probe();
      const text = await res.text();
      expect(res.status(), `probe ${i + 1} must not crash the server`).toBeLessThan(500);
      expect(text, `probe ${i + 1} leaked internals`).not.toMatch(LEAK_PATTERN);
    }
    await api.dispose();
  });

  test('SQL injection strings are treated as plain text', async () => {
    const api = await H.newClient();
    const token = await H.csrfToken(api);

    const login = await H.postJson(api, 'auth.php?action=login', {
      email: "admin@example.com' OR '1'='1' -- ", password: "' OR '1'='1", csrf_token: token,
    });
    expect([400, 401, 422], 'injection must not log anyone in').toContain(login.status());
    expect(await login.text()).not.toMatch(LEAK_PATTERN);

    const month = await api.get("booking_api.php?action=month&provider_id=1 OR 1=1&appointment_type_id=1;DROP TABLE users&month=2030-01");
    expect(month.status()).toBeLessThan(500);
    expect(await month.text()).not.toMatch(LEAK_PATTERN);

    if (H.hasAdmin) {
      const admin = await H.adminClient();
      const search = await admin.get(`customers_api.php?action=search&q=${encodeURIComponent("' OR 1=1 -- ")}`);
      expect(search.status()).toBe(200);
      const body = await search.json();
      expect(body.customers, 'a quote and SQL comment must not match every customer').toEqual([]);
      await admin.dispose();
    }
    await api.dispose();
  });

  test('source, config and developer files are not served', async () => {
    const api = await H.newClient();
    const paths = [
      '.git/HEAD', '.git/config', 'install.sql', 'composer.json', 'phpunit.xml.dist',
      'config_sample.php', 'README.md', 'tests/bootstrap.php', 'testing/security.spec.js',
      '.github/workflows/end_to_end.yml',
    ];
    for (const path of paths) {
      const res = await api.get(path, { failOnStatusCode: false, maxRedirects: 0 });
      expect([403, 404], `${path} must not be reachable`).toContain(res.status());
    }

    // config.php may legitimately run (it only defines constants) but must never show its source.
    const config = await api.get('config.php', { failOnStatusCode: false, maxRedirects: 0 });
    expect(await config.text()).not.toMatch(/DB_PASS|DB_USER|define\s*\(/);
    await api.dispose();
  });

  test('the reminder cron endpoint cannot be triggered without the key', async () => {
    const api = await H.newClient();
    for (const path of ['cron_reminders.php', 'cron_reminders.php?key=wrong-key', 'cron_reminders.php?key=']) {
      const res = await api.get(path, { failOnStatusCode: false });
      expect(res.status(), `${path} must be forbidden`).toBe(403);
    }
    await api.dispose();
  });

  test('self-registration is closed once an admin exists', async () => {
    // The password is deliberately too weak: if registration were ever open this request would be
    // rejected by validation and could not create an account.
    const api = await H.newClient();
    const token = await H.csrfToken(api);
    for (const action of ['register', 'register_first_admin']) {
      const res = await H.postJson(api, `auth.php?action=${action}`, {
        email: 'e2e-register-probe@example.invalid',
        password: 'x',
        first_name: 'Probe',
        last_name: 'Probe',
        csrf_token: token,
      });
      expect(res.status(), `${action} must be blocked`).toBe(403);
    }
    await api.dispose();
  });
});

/* -------------------------------------------------------------------------- sessions & cookies */

test.describe('sessions and cookies', () => {
  test('the session cookie is HttpOnly, SameSite and (over HTTPS) Secure', async () => {
    const api = await H.newClient();
    const res = await api.get('booking_api.php?action=init');
    const cookies = res.headersArray()
      .filter(h => h.name.toLowerCase() === 'set-cookie')
      .map(h => h.value);
    const session = cookies.find(c => /sess/i.test(c.split('=')[0]));
    expect(session, 'a session cookie should be set').toBeTruthy();

    expect(session).toMatch(/;\s*HttpOnly/i);
    expect(session).toMatch(/;\s*SameSite=(Lax|Strict)/i);
    if (H.baseURL.startsWith('https://')) {
      expect(session, 'the session cookie must carry the Secure flag when served over HTTPS').toMatch(/;\s*Secure/i);
    }
    await api.dispose();
  });

  test('login does not reveal which e-mail addresses have accounts', async () => {
    test.skip(!H.hasAdmin, 'Admin credentials not provided');
    const api = await H.newClient();
    const unknown = await H.login(api, `e2e-nobody-${Date.now()}@example.invalid`, 'wrong-password-1');
    const known = await H.login(api, H.adminUsername, `wrong-${Date.now()}`);

    expect(known.status()).toBe(unknown.status());
    expect((await known.json()).error).toBe((await unknown.json()).error);
    await api.dispose();
  });

  test('logging in issues a new session id, and logging out kills the old one', async () => {
    test.skip(!H.hasAdmin, 'Admin credentials not provided');
    const sessionCookie = state => state.cookies.find(c => /sess/i.test(c.name));

    const browser = await H.newClient();
    await H.getInit(browser);
    const before = sessionCookie(await browser.storageState());
    expect(before, 'anonymous session cookie').toBeTruthy();

    expect((await H.login(browser, H.adminUsername, H.adminPassword)).status()).toBe(200);
    const loggedIn = await browser.storageState();
    expect(sessionCookie(loggedIn).value, 'session id must change at login (session fixation)').not.toBe(before.value);

    const calendar = 'calendar_api.php?start=2030-01-01&end=2030-01-08';
    expect((await browser.get(calendar)).status()).toBe(200);

    const logout = await H.postJson(browser, 'auth.php?action=logout', { csrf_token: await H.csrfToken(browser) });
    expect(logout.status()).toBe(200);

    // Someone who copied the cookie before logout must not get in afterwards.
    const replay = await H.newClient({ storageState: loggedIn });
    expect((await replay.get(calendar)).status(), 'a logged-out session must stay dead').toBe(401);

    await replay.dispose();
    await browser.dispose();
  });
});
