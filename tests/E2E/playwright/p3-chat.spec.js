// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('P3 — Chat admin page', () => {
    test.beforeEach(async ({ page }) => {
        // Log in as nj_agent (administrator, credentials from global-setup.js)
        await page.goto('/wp-login.php');
        await page.waitForSelector('#user_login', { state: 'visible' });
        await page.fill('#user_login', 'nj_agent');
        await page.fill('#user_pass', 'C8IcqAWJu8F3dOw6E4ndWhIe');
        await page.click('#wp-submit');
        await page.waitForURL('**/wp-admin/**');
    });

    test('Chat page loads with React mount point', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind-chat');
        await expect(page.locator('#wp-ai-mind-chat')).toBeVisible();
    });

    test('Chat shell renders with sidebar and composer', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind-chat');
        // Wait for React to hydrate
        await page.waitForSelector('.wpaim-shell', { timeout: 10000 });
        await expect(page.locator('.wpaim-sidebar')).toBeVisible();
        await expect(page.locator('.wpaim-composer')).toBeVisible();
    });

    test('Settings page loads with React mount point', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind-settings');
        await expect(page.locator('#wp-ai-mind-settings')).toBeVisible();
    });

    test('Settings tabs render after hydration', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind-settings');
        await page.waitForSelector('.wpaim-settings-shell', { timeout: 10000 });
        await expect(page.locator('.wpaim-settings-tabpanel')).toBeVisible();
    });

    test('REST endpoint /wp-ai-mind/v1/providers responds', async ({ page }) => {
        // Hit the REST API directly via the browser (nonce not required for this check)
        const response = await page.request.get( '/wp-json/wp-ai-mind/v1/providers' );
        // 200, 401, or 403 — any of these means the route is registered
        expect([200, 401, 403]).toContain(response.status());
    });
});
