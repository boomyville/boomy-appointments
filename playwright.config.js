const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './testing',
  timeout: 150_000,
  expect: {
    timeout: 15_000
  },
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL,
    browserName: 'chromium',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure'
  }
});
