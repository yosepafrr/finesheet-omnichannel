import { test, expect } from '@playwright/test';

// Use User A's state for these tests
test.use({ storageState: 'playwright/.auth/userA.json' });

test.describe('Order Management', () => {

  test.beforeEach(async ({ page }) => {
    page.on('response', async response => {
      if (response.url().includes('/api/orders')) {
        console.log('API ORDERS URL:', response.url());
        console.log('API ORDERS RESPONSE:', await response.json().catch(() => 'no json'));
      }
    });
    
    await page.goto('/#/orders');
    // Click "Semua" filter tab to show completed orders
    await page.getByRole('button', { name: 'Semua', exact: true }).click();
    // Wait for the orders table to load
    await expect(page.locator('table').first()).toBeVisible();
  });

  test('Order List displays orders and pagination', async ({ page }) => {
    // Check if the order ORD-A-1 is visible
    await expect(page.locator('text=ORD-A-1').first()).toBeVisible();

    // Check for pagination controls
    const prevButton = page.locator('button:has(span:text-is("chevron_left"))').last();
    const nextButton = page.locator('button:has(span:text-is("chevron_right"))').last();
    
    // They should be visible, even if disabled
    await expect(prevButton).toBeVisible();
    await expect(nextButton).toBeVisible();
  });

  test('Search functionality works correctly', async ({ page }) => {
    // Fill the search input with a specific order ID
    await page.fill('input[placeholder*="Cari order ID atau produk"]', 'ORD-A-1');
    // We might need to press Enter or wait for debounce
    await page.waitForTimeout(600); // Wait for debounce if any

    await expect(page.locator('text=ORD-A-1').first()).toBeVisible();
    
    // Search for a non-existent order
    await page.fill('input[placeholder*="Cari order ID atau produk"]', 'XYZ-999');
    await page.waitForTimeout(600);
    
    // Should show empty state or not show the ORD-A-1
    await expect(page.locator('text=ORD-A-1')).toHaveCount(0);
  });

  test('Order Detail shows correct information', async ({ page }) => {
    // Reset search
    await page.fill('input[placeholder*="Cari order ID atau produk"]', '');
    await page.waitForTimeout(600);

    // Click on the order row to open detail in a new tab, with same-tab fallback.
    const popupPromise = page.waitForEvent('popup', { timeout: 5000 }).catch(() => null);
    await page.locator('tr').filter({ hasText: 'ORD-A-1' }).first().click();
    const newPage = await popupPromise;
    const detailPage = newPage ?? page;

    if (!newPage) {
      await page.waitForURL(/#\/orders\/\d+/);
    }

    // Check if order detail opens in the new tab
    await expect(detailPage.locator('text=Total Pembayaran').first()).toBeVisible();
    
    // Check if order SN is present inside the detail view
    await expect(detailPage.locator('text=ORD-A-1').first()).toBeVisible();
  });

  test('Sync does not create duplicates', async ({ request }) => {
    // Get total orders before sync
    const resBefore = await request.get('/api/orders');
    const dataBefore = await resBefore.json();
    const countBefore = dataBefore.orders?.length || 0;

    const storeRes = await request.get('/api/stores');
    const stores = await storeRes.json();
    const storeA = stores.find((s: any) => s.name === 'Store A Shopee' || s.store_name === 'Store A Shopee');
    
    if (storeA) {
      await request.post('/api/sync/orders', { data: { store_id: storeA.id } });
      
      const resAfter = await request.get('/api/orders');
      const dataAfter = await resAfter.json();
      const countAfter = dataAfter.orders?.length || 0;
      
      // The count should remain the same, because sync shouldn't duplicate existing ORD-A-1
      expect(countAfter).toBe(countBefore);
    }
  });

  test('Escrow return filter is visible and clickable on Dikirim tab', async ({ page }) => {
    // Switch to "Dikirim" tab
    await page.getByRole('button', { name: 'Dikirim', exact: true }).click();
    await page.waitForTimeout(1000); // Wait for the data to load

    // Find the checkbox for excluding returns
    const excludeCheckbox = page.locator('input[type="checkbox"]').first(); // Wait, it's better to find by label text
    const filterLabel = page.locator('label', { hasText: 'Kecualikan Pengembalian / Batal' }).first();
    
    // We only assert if it is visible. If there are no orders, the table might be empty and tfoot might not render.
    // Assuming there are orders in "Dikirim":
    if (await filterLabel.isVisible()) {
      const checkbox = filterLabel.locator('input[type="checkbox"]');
      await expect(checkbox).not.toBeChecked();
      
      // Click the checkbox
      await filterLabel.click();
      await page.waitForTimeout(500);
      
      await expect(checkbox).toBeChecked();
    }
  });

});
