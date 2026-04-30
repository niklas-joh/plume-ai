// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('P2 — Dashboard landing page', () => {
    test.beforeEach(async ({ page }) => {
        // Log in as nj_agent (administrator, credentials from global-setup.js)
        await page.goto('/wp-login.php');
        await page.fill('#user_login', 'nj_agent');
        await page.fill('#user_pass', 'C8IcqAWJu8F3dOw6E4ndWhIe');
        await page.click('#wp-submit');
        await page.waitForURL('**/wp-admin/**');
    });

    test('dashboard page renders with title and Start section', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind');
        // Wait for React to hydrate
        await page.waitForSelector('.wpaim-dash-title', { timeout: 10000 });
        await expect(page.locator('.wpaim-dash-title')).toContainText('WP AI Mind');
        await expect(page.locator('.wpaim-dash-tiles')).toBeVisible();
        await expect(page.locator('.wpaim-dash-resources')).toBeVisible();
        await expect(page.locator('.wpaim-dash-footer')).toBeVisible();
    });

    test('usage widget is visible for administrator', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind');
        await page.waitForSelector('.wpaim-dash-title', { timeout: 10000 });
        // Widget is rendered only when PHP localises usage data for manage_options users.
        await expect(page.locator('.wpaim-usage-widget')).toBeVisible();
    });

    test('Chat sub-menu navigates to Chat page', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind');
        await page.click('text=Chat');
        await expect(page).toHaveURL(/page=wp-ai-mind-chat/);
        await expect(page.locator('#wp-ai-mind-chat')).toBeVisible();
    });

    test('Run setup again link navigates and shows onboarding modal', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind');
        await page.waitForSelector('.wpaim-dash-title', { timeout: 10000 });

        // Click Run setup again in footer.
        const runSetupLink = page.locator('.wpaim-dash-footer__link', { hasText: 'Run setup again' });
        await runSetupLink.click();

        // Should navigate back to dashboard or show modal.
        await expect(page).toHaveURL(/page=wp-ai-mind/);
        // Onboarding modal should be visible.
        await expect(page.locator('.wpaim-ob-overlay')).toBeVisible();
    });

    test('onboarding modal — Plugin API path completes in one step', async ({ page }) => {
        // Reset onboarding seen flag via page evaluation.
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind');
        await page.waitForSelector('.wpaim-dash-title', { timeout: 10000 });

        await page.evaluate(() => {
            window.wpAiMindDashboard.onboardingSeen = false;
        });
        await page.reload();
        await page.waitForSelector('.wpaim-ob-overlay', { timeout: 10000 });

        await expect(page.locator('.wpaim-ob-overlay')).toBeVisible();

        // Plugin API is selected by default — click Get started.
        await page.click('button:has-text("Get started")');

        // Done screen should appear.
        await expect(page.locator('text=Setup complete')).toBeVisible();
    });

    test('all resource links have correct attributes', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=wp-ai-mind');
        await page.waitForSelector('.wpaim-dash-title', { timeout: 10000 });

        const links = page.locator('.wpaim-dash-resource');
        const count = await links.count();
        expect( count ).toBe( 4 );

        for (let i = 0; i < count; i++) {
            await expect(links.nth(i)).toHaveAttribute('target', '_blank');
            await expect(links.nth(i)).toHaveAttribute('rel', 'nofollow noreferrer');
        }
    });
});
