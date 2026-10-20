import { test, expect } from '@playwright/test';

test.describe('Public review pages', () => {
  test('landing page exposes operator, contact, and public documents', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { name: /Finesheet untuk operasi e-commerce/i })).toBeVisible();
    await expect(page.getByText('Yosep Adriana Fauzi R', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'developer@finesheet.id' }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Privacy Policy' }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Terms of Service' }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Integrasi TikTok Ads' }).first()).toBeVisible();
  });

  test('direct public URLs remain accessible without authentication', async ({ page }) => {
    const pages = [
      ['/privacy-policy', 'Privacy Policy'],
      ['/terms-of-service', 'Terms of Service'],
      ['/tiktok-ads-integration', 'Integrasi TikTok Ads'],
    ];

    for (const [path, heading] of pages) {
      await page.goto(path);
      await expect(page).toHaveURL(new RegExp(`${path}$`));
      await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
      await expect(page).not.toHaveURL(/#\/login/);
    }
  });

  test('public pages fit a mobile viewport without horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tiktok-ads-integration');

    await expect(page.getByRole('heading', { name: 'Integrasi TikTok Ads', exact: true })).toBeVisible();
    const hasHorizontalOverflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );

    expect(hasHorizontalOverflow).toBe(false);
  });
});
