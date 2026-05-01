import { expect, test } from '../playwright/fixtures';
import { PLAYWRIGHT_BASE_URL } from '../playwright/config';

test.describe('Timer focus mode', () => {
    test.use({ viewport: { width: 1280, height: 720 } });

    test('sidebar opens focus view with notes and events panels', async ({ page }) => {
        await page.goto(`${PLAYWRIGHT_BASE_URL}/dashboard`);
        await page.waitForLoadState('networkidle');

        const focusTrigger = page.getByTestId('timer_focus_enter_sidebar').first();
        await expect(focusTrigger).toBeVisible({ timeout: 20000 });
        await focusTrigger.click();

        await expect(page.getByTestId('timer_focus_view')).toBeVisible();
        await expect(page.getByTestId('timer_focus_notes')).toBeVisible();
        await expect(page.getByTestId('timer_focus_events')).toBeVisible();
    });
});
