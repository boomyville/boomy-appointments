const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './testing',
  timeout: 150_000,
  expect: {
    timeout: 15_000
  },
  retries: 0,
  // Every spec shares one runner IP and therefore the same rate-limit / booking-limit buckets,
  // so the files must run one after another.
  workers: 1,
  fullyParallel: false,
  globalTeardown: require.resolve('./testing/global-teardown.js'),
  reporter: [
    ['list'],
    ['junit', { outputFile: 'test-results/junit.xml' }]
  ],
  use: {
    baseURL: process.env.E2E_BASE_URL,
    browserName: 'chromium',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure'
  }
});
