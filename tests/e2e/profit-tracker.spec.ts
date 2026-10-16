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
    const filterReturn = page.getByRole('button', { name: 'Return / Batal Setelah Dikirim' });
    
    await expect(filterPerluDikirim).toBeVisible();
    await expect(filterDikirim).toBeVisible();
    await expect(filterReturn).toBeVisible();
    await expect(page.getByText('Coming Soon', { exact: true }).first()).toBeVisible();

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

  test('Analytics presets and styled date picker are available', async ({ page }) => {
    await expect(page.getByRole('button', { name: '7 Hari', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '14 Hari', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Pilih Hari', exact: true }).click();

    await expect(page.getByLabel('Tanggal analisis')).toBeVisible();
    await expect(page.locator('#analytics-date').locator('..').locator('..')).toHaveClass(/focus-within:ring/);
  });

  test('Analytics refreshes after a real-time order event', async ({ page }) => {
    const refreshRequest = page.waitForRequest((request) => (
      request.method() === 'GET'
      && request.url().includes('/api/profit-tracker')
      && request.url().includes('include_analytics=1')
    ));

    await page.evaluate(() => {
      window.dispatchEvent(new CustomEvent('order-updated', {
        detail: { id: 1, order_status: 'COMPLETED' },
      }));
    });

    await refreshRequest;
  });

});
