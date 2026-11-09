const { test, expect } = require('@playwright/test');
const H = require('./helpers');

/**
 * Booking behaviour that must keep working: a taken slot is really taken, the "max active bookings"
 * limit locks the page, bookings in the past are refused, and a customer cannot attach a booking to
 * somebody else's profile.
 *
 * Needs the admin credentials: the tests create real (pending) bookings on the target site and delete
 * them again through the admin API. Customers are always named "Playwright E2E ...".
 *
 * The public create endpoint is throttled per IP (see booking_throttle in booking_api.php). If a re-run
 * hits that limit the affected test skips with a message instead of failing.
 */

// No serial mode: the specs run one after another anyway (workers: 1), and in serial mode one failure
// would hide the result of every test after it.

test.beforeAll(() => {
  expect(H.baseURL, 'Set the E2E_BASE_URL GitHub secret').not.toBe('/');
});

function skipIfThrottled(res) {
  test.skip(res.status() === 429, 'Booking endpoint is throttled for this IP (too many bookings in the last hour). Re-run later.');
}

async function openBookingPage(page, { appointmentTypeId, providerId } = {}) {
  await page.goto('booking.php');
  await expect(page.locator('#toast')).toContainText(/Ready|Booking locked/, { timeout: 30_000 });
  if (appointmentTypeId) await page.locator('#appointmentTypeSelect').selectOption(String(appointmentTypeId)).catch(() => {});
  if (providerId) await page.locator('#providerSelect').selectOption(String(providerId)).catch(() => {});
}

test('a booked slot disappears, cannot be double booked, and returns when the booking is deleted', async ({ page }) => {
  test.skip(!H.hasAdmin, 'Needs E2E_ADMIN_USERNAME / E2E_ADMIN_PASSWORD to clean up after itself');

  const api = await H.newClient();
  const admin = await H.adminClient();
  const customerIds = [];

  try {
    const init = await H.getInit(api);
    const found = await H.findFreeSlots(api, init, { count: 12 });
    test.skip(!found, 'No free slots found in the next two months');

    // Prefer a 3pm slot like a real customer would; otherwise take the first one.
    const slot = found.slots.find(s => /\s15:00:00$/.test(s.value)) || found.slots[0];
    const { providerId, appointmentTypeId } = found;
    const inbox = await H.createInbox();

    // 1. The slot is offered before we book it.
    expect(await H.daySlotValues(api, providerId, appointmentTypeId, slot.date)).toContain(slot.value);

    // 2. Book it.
    const created = await H.createBooking(api, init, { providerId, appointmentTypeId, startTime: slot.value, email: inbox.email });
    skipIfThrottled(created);
    expect(created.status(), await created.text()).toBe(200);
    const body = await created.json();
    customerIds.push(body.appointment?.customer_id);
    expect(body.pending, 'online bookings start as pending verification').toBe(true);
    expect(body, 'the verify link must only be e-mailed').not.toHaveProperty('verify_url');

    // 3. The slot is gone from the API...
    expect(await H.daySlotValues(api, providerId, appointmentTypeId, slot.date)).not.toContain(slot.value);

    // ...and from the booking page itself.
    await openBookingPage(page, { appointmentTypeId, providerId });
    await page.locator('#step1Continue').click();
    await expect(
      page.locator('#monthsContainer .month-card').filter({ hasNotText: 'Loading calendar...' }).first()
    ).toBeVisible({ timeout: 30_000 });
    const dateButton = page.locator(`#monthsContainer [data-date="${slot.date}"]`);
    if (await dateButton.isEnabled()) {
      await dateButton.click();
      await expect(page.locator('#slotList')).toBeVisible();
      await expect(page.locator(`#slotList [data-slot="${slot.value}"]`)).toHaveCount(0);
    } // else: it was the only slot that day, so the whole date is greyed out, which also proves it is gone.

    // 4. Booking the same slot again is refused.
    const second = await H.createBooking(api, init, { providerId, appointmentTypeId, startTime: slot.value, email: inbox.email });
    skipIfThrottled(second);
    expect(second.status(), 'double booking must be rejected').toBe(422);

    // 5. Verification: a customer may only pick profiles that share their own email + mobile.
    const verifyLink = await H.waitForEmailLink(inbox, 'verify_appointment');
    const verifyToken = new URL(verifyLink).searchParams.get('token');
    expect(verifyToken).toBeTruthy();

    // A second, unrelated customer: the one a malicious visitor would try to attach their booking to.
    const adminCsrf = await H.csrfToken(admin);
    const otherCustomer = await H.postJson(admin, 'customers_api.php?action=create', {
      first_name: 'Playwright',
      last_name: `E2E other ${Date.now()}`,
      email: H.fakeEmail('other'),
      mobile: H.randomMobile(),
      date_of_birth: '1990-01-01',
      gender: 'unknown',
      csrf_token: adminCsrf,
    });
    expect(otherCustomer.status(), await otherCustomer.text()).toBeLessThan(300);
    const otherCustomerId = (await otherCustomer.json()).customer.id;
    customerIds.push(otherCustomerId);

    const csrf = await H.csrfToken(api);
    const foreign = await H.postJson(api, 'booking_api.php?action=verify', {
      csrf_token: csrf, token: verifyToken, customer_option: String(otherCustomerId),
    });
    expect(foreign.status(), 'attaching the booking to someone else\'s profile must be refused').toBe(403);

    const verified = await H.postJson(api, 'booking_api.php?action=verify', {
      csrf_token: csrf, token: verifyToken, customer_option: 'current',
    });
    expect(verified.status(), await verified.text()).toBe(200);
    const verifiedBody = await verified.json();
    expect(verifiedBody.appointment.status).toBe('booked');
    expect(verifiedBody.appointment.customer_id, 'booking stays with the customer who made it').toBe(customerIds[0]);

    // A confirmed booking still holds the slot.
    expect(await H.daySlotValues(api, providerId, appointmentTypeId, slot.date)).not.toContain(slot.value);

    // 6. Deleting the customer (cascades to the booking) frees the slot again.
    expect(await H.deleteCustomers(admin, [customerIds[0]])).toBe(1);
    expect(await H.daySlotValues(api, providerId, appointmentTypeId, slot.date)).toContain(slot.value);
  } finally {
    await H.deleteCustomers(admin, customerIds).catch(() => {});
    await admin.dispose();
    await api.dispose();
  }
});

test('the "max active bookings" limit locks the booking page', async ({ page }) => {
  test.skip(!H.hasAdmin, 'Needs E2E_ADMIN_USERNAME / E2E_ADMIN_PASSWORD to clean up after itself');

  const api = await H.newClient();
  const admin = await H.adminClient();
  const customerIds = [];

  try {
    const init = await H.getInit(api);
    const limit = Number(init.prevent_same_user_booking || 0);
    test.skip(limit < 1, 'prevent_same_user_booking is 0 (limit switched off)');
    test.skip(limit > 8, `The limit is ${limit}; too many bookings to create in a test run`);

    const found = await H.findFreeSlots(api, init, { count: limit + 1 });
    test.skip(!found || found.slots.length < limit + 1, `Need ${limit + 1} free slots to test the limit`);
    const inbox = await H.createInbox();

    const check = async () => {
      const res = await H.postJson(api, 'booking_api.php?action=check_metadata_limit', {
        csrf_token: await H.csrfToken(api),
        client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
      });
      expect(res.status()).toBe(200);
      return (await res.json()).is_blocked;
    };

    // Create bookings one by one until the limit trips (leftovers from earlier runs count too).
    let blocked = await check();
    for (let i = 0; i < limit && !blocked; i++) {
      const res = await H.createBooking(api, init, {
        providerId: found.providerId,
        appointmentTypeId: found.appointmentTypeId,
        startTime: found.slots[i].value,
        email: inbox.email,
      });
      skipIfThrottled(res);
      expect(res.status(), await res.text()).toBe(200);
      customerIds.push((await res.json()).appointment?.customer_id);
      blocked = await check();
    }
    expect(blocked, `after ${limit} active bookings check_metadata_limit must report is_blocked`).toBe(true);

    // The booking page shows the lock instead of the booking form.
    await openBookingPage(page);
    await expect(page.locator('#step1RestrictionNotice')).toContainText(/Booking Limit Exceeded/i);
    await expect(page.locator('#step1SelectionGrid')).toBeHidden();

    // Informational: is the limit also enforced by the server when someone skips the page and calls
    // the create endpoint directly? Today it is only enforced by the page, so this is a note, not a failure.
    const direct = await H.createBooking(api, init, {
      providerId: found.providerId,
      appointmentTypeId: found.appointmentTypeId,
      startTime: found.slots[limit].value,
      email: inbox.email,
    });
    if (direct.status() === 200) customerIds.push((await direct.json()).appointment?.customer_id);
    const enforcement = direct.status() === 200
      ? 'Server did NOT refuse a booking over the limit via the API (limit only locks the page).'
      : `Server refused a booking over the limit via the API (HTTP ${direct.status()}).`;
    test.info().annotations.push({ type: 'note', description: enforcement });
    console.log(`[note] ${enforcement}`);

    // Removing the test bookings lifts the lock again.
    await H.deleteCustomers(admin, customerIds);
    expect.soft(await check(), 'limit should clear once the test bookings are deleted').toBe(false);
  } finally {
    await H.deleteCustomers(admin, customerIds).catch(() => {});
    await admin.dispose();
    await api.dispose();
  }
});

test('bookings in the past are refused', async () => {
  const api = await H.newClient();
  const init = await H.getInit(api);
  const type = init.appointment_types[0];
  const provider = init.providers[0];
  test.skip(!type || !provider, 'No bookable type / provider');

  const res = await H.createBooking(api, init, {
    providerId: provider.id,
    appointmentTypeId: type.id,
    startTime: '2020-01-06 10:00:00',
    email: 'e2e-past-booking@example.invalid',
  });
  skipIfThrottled(res);
  expect(res.status()).toBe(422);
  expect((await res.json()).code).toBe('insufficient_booking_notice');
  await api.dispose();
});

test('the calendar does not open beyond the maximum advance booking window', async () => {
  const api = await H.newClient();
  const init = await H.getInit(api);
  const type = init.appointment_types[0];
  const provider = init.providers[0];
  test.skip(!type || !provider, 'No bookable type / provider');

  const farFuture = new Date();
  farFuture.setFullYear(farFuture.getFullYear() + 3);
  const month = `${farFuture.getFullYear()}-${String(farFuture.getMonth() + 1).padStart(2, '0')}`;

  const res = await api.get(
    `booking_api.php?action=month&provider_id=${provider.id}&appointment_type_id=${type.id}&month=${month}`
  );
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(body.out_of_range).toBe(true);
  expect(body.month).toBeNull();
  await api.dispose();
});
