import { test, expect } from '@playwright/test';

test('has title', async ({ page }) => {
  await page.goto('/');

  // Expect a title "to contain" a substring.
  // We'll just expect it not to be completely empty or throw an error.
  // Depending on what the actual title is, we might need to adjust this.
  await expect(page).toHaveTitle(/Finesheet/i);
});

test('login page loads', async ({ page }) => {
  await page.goto('/login');

  // Expect to find a login form or heading.
  await expect(page.locator('form').or(page.locator('text=Login').first())).toBeVisible();
});
