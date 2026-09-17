import { defineConfig, devices } from '@playwright/test';

/**
 * See https://playwright.dev/docs/test-configuration.
 */
export default defineConfig({
  testDir: './tests/e2e',

  /* Maximum time one test can run for.
   * 60s gives Firefox enough time to boot + render + run assertions. */
  timeout: 60000,

  /* Run tests in files in parallel */
  fullyParallel: true,

  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,

  /* Retry failed tests locally too, to handle Firefox slow-start flakiness.
   * CI uses 2 retries; local uses 1 so we don't wait too long. */
  retries: process.env.CI ? 2 : 1,

  /* Limit workers so php artisan serve (single-threaded) is not overwhelmed
   * when all 3 browsers run tests in parallel. 3 workers = 1 per browser. */
  workers: process.env.CI ? 1 : 3,

  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: 'html',

  /* Shared settings for all the projects below. */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    baseURL: 'http://127.0.0.1:8000',

    /* Collect trace when retrying the failed test. */
    trace: 'on-first-retry',

    /* Allow each action (click, fill, etc.) up to 15s before failing. */
    actionTimeout: 15000,

    /* Allow navigation (goto, waitForURL) up to 30s. */
    navigationTimeout: 30000,
  },

  /* Increase expect timeout to prevent flaky tests in slower UI/headed environments */
  expect: {
    timeout: 10000,
  },

  /* Configure projects for major browsers */
  projects: [
    { name: 'setup', testMatch: /.*\.setup\.ts/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
      dependencies: ['setup'],
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
      dependencies: ['setup'],
    },
  ],
});
