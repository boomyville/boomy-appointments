const { test, expect } = require('@playwright/test');

const GUERRILLA_API = 'https://api.guerrillamail.com/ajax.php';
const GUERRILLA_AGENT = 'BoomyAppointmentsPlaywright/1.0';

async function guerrillaRequest(params, sidToken) {
  const url = new URL(GUERRILLA_API);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, String(value));
  }
  url.searchParams.set('ip', '127.0.0.1');
  url.searchParams.set('agent', GUERRILLA_AGENT);
  if (sidToken) url.searchParams.set('sid_token', sidToken);

  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`Guerrilla Mail returned HTTP ${response.status}`);
  }
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

async function waitForConfirmationEmail(inbox, timeoutMs = 120_000) {
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    const result = await guerrillaRequest(
      { f: 'check_email', seq: 0 },
      inbox.sidToken
    );
    const messages = result.list || [];

    for (const message of messages) {
      const email = await guerrillaRequest(
        { f: 'fetch_email', email_id: message.mail_id },
        inbox.sidToken
      );
      const body = String(email.mail_body || email.mail_body_plain || '');
      const decodedBody = body
        .replace(/&amp;/gi, '&')
        .replace(/&#x3D;/gi, '=')
        .replace(/&quot;/gi, '"');
      const match = decodedBody.match(
        /(?:https?:\/\/[^"'<>\s]+)?confirm_appointment\.php\?[^"'<>\s]+/i
      );

      if (match) {
        return new URL(match[0], process.env.E2E_BASE_URL).toString();
      }
    }

    await new Promise(resolve => setTimeout(resolve, 5_000));
  }

  throw new Error('Timed out waiting for the appointment confirmation email.');
}

test('customer can book, confirm by email, and admin can sign in', async ({ page }) => {
  const baseURL = process.env.E2E_BASE_URL;
  const adminUsername = process.env.E2E_ADMIN_USERNAME;
  const adminPassword = process.env.E2E_ADMIN_PASSWORD;

  expect(baseURL, 'Set the E2E_BASE_URL GitHub secret').toBeTruthy();
  expect(adminUsername, 'Set the E2E_ADMIN_USERNAME GitHub secret').toBeTruthy();
  expect(adminPassword, 'Set the E2E_ADMIN_PASSWORD GitHub secret').toBeTruthy();

  const inbox = await createInbox();
  let confirmationUrl;

  try {
    // Step 1: choose the first configured service and provider.
    await page.goto('booking.php');

    console.log('Booking URL:', page.url());
    console.log('Booking page title:', await page.title());

    await expect(page).toHaveURL(/\/appointments\/booking\.php/);
    await expect(page.locator('#appointmentTypeSelect')).toBeVisible();

    await expect(page.locator('#appointmentTypeSelect')).toBeVisible();
    await expect(page.locator('#providerSelect')).toBeVisible();
    await expect(page.locator('#appointmentTypeSelect option')).not.toHaveCount(0);
    await expect(page.locator('#providerSelect option')).not.toHaveCount(0);

    page.on('pageerror', error => {
        console.log('BROWSER ERROR:', error.message);
    });

    page.on('response', async response => {
        if (response.url().includes('booking_api.php?action=month')) {
            console.log('MONTH API STATUS:', response.status());
            console.log('MONTH API URL:', response.url());

            try {
                const data = await response.json();
                console.log('MONTH API RESPONSE:', JSON.stringify(data));
            } catch {
                console.log('MONTH API RESPONSE: Not JSON');
            }
        }
    });

    // Wait until booking page JavaScript has completed initialisation.
    await expect(page.locator('#toast')).toContainText('Ready', {
        timeout: 30_000
    });

    console.log('Booking page initialisation complete.');

    console.log('Selected appointment type:',
        await page.locator('#appointmentTypeSelect').inputValue()
    );

    console.log('Selected provider:',
        await page.locator('#providerSelect').inputValue()
    );

    await page.locator('#step1Continue').click();

    // Step 2: select the first available date and time.
    await expect(page.locator('#monthsContainer .month-card')).toBeVisible({
        timeout: 30_000
    });

    console.log(
        'CALENDAR CONTENT:',
        await page.locator('#monthsContainer').innerText()
    );

    console.log(
        'AVAILABLE DATES:',
        await page.locator('#monthsContainer [data-date].is-available').count()
    );

    const availableDate = page.locator(
        '#monthsContainer [data-date].is-available'
    ).first();

    await expect(availableDate).toBeVisible({ timeout: 30_000 });
    await availableDate.click();

    const availableSlot = page.locator('#slotList [data-slot]').first();
    await expect(availableSlot).toBeVisible({ timeout: 20_000 });
    await availableSlot.click();
    await page.locator('#step2Continue').click();

    // Step 3: enter a unique customer so the test can be identified.
    const marker = `E2E ${Date.now()}`;
    await page.locator('#newFirstName').fill('Playwright');
    await page.locator('#newLastName').fill(marker);
    await page.locator('#newEmail').fill(inbox.email);
    await page.locator('#newMobile').fill('0400000000');

    // These fields may be optional/hidden depending on the site's settings.
    const dob = page.locator('#newDob');
    if (await dob.isVisible()) await dob.fill('1990-01-01');
    const gender = page.locator('#newGender');
    if (await gender.isVisible()) await gender.selectOption('unknown');

    await page.locator('#step3Continue').click();
    await expect(page.locator('[data-panel="4"]')).toBeVisible();
    await page.locator('#confirmBookingBtn').click();

    // The booking creates a pending email-verification appointment.
    await expect(page.locator('#resultCard')).toContainText(/check your email|pending|verification/i, {
      timeout: 30_000
    });

    // Step 4: read the confirmation email through Guerrilla Mail's API.
    confirmationUrl = await waitForConfirmationEmail(inbox);
    await page.goto(confirmationUrl);
    await expect(page.getByRole('heading', { name: /confirm or modify your appointment/i })).toBeVisible();
    await page.getByRole('button', { name: /^confirm appointment$/i }).click();
    await expect(page.getByText(/thanks for confirming your appointment/i)).toBeVisible();

    // Step 5: verify that the configured admin account can sign in.
    await page.goto('login.php');
    await page.locator('#email').fill(adminUsername);
    await page.locator('#password').fill(adminPassword);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/admin\.php(?:$|[?#])/, { timeout: 20_000 });
    await expect(page).toHaveTitle(/admin dashboard/i);
  } finally {
    // Remove the appointment created by this test, even if a later assertion fails.
    // If the email never arrived, there is no confirmation URL available to clean up.
    if (confirmationUrl) {
      await page.goto(confirmationUrl).catch(() => {});
      const deleteButton = page.getByRole('button', { name: /delete appointment and book a new one|delete appointment/i });
      if (await deleteButton.isVisible().catch(() => false)) {
        page.once('dialog', dialog => dialog.accept());
        await deleteButton.click().catch(() => {});
      }
    }
  }
});
