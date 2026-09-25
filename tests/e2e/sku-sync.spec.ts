import { expect, test, type Page } from '@playwright/test';

test.use({ storageState: 'playwright/.auth/userA.json' });

const masterRow = {
  id: 21,
  master_product_id: 7,
  name: 'Kemeja Oxford',
  image: null,
  brand: 'Finesheet',
  category: 'Kemeja',
  description: null,
  product_status: 'active',
  variant_name: 'Hitam / L',
  sku: 'SKU-OXFORD-L',
  barcode: '899000000001',
  hpp: 50000,
  stock: 12,
  is_active: true,
  stores_count: 2,
  listings_count: 2,
  same_sku_across_stores: true,
  stores: [
    { id: 1, name: 'Shopee Utama', platform: 'Shopee', status: 'synced', listings_count: 1 },
    { id: 2, name: 'TikTok Utama', platform: 'Tiktokshop', status: 'synced', listings_count: 1 },
  ],
};

async function mockMasterApis(page: Page, initialRows = [masterRow], initialDetections: any[] = []) {
  let rows = structuredClone(initialRows);
  let detections = structuredClone(initialDetections);

  await page.route('**/api/sku-sync/detect', route => route.fulfill({ json: detections }));
  await page.route('**/api/master-products**', async route => {
    const request = route.request();
    const url = new URL(request.url());

    if (request.method() === 'GET' && url.pathname === '/api/master-products') {
      return route.fulfill({
        json: {
          data: rows,
          meta: { current_page: 1, last_page: 1, per_page: 20, total: rows.length, from: rows.length ? 1 : null, to: rows.length || null },
        },
      });
    }

    if (request.method() === 'POST' && url.pathname === '/api/master-products') {
      const payload = request.postDataJSON();
      rows = [{
        ...masterRow,
        id: 22,
        master_product_id: 8,
        name: payload.name,
        sku: payload.variants[0].sku,
        stock: payload.variants[0].stock,
        hpp: payload.variants[0].hpp,
        stores_count: 0,
        listings_count: 0,
        same_sku_across_stores: false,
        stores: [],
      }];

      return route.fulfill({ status: 201, json: { id: 8, name: payload.name, variants: rows } });
    }

    if (request.method() === 'POST' && url.pathname === '/api/master-products/bulk') {
      const payload = request.postDataJSON();
      const selected = detections.filter(item => payload.skus.includes(item.sku));
      rows = [...rows, ...selected.map((item, index) => ({
        ...masterRow,
        id: 30 + index,
        master_product_id: 20 + index,
        name: item.items[0].product_name,
        sku: item.sku,
        variant_name: item.items[0].variant_name,
        stock: item.items[0].stock,
        hpp: 0,
        stores_count: 0,
        listings_count: 0,
        same_sku_across_stores: false,
        stores: [],
      }))];
      detections = detections.filter(item => !payload.skus.includes(item.sku));

      return route.fulfill({
        status: 201,
        json: {
          message: `${selected.length} SKU master berhasil ditambahkan.`,
          requested_count: payload.skus.length,
          created_count: selected.length,
          skipped_count: 0,
          sync_queued: true,
        },
      });
    }

    if (request.method() === 'PUT' && url.pathname.endsWith('/variants/21')) {
      const payload = request.postDataJSON();
      rows = rows.map(row => row.id === 21 ? { ...row, ...payload, hpp: Number(payload.hpp), stock: Number(payload.stock) } : row);

      return route.fulfill({ json: rows.find(row => row.id === 21) });
    }

    if (request.method() === 'POST' && url.pathname.endsWith('/variants/21/push')) {
      return route.fulfill({ status: 202, json: { message: 'Sinkronisasi stok dijadwalkan.', queued_count: 2 } });
    }

    return route.fallback();
  });
}

test.describe('Master product and stock synchronization', () => {
  test('shows the merged per-SKU list and marketplace status', async ({ page }) => {
    await mockMasterApis(page);
    await page.goto('/#/products');

    await expect(page.getByRole('heading', { name: 'Product Management' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Master Produk', exact: true })).toBeVisible();
    await expect(page.getByText('SKU-OXFORD-L').first()).toBeVisible();
    await expect(page.getByText('2 toko').first()).toBeVisible();
    await expect(page.getByText('Sama di 2 toko').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Sinkronisasi Stok' })).toHaveCount(0);
  });

  test('creates a user-owned master SKU without pushing stock automatically', async ({ page }) => {
    await mockMasterApis(page, []);
    await page.goto('/#/products');
    await page.getByRole('button', { name: 'Tambah Master Produk' }).click();

    await page.getByLabel('Nama master produk').fill('Blazer Formal');
    await page.getByLabel('SKU', { exact: true }).fill('SKU-BLAZER-M');
    await page.getByLabel('Stok tersedia').fill('25');
    await page.getByLabel('HPP', { exact: true }).fill('75000');

    const requestPromise = page.waitForRequest(request => request.url().endsWith('/api/master-products') && request.method() === 'POST');
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
    const request = await requestPromise;

    expect(request.postDataJSON().variants[0]).toMatchObject({ sku: 'SKU-BLAZER-M', stock: 25, hpp: 75000 });
    await expect(page.getByText('SKU-BLAZER-M').first()).toBeVisible();
  });

  test('edits stock and can explicitly queue marketplace synchronization', async ({ page }) => {
    await mockMasterApis(page);
    await page.goto('/#/products');
    await page.getByRole('button', { name: 'Pengaturan SKU master' }).first().click();
    await page.getByRole('button', { name: 'Edit data' }).click();

    await page.getByLabel('Stok tersedia').fill('30');
    await page.getByLabel('HPP', { exact: true }).fill('55000');
    const updatePromise = page.waitForRequest(request => request.url().endsWith('/variants/21') && request.method() === 'PUT');
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
    expect((await updatePromise).postDataJSON()).toMatchObject({ stock: 30, hpp: 55000 });

    await page.getByRole('button', { name: 'Pengaturan SKU master' }).first().click();
    const pushPromise = page.waitForRequest(request => request.url().endsWith('/variants/21/push') && request.method() === 'POST');
    await page.getByRole('button', { name: 'Sinkronkan stok' }).click();
    await pushPromise;
  });

  test('adds all or selected detected SKUs in bulk', async ({ page }) => {
    const detections = [
      {
        sku: 'SKU-MASSAL-A',
        stores_count: 2,
        items: [{ product_name: 'Kemeja Massal', variant_name: 'Hitam / L', stock: 18 }],
      },
      {
        sku: 'SKU-MASSAL-B',
        stores_count: 3,
        items: [{ product_name: 'Celana Massal', variant_name: 'Navy / M', stock: 9 }],
      },
    ];
    await mockMasterApis(page, [], detections);
    await page.goto('/#/products');

    await page.getByRole('button', { name: 'Tambah Massal' }).click();
    await expect(page.getByRole('heading', { name: 'Tambah SKU Master Secara Massal' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Tambahkan 2 SKU' })).toBeEnabled();

    await page.getByLabel('Pilih SKU SKU-MASSAL-B').uncheck();
    const requestPromise = page.waitForRequest(request => request.url().endsWith('/api/master-products/bulk') && request.method() === 'POST');
    await page.getByRole('button', { name: 'Tambahkan 1 SKU' }).click();
    const request = await requestPromise;

    expect(request.postDataJSON()).toEqual({ skus: ['SKU-MASSAL-A'] });
    await expect(page.getByText('SKU-MASSAL-A').first()).toBeVisible();
    await expect(page.getByText('1 SKU master berhasil ditambahkan.')).toBeVisible();
  });
});
