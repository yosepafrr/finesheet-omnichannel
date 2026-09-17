import { test, expect } from '@playwright/test';
import * as dotenv from 'dotenv';
dotenv.config({ path: '.env.test' });

test.describe('Authentication & Authorization', () => {

  test('User A login and see own data', async ({ browser }) => {
    // Create isolated context for User A using their saved state
    const context = await browser.newContext({ storageState: 'playwright/.auth/userA.json' });
    const page = await context.newPage();
    
    // Visit orders
    await page.goto('/#/orders');
    await expect(page.getByRole('button', { name: 'Semua', exact: true })).toBeVisible();
    
    await page.getByRole('button', { name: 'Semua', exact: true }).click();
    await page.waitForTimeout(1000); // Wait for API and render to settle
    
    // Ensure order A is visible, and order B is not
    await expect(page.locator('text=ORD-A-1').first()).toBeVisible({ timeout: 10000 });
    await expect(page.locator('text=ORD-B-1')).toHaveCount(0);

    await context.close();
  });

  test('User B login and see own data', async ({ browser }) => {
    // Create isolated context for User B using their saved state
    const context = await browser.newContext({ storageState: 'playwright/.auth/userB.json' });
    const page = await context.newPage();
    
    // Visit orders
    await page.goto('/#/orders');
    await expect(page.getByRole('button', { name: 'Semua', exact: true })).toBeVisible();
    
    await page.getByRole('button', { name: 'Semua', exact: true }).click();
    await page.waitForTimeout(1000); // Wait for API and render to settle
    
    // Ensure order B is visible, and order A is not
    await expect(page.locator('text=ORD-B-1').first()).toBeVisible({ timeout: 10000 });
    await expect(page.locator('text=ORD-A-1')).toHaveCount(0);

    await context.close();
  });

  test('Direct API access restriction across users', async ({ request, browser }) => {
    // We will test if User A can access User B's order via API
    // First get User B's order ID.
    // Note: Since we don't know the exact ID, we can just assume User B's order is in the DB.
    // To do a proper test, we should fetch User B's orders as User B, then try to fetch it as User A.
    
    // Let's get User B's orders
    const contextB = await browser.newContext({ storageState: 'playwright/.auth/userB.json' });
    const responseB = await contextB.request.get('/api/orders');
    const dataB = await responseB.json();
    
    const ordersArray = dataB.orders || dataB.data?.data || dataB.data || dataB;
    const userBOrder = ordersArray.find((o: any) => o.order_sn === 'ORD-B-1');
    expect(userBOrder).toBeDefined();

    const orderIdB = userBOrder.id;

    // Now request it as User A
    const contextA = await browser.newContext({ storageState: 'playwright/.auth/userA.json' });
    const responseA = await contextA.request.get(`/api/orders/${orderIdB}`);
    
    // The application should return 403 or 404
    expect([403, 404]).toContain(responseA.status());
  });
});
