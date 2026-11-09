const { expect, request } = require('@playwright/test');

/**
 * Helpers shared by the API-level specs (security-basics, booking-rules) and the global teardown.
 *
 * Every customer these tests create is named "Playwright E2E <something>", which is how the cleanup
 * finds them again. Never change that prefix without changing TEST_NAME_PATTERN.
 */

const rawBase = process.env.E2E_BASE_URL || '';
const baseURL = rawBase.endsWith('/') ? rawBase : rawBase + '/';
const adminUsername = process.env.E2E_ADMIN_USERNAME;
const adminPassword = process.env.E2E_ADMIN_PASSWORD;
const hasAdmin = Boolean(adminUsername && adminPassword);

const TEST_NAME_PREFIX = 'Playwright E2E';
const TEST_NAME_PATTERN = /^Playwright E2E\b/;

/* ------------------------------------------------------------------ plain HTTP helpers */

/** A fresh client with its own cookie jar = one "browser". */
async function newClient(extra = {}) {
  return request.newContext({ baseURL, ...extra });
}

async function getInit(api) {
  const res = await api.get('booking_api.php?action=init');
  expect(res.status(), 'booking_api init').toBe(200);
  const body = await res.json();
  expect(body.csrf_token, 'init should hand out a CSRF token').toBeTruthy();
  return body;
}

async function csrfToken(api) {
  return (await getInit(api)).csrf_token;
}

function postJson(api, path, data) {
  return api.post(path, { data, headers: { 'Content-Type': 'application/json' } });
}

async function login(api, email, password) {
  const token = await csrfToken(api);
  return postJson(api, 'auth.php?action=login', { email, password, csrf_token: token });
}

async function adminClient() {
  const api = await newClient();
  const res = await login(api, adminUsername, adminPassword);
  expect(res.status(), 'admin login').toBe(200);
  return api;
}

/* ------------------------------------------------------------------ booking helpers */

/**
 * An address on the same disposable domain the booking test already uses, so it passes an e-mail
 * domain whitelist if the clinic has one. Nothing is ever sent to it (admin-created customers).
 */
function fakeEmail(tag) {
  return `e2e-${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@guerrillamailblock.com`;
}

function randomMobile() {
  return '04' + String(Math.floor(Math.random() * 1e8)).padStart(8, '0');
}

/**
 * Walks the same public endpoints the booking page uses and returns free slots.
 * The first available day is skipped on purpose so we stay out of the way of booking.spec.js and of
 * anything a real customer is about to book.
 */
async function findFreeSlots(api, init, { count = 1, skipDays = 1 } = {}) {
  const months = [init.month_start, init.next_month_start]
    .filter(Boolean)
    .map(d => d.slice(0, 7));

  for (const type of init.appointment_types) {
    for (const provider of init.providers) {
      const slots = [];
      let skipped = 0;

      for (const month of months) {
        const monthRes = await api.get(
          `booking_api.php?action=month&provider_id=${provider.id}&appointment_type_id=${type.id}&month=${month}`
        );
        if (monthRes.status() !== 200) continue;
        const monthData = (await monthRes.json()).month;
        if (!monthData) continue;

        for (const day of monthData.days) {
          if (!day.in_month || !day.has_slots) continue;
          if (skipped < skipDays) { skipped++; continue; }

          const dayRes = await api.get(
            `booking_api.php?action=day_slots&provider_id=${provider.id}&appointment_type_id=${type.id}&date=${day.date}`
          );
          if (dayRes.status() !== 200) continue;
          for (const slot of (await dayRes.json()).slots || []) {
            slots.push({ date: day.date, value: slot.value, label: slot.label, start: slot.start });
            if (slots.length >= count) {
              return { providerId: provider.id, appointmentTypeId: type.id, slots };
            }
          }
        }
      }
    }
  }
  return null;
}

async function daySlotValues(api, providerId, appointmentTypeId, date) {
  const res = await api.get(
    `booking_api.php?action=day_slots&provider_id=${providerId}&appointment_type_id=${appointmentTypeId}&date=${date}`
  );
  expect(res.status(), 'day_slots').toBe(200);
  return ((await res.json()).slots || []).map(s => s.value);
}

/** Answers for any question the clinic marked as required (first option is always valid). */
function requiredAnswers(init) {
  const answers = {};
  for (const q of init.user_questions || []) {
    if (q.required && Array.isArray(q.options) && q.options.length) answers[q.id] = q.options[0];
  }
  return answers;
}

let nameCounter = 0;

/** Creates a (pending verification) online booking exactly like the booking page does. */
async function createBooking(api, init, { providerId, appointmentTypeId, startTime, email, csrf, overrides = {} }) {
  nameCounter += 1;
  const token = csrf || (await csrfToken(api));
  const lastName = `E2E ${Date.now()}-${nameCounter}`;
  const payload = {
    csrf_token: token,
    provider_id: providerId,
    appointment_type_id: appointmentTypeId,
    start_time: startTime,
    customer_mode: 'new',
    allow_duplicate: false,
    notes: '',
    answers: requiredAnswers(init),
    customer_first_name: 'Playwright',
    customer_last_name: lastName,
    customer_name: `Playwright ${lastName}`,
    customer_email: email,
    customer_mobile: randomMobile(),
    customer_date_of_birth: '1990-01-01',
    customer_gender: 'unknown',
    client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
    client_screen: '1280x720',
    client_locale: 'en-AU',
    ...overrides,
  };
  return postJson(api, 'booking_api.php?action=create', payload);
}

/* ------------------------------------------------------------------ guerrilla mail */

const GUERRILLA_API = 'https://api.guerrillamail.com/ajax.php';
const GUERRILLA_AGENT = 'BoomyAppointmentsPlaywright/1.0';

async function guerrillaRequest(params, sidToken) {
  const url = new URL(GUERRILLA_API);
  for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value));
  url.searchParams.set('ip', '127.0.0.1');
  url.searchParams.set('agent', GUERRILLA_AGENT);
  if (sidToken) url.searchParams.set('sid_token', sidToken);

  const response = await fetch(url);
  if (!response.ok) throw new Error(`Guerrilla Mail returned HTTP ${response.status}`);
  const data = await response.json();
  if (data.error) throw new Error(`Guerrilla Mail: ${data.error}`);
  return data;
}

async function createInbox() {
  const inbox = await guerrillaRequest({ f: 'get_email_address', lang: 'en' });
  if (!inbox.email_addr || !inbox.sid_token) {
    throw new Error('Guerrilla Mail did not return an email address and session token.');
  }
  return { email: inbox.email_addr, sidToken: inbox.sid_token };
}

/** Waits for an e-mail containing a link to <pageName>.php and returns that link (absolute). */
async function waitForEmailLink(inbox, pageName, timeoutMs = 120_000) {
  const pattern = new RegExp(`(?:https?:\\/\\/[^"'<>\\s]+)?${pageName}\\.php\\?[^"'<>\\s]+`, 'i');
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    const result = await guerrillaRequest({ f: 'check_email', seq: 0 }, inbox.sidToken);
    for (const message of result.list || []) {
      const email = await guerrillaRequest({ f: 'fetch_email', email_id: message.mail_id }, inbox.sidToken);
      const body = String(email.mail_body || email.mail_body_plain || '')
        .replace(/&amp;/gi, '&')
        .replace(/&#x3D;/gi, '=')
        .replace(/&quot;/gi, '"');
      const match = body.match(pattern);
      if (match) return new URL(match[0], baseURL).toString();
    }
    await new Promise(resolve => setTimeout(resolve, 5_000));
  }
  throw new Error(`Timed out waiting for the ${pageName} email.`);
}

/* ------------------------------------------------------------------ cleanup */

/** Deletes specific customers (and, through the database cascade, their appointments). */
async function deleteCustomers(admin, ids) {
  const token = await csrfToken(admin);
  let deleted = 0;
  for (const id of ids.filter(Boolean)) {
    const res = await postJson(admin, 'customers_api.php?action=delete', { customer_id: id, csrf_token: token });
    if (res.status() === 200) deleted++;
  }
  return deleted;
}

/**
 * Removes every customer whose name starts with "Playwright E2E", together with their appointments.
 * Only ever touches names with that exact prefix.
 */
async function sweepPlaywrightData(admin) {
  let total = 0;
  for (let round = 0; round < 25; round++) {
    const res = await admin.get(
      `customers_api.php?action=search&limit=20&q=${encodeURIComponent(TEST_NAME_PREFIX)}`
    );
    if (res.status() !== 200) throw new Error(`customer search returned HTTP ${res.status()}`);
    const matches = ((await res.json()).customers || []).filter(c => TEST_NAME_PATTERN.test(c.full_name || ''));
    if (!matches.length) break;
    const deleted = await deleteCustomers(admin, matches.map(c => Number(c.id)));
    if (!deleted) break;
    total += deleted;
  }
  return total;
}

module.exports = {
  baseURL, adminUsername, adminPassword, hasAdmin,
  TEST_NAME_PREFIX, TEST_NAME_PATTERN,
  newClient, getInit, csrfToken, postJson, login, adminClient,
  fakeEmail, randomMobile, findFreeSlots, daySlotValues, requiredAnswers, createBooking,
  createInbox, waitForEmailLink,
  deleteCustomers, sweepPlaywrightData,
};
