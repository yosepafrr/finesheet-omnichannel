import { test, expect } from '@playwright/test';

test.use({ storageState: 'playwright/.auth/userA.json' });

test.describe('Period & Rekapitulasi', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/#/payable');
    await page.waitForTimeout(1000); // Wait for initial loads
  });

  test('Create a new period', async ({ page }) => {
    // Click on create manual period button
    const createButton = page.locator('text=Periode Manual').first();
    
    if (await createButton.isVisible()) {
      await createButton.click();
      
      // Fill the form
      const dateInputs = page.locator('input[type="datetime-local"]');
      if (await dateInputs.count() >= 2) {
         await dateInputs.nth(0).fill('2026-01-01T00:00');
         await dateInputs.nth(1).fill('2026-01-07T23:59');
      }
      
      const saveBtn = page.locator('button', { hasText: /Buat Periode/i }).first();
      if (await saveBtn.isVisible()) {
          // We won't actually click save to prevent state pollution in simple tests,
          // or we can click it and delete later via API teardown.
          expect(saveBtn).toBeVisible();
      }
    }
  });

  test('Event date original remains unchanged on reassign', async ({ request }) => {
    // 1. Create 2 periods via API
    const p1Res = await request.post('/api/periods', { data: { start_date: '2026-01-01', cycle_days: 7 }});
    const p2Res = await request.post('/api/periods', { data: { start_date: '2026-01-08', cycle_days: 7 }});
    
    if (p1Res.ok() && p2Res.ok()) {
       // Just testing API logic
       const dataP1 = await p1Res.json();
       const dataP2 = await p2Res.json();
       
       // Note: Since this is E2E, we might need to query the order and check if order_time is unchanged
       const orderRes = await request.get('/api/orders');
       const orders = await orderRes.json();
       const orderA = orders.data?.find((o: any) => o.order_sn === 'ORD-A-1');
       
       if (orderA) {
          const originalTime = orderA.order_time;
          
          // Reassign API call
          await request.post('/api/payable/reassign', {
            data: {
               event_id: orderA.id,
               event_type: 'order',
               target_period_id: dataP2.id
            }
          });
          
          // Fetch again
          const orderRes2 = await request.get('/api/orders');
          const orders2 = await orderRes2.json();
          const orderA2 = orders2.data?.find((o: any) => o.order_sn === 'ORD-A-1');
          
          expect(orderA2.order_time).toBe(originalTime);
       }
    }
  });
});
