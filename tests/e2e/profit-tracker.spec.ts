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
    await expect(page.getByText('Iklan Shopee', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Pengeluaran iklan TikTok Shop belum termasuk dalam perhitungan profit.')).toBeVisible();

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
    const periodFilter = page.getByRole('button', { name: 'Rentang: 3 Hari' });
    await expect(periodFilter).toBeVisible();
    await periodFilter.click();
    await expect(page.getByRole('option', { name: '7 Hari' })).toBeVisible();
    await expect(page.getByRole('option', { name: '14 Hari' })).toBeVisible();

    await page.getByRole('option', { name: 'Pilih Hari' }).click();

    await expect(page.getByLabel('Tanggal analisis')).toBeVisible();
    await expect(page.locator('#analytics-date').locator('..').locator('..')).toHaveClass(/focus-within:ring/);
  });

  test('Store filter is scoped to the analytics section', async ({ page }) => {
    const analyticsSection = page.locator('#tour-profit-analytics');
    const storeFilter = analyticsSection.getByRole('button', { name: 'Toko: Semua Toko' });

    await expect(storeFilter).toBeVisible();
    await expect(page.locator('body > *').getByRole('button', { name: 'Toko: Semua Toko' })).toHaveCount(1);
    await storeFilter.click();
    await expect(page.getByRole('option', { name: 'Semua Toko' })).toBeVisible();
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

  test('Analytics refreshes after a Shopee ads spend event', async ({ page }) => {
    const refreshRequest = page.waitForRequest((request) => (
      request.method() === 'GET'
      && request.url().includes('/api/profit-tracker')
      && request.url().includes('include_analytics=1')
    ));

    await page.evaluate(() => {
      window.dispatchEvent(new CustomEvent('profit-analytics-updated', {
        detail: { spend_date: '2026-10-19', platform: 'Shopee' },
      }));
    });

    await refreshRequest;
  });

});
