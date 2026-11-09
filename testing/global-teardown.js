const { adminClient, sweepPlaywrightData, hasAdmin } = require('./helpers');

/**
 * Runs once after every spec. Deletes the throw-away customers (and so their appointments) the
 * Playwright tests created. Controlled by E2E_CLEANUP=true, which the workflow sets from its
 * "delete_test_data" input. A cleanup problem never fails the test run.
 */
module.exports = async function globalTeardown() {
  if (String(process.env.E2E_CLEANUP).toLowerCase() !== 'true') {
    console.log('[cleanup] skipped (delete_test_data is off). Test customers named "Playwright E2E ..." were left in place.');
    return;
  }
  if (!hasAdmin) {
    console.log('[cleanup] skipped: E2E_ADMIN_USERNAME / E2E_ADMIN_PASSWORD not set.');
    return;
  }

  let admin;
  try {
    admin = await adminClient();
    const removed = await sweepPlaywrightData(admin);
    console.log(`[cleanup] removed ${removed} "Playwright E2E" customer(s) and their appointments.`);
  } catch (error) {
    console.log(`[cleanup] failed, please remove "Playwright E2E" customers by hand: ${error.message}`);
  } finally {
    if (admin) await admin.dispose();
  }
};
