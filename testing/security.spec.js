const { test, expect, request } = require('@playwright/test');

/**
 * Security regression tests. API level only (no browser), so they are fast and cheap on GitHub Actions.
 *
 * They run against the deployed site (E2E_BASE_URL), so they are written to be safe there:
 *  - never use the real admin e-mail for anything that locks or e-mails, unless E2E_TEST_ADMIN_RESET=1
 *  - use throwaway e-mail addresses for lockout / reset throttling (limits are per IP + e-mail)
 *  - do not exhaust the public booking buckets (customer_check / create) because booking.spec.js needs them
 *
 * Optional secrets:  E2E_PROVIDER_USERNAME / E2E_PROVIDER_PASSWORD (non-admin account, enables the
 *                    "provider cannot read mail settings" test)
 *                    E2E_TEST_ADMIN_RESET=1 (also run forgot-password against the real admin address; this
 *                    sends one reset e-mail to that address if mail is configured)
 */

const rawBase = process.env.E2E_BASE_URL || '';
const baseURL = rawBase.endsWith('/') ? rawBase : rawBase + '/';
const adminUsername = process.env.E2E_ADMIN_USERNAME;
const adminPassword = process.env.E2E_ADMIN_PASSWORD;
const providerUsername = process.env.E2E_PROVIDER_USERNAME;
const providerPassword = process.env.E2E_PROVIDER_PASSWORD;

// The tests share rate-limit buckets (same runner IP), so keep them in order.
// No serial mode: the specs run one after another anyway (workers: 1), and in serial mode one failure
// would hide the result of every test after it.

test.beforeAll(() => {
  expect(rawBase, 'Set the E2E_BASE_URL GitHub secret').toBeTruthy();
});

/** A fresh client with its own cookie jar = one "browser". */
async function newClient() {
  return request.newContext({ baseURL });
}

async function csrfToken(api) {
  const res = await api.get('booking_api.php?action=init');
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(body.csrf_token, 'init should hand out a CSRF token').toBeTruthy();
  return body.csrf_token;
}

async function postJson(api, path, data) {
  return api.post(path, { data, headers: { 'Content-Type': 'application/json' } });
}

async function login(api, email, password) {
  const token = await csrfToken(api);
  return postJson(api, 'auth.php?action=login', { email, password, csrf_token: token });
}

const CALENDAR = 'calendar_api.php?start=2030-01-01&end=2030-01-08';

test.describe('anonymous visitors', () => {
  test('staff APIs reject callers who are not logged in', async () => {
    const api = await newClient();
    const endpoints = [
      CALENDAR,
      'customers_api.php?action=search&q=a',
      'appointments_api.php',
      'settings_api.php?action=get',
      'settings_api.php?action=sessions',
      'users_api.php?action=search',
      'daily_notes_api.php',
      'unavailabilities_api.php',
    ];
    for (const endpoint of endpoints) {
      const res = await api.get(endpoint);
      expect(res.status(), `${endpoint} must answer 401 without a session`).toBe(401);
    }
    await api.dispose();
  });

  test('public booking API does not leak staff e-mails or debug data', async () => {
    const api = await newClient();

    const init = await (await api.get('booking_api.php?action=init')).json();
    expect(Array.isArray(init.providers)).toBe(true);
    for (const provider of init.providers) {
      expect(provider, 'staff e-mail addresses must not be public').not.toHaveProperty('email');
    }

    const token = init.csrf_token;
    const res = await postJson(api, 'booking_api.php?action=check_metadata_limit', { csrf_token: token });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(Object.keys(body), 'only the verdict may be returned, no IPs or customer rows').toEqual(['is_blocked']);
    await api.dispose();
  });
});

test.describe('password reset', () => {
  test('forgot-password never hands out a reset link and is throttled', async () => {
    const api = await newClient();
    const token = await csrfToken(api);
    const email = `e2e-reset-${Date.now()}@example.invalid`;

    let throttled = false;
    for (let i = 0; i < 6 && !throttled; i++) {
      const res = await postJson(api, 'auth.php?action=forgot_password', { email, csrf_token: token });
      expect([200, 429]).toContain(res.status());
      const text = await res.text();
      expect(text).not.toMatch(/reset_link|bypass|reset_password\.php/i);
      throttled = res.status() === 429;
    }
    expect(throttled, 'repeated requests for one address must hit the rate limit').toBe(true);
    await api.dispose();
  });

  test('forgot-password for the real admin address leaks nothing', async () => {
    test.skip(!process.env.E2E_TEST_ADMIN_RESET || !adminUsername, 'Opt in with E2E_TEST_ADMIN_RESET=1');
    const api = await newClient();
    const token = await csrfToken(api);

    const res = await postJson(api, 'auth.php?action=forgot_password', { email: adminUsername, csrf_token: token });
    expect([200, 429]).toContain(res.status());
    const text = await res.text();
    expect(text).not.toMatch(/reset_link|bypass|reset_password\.php/i);
    await api.dispose();
  });
});

test.describe('login throttling', () => {
  test('five failures lock that IP + e-mail pair, not the real admin account', async () => {
    const api = await newClient();
    const email = `e2e-lockout-${Date.now()}@example.invalid`;

    for (let i = 1; i <= 5; i++) {
      const res = await login(api, email, `wrong-password-${i}`);
      expect(res.status(), `attempt ${i} is a normal failed login`).toBe(401);
    }
    const locked = await login(api, email, 'wrong-password-6');
    expect(locked.status(), 'the sixth attempt must be refused outright').toBe(429);
    expect(locked.headers()['retry-after']).toBeTruthy();

    // Someone hammering a made-up address must not stop the real admin from signing in.
    test.skip(!adminUsername || !adminPassword, 'Admin credentials not provided');
    const adminApi = await newClient();
    const ok = await login(adminApi, adminUsername, adminPassword);
    expect(ok.status()).toBe(200);
    await adminApi.dispose();
    await api.dispose();
  });
});

test.describe('sessions', () => {
  test.skip(!adminUsername || !adminPassword, 'Admin credentials not provided');

  test('a revoked session stops working on the APIs immediately', async () => {
    const deviceA = await newClient();
    const deviceB = await newClient();

    expect((await login(deviceA, adminUsername, adminPassword)).status()).toBe(200);
    expect((await login(deviceB, adminUsername, adminPassword)).status()).toBe(200);
    expect((await deviceA.get(CALENDAR)).status(), 'device A works before revoke').toBe(200);

    // Device B signs every other device out (Settings > Sessions > "revoke all others").
    const token = await csrfToken(deviceB);
    const revoke = await postJson(deviceB, 'settings_api.php?action=sessions', { command: 'revoke_all_others', csrf_token: token });
    expect(revoke.status()).toBe(200);

    expect((await deviceA.get(CALENDAR)).status(), 'device A must be locked out of the API').toBe(401);
    expect((await deviceA.get('settings_api.php?action=get')).status()).toBe(401);
    expect((await deviceB.get(CALENDAR)).status(), 'device B stays signed in').toBe(200);

    await deviceA.dispose();
    await deviceB.dispose();
  });

  test('the admin session never exposes the SMTP password or its own hash', async () => {
    const admin = await newClient();
    expect((await login(admin, adminUsername, adminPassword)).status()).toBe(200);

    const settings = await (await admin.get('settings_api.php?action=get')).json();
    expect(settings.settings).not.toHaveProperty('smtp_password');

    const status = await (await admin.get('auth.php?action=status')).text();
    expect(status).not.toMatch(/password/i);
    await admin.dispose();
  });

  test('a provider (non-admin) cannot read mail settings', async () => {
    test.skip(!providerUsername || !providerPassword, 'Set E2E_PROVIDER_USERNAME / E2E_PROVIDER_PASSWORD to run this');
    const provider = await newClient();
    expect((await login(provider, providerUsername, providerPassword)).status()).toBe(200);

    const settings = await (await provider.get('settings_api.php?action=get')).json();
    const leaked = Object.keys(settings.settings).filter(key => key.startsWith('smtp_'));
    expect(leaked, 'providers must not receive any smtp_* setting').toEqual([]);

    expect((await provider.get('users_api.php?action=search')).status(), 'admin-only API').toBe(403);
    await provider.dispose();
  });
});
