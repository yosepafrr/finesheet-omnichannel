import { test as setup, expect } from '@playwright/test';
import * as dotenv from 'dotenv';
import { writeFile } from 'node:fs/promises';
dotenv.config({ path: '.env.test' });

const authFileA = 'playwright/.auth/userA.json';
const authFileB = 'playwright/.auth/userB.json';

setup('authenticate as User A', async ({ page }) => {
  await page.goto('/login');
  
  // Wait for the React HashRouter to redirect if needed
  await page.waitForURL(/#\/login/);

  await page.fill('input[type="email"]', process.env.TEST_USER_A_EMAIL!);
  await page.fill('input[type="password"]', process.env.TEST_USER_A_PASSWORD!);
  await page.click('button[type="submit"]');

  // Wait until the page receives the cookies/redirect to dashboard.
  // The React app redirects to /#/dashboard or /#/ after login.
  await page.waitForURL(/#\/(dashboard)?$/);

  // Save authentication state
  const state = await page.context().storageState();
  await writeFile(authFileA, JSON.stringify({ ...state, origins: [] }, null, 2));
});

setup('authenticate as User B', async ({ page }) => {
  await page.goto('/login');
  
  // Wait for the React HashRouter to redirect if needed
  await page.waitForURL(/#\/login/);

  await page.fill('input[type="email"]', process.env.TEST_USER_B_EMAIL!);
  await page.fill('input[type="password"]', process.env.TEST_USER_B_PASSWORD!);
  await page.click('button[type="submit"]');

  await page.waitForURL(/#\/(dashboard)?$/);

  const state = await page.context().storageState();
  await writeFile(authFileB, JSON.stringify({ ...state, origins: [] }, null, 2));
});
