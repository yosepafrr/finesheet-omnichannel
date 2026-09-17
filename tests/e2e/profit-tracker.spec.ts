import { test, expect } from '@playwright/test';

test.use({ storageState: 'playwright/.auth/userA.json' });

test.describe('Profit Tracker & Escrow Filters', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/#/profit-tracker');
    await page.waitForTimeout(1000); // Wait for initial loads
  });

  test('Escrow Filters render correctly and can be toggled', async ({ page }) => {
    // Locate the filter chip buttons
    const filterPerluDikirim = page.locator('button', { hasText: 'Perlu Dikirim' }).first();
    const filterDikirim = page.locator('button', { hasText: 'Dikirim' }).first();
    const filterReturn = page.locator('button', { hasText: 'Return / Batal' }).first();
    
    await expect(filterPerluDikirim).toBeVisible();
    await expect(filterDikirim).toBeVisible();
    await expect(filterReturn).toBeVisible();

    // Verify initial states (assuming default is Perlu Dikirim = ON, Dikirim = ON, Return = OFF)
    await expect(filterPerluDikirim).toHaveClass(/text-white/);
    await expect(filterDikirim).toHaveClass(/text-white/);
    await expect(filterReturn).not.toHaveClass(/text-white/);

    // Toggle "Perlu Dikirim" OFF
    await filterPerluDikirim.click();
    await page.waitForTimeout(500); // Wait for data to update
    await expect(filterPerluDikirim).not.toHaveClass(/text-white/);

    // Toggle "Return / Batal" ON
    await filterReturn.click();
    await page.waitForTimeout(500); // Wait for data to update
    await expect(filterReturn).toHaveClass(/text-white/);
  });

});
