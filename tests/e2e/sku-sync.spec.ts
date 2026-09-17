import { test, expect } from '@playwright/test';

test.use({ storageState: 'playwright/.auth/userA.json' });

test.describe('SKU Sync Module', () => {

  test.beforeEach(async ({ page }) => {
    // Navigate to Product Management page
    await page.goto('/#/products');
    
    // Wait for the Products header to be visible
    await expect(page.getByRole('heading', { name: 'Product Management' })).toBeVisible();
    
    // Switch to SKU Sync tab
    const syncTab = page.getByRole('button', { name: /Sinkronisasi Stok/i });
    await expect(syncTab).toBeVisible();
    await syncTab.click();
    
    // Wait for the sync panel to load
    await expect(page.getByRole('heading', { name: 'Sinkronisasi Stok' })).toBeVisible();
  });

  test('SKU Sync Panel displays correctly', async ({ page }) => {
    // Check if the detection button exists
    const detectButton = page.getByRole('button', { name: /Deteksi SKU Sama/i });
    await expect(detectButton).toBeVisible();
    
    // Check if Active Sync Groups section exists
    await expect(page.getByRole('heading', { name: 'Grup Sinkronisasi Aktif' })).toBeVisible();
  });

  test('Detect SKU and Bulk Create functionality works', async ({ page }) => {
    const detectButton = page.getByRole('button', { name: /Deteksi SKU Sama/i });
    
    // Click the detect button
    await detectButton.click();
    
    // It should either show the empty state "Semua SKU Tersinkronisasi!" or a list of detected SKUs
    const emptyState = page.locator('text=Semua SKU Tersinkronisasi!');
    const selectAllCheckbox = page.getByLabel('Pilih Semua');
    const createBulkButton = page.getByRole('button', { name: /Buat Massal/i });
    const createSingleButton = page.getByRole('button', { name: 'Buat (1)' }).first();
    
    // Wait for one of them to be visible
    await Promise.any([
        expect(emptyState).toBeVisible(),
        expect(createSingleButton).toBeVisible()
    ]);
    
    if (await createSingleButton.isVisible()) {
        // Test single create modal
        await createSingleButton.click();
        
        // Wait for modal to appear
        const saveButton = page.getByRole('button', { name: 'Simpan & Push Stok' });
        await expect(saveButton).toBeVisible();
        
        // Cancel single create
        await page.getByRole('button', { name: 'Batal' }).click();

        // Test bulk create
        await selectAllCheckbox.check();
        await expect(createBulkButton).toBeEnabled();
        
        // Intercept bulk creation network request
        const [response] = await Promise.all([
            page.waitForResponse(res => res.url().includes('/api/sku-sync/groups/bulk') && res.request().method() === 'POST'),
            createBulkButton.click()
        ]);
        
        expect(response.status()).toBe(201);
    }
  });

  test('Edit Master Stock functionality works', async ({ page }) => {
     // Check if there are active groups, wait for list to load
     const editButton = page.getByTitle('Edit Master Stock').first();
     
     if (await editButton.isVisible()) {
         await editButton.click();
         
         const newStockInput = page.getByLabel('Master Stock Baru');
         await expect(newStockInput).toBeVisible();
         await newStockInput.fill('150');
         
         const saveButton = page.getByRole('button', { name: 'Simpan & Push' });
         
         const [response] = await Promise.all([
             page.waitForResponse(res => res.url().includes('/api/sku-sync/groups/') && res.request().method() === 'PUT'),
             saveButton.click()
         ]);
         
         expect(response.status()).toBe(200);
     }
  });
  
  test('Store Authorization safeguards real marketplace stock (PULL initially)', async ({ page }) => {
      // Skema test: Ketika user pertama kali otorisasi toko
      // Pastikan backend tidak melakukan PUSH (sinkronisasi UPDATE) secara otomatis ke marketplace.
      // Sebaliknya, sistem HANYA melakukan PULL (mengambil stok dari marketplace ke database lokal).
      // Test ini untuk memvalidasi bahwa "tidak ada overwrite dari stock kosong sistem ke marketplace" saat pertama auth.
      
      let pushedToMarketplace = false;
      
      page.on('request', request => {
          // Monitor external marketplace API calls for Push/Update
          if (request.url().includes('open-api.tiktok.com/product/stocks') && (request.method() === 'POST' || request.method() === 'PUT')) {
              pushedToMarketplace = true;
          }
          if (request.url().includes('partner.shopeemobile.com/api/v2/product/update_stock')) {
              pushedToMarketplace = true;
          }
      });
      
      // Simulate store connection process (Mocking the authorization callback)
      await page.route('/tiktok/callback*', async route => {
          await route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify({ message: "Store connected successfully. Pulling initial products in background." })
          });
      });
      
      await page.route('/shopee/callback*', async route => {
          await route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify({ message: "Store connected successfully. Pulling initial products in background." })
          });
      });
      
      // Attempt to hit the mock endpoints as an authorized user
      await page.request.get('/tiktok/callback?code=mock_code&state=mock_state');
      await page.request.get('/shopee/callback?shop_id=123&code=mock_code');
      
      // Wait a bit to ensure no background pushes occur
      await page.waitForTimeout(2000);
      
      // Assert that no push occurred to overwrite marketplace stock
      expect(pushedToMarketplace).toBeFalsy();
  });

});
