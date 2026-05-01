import { expect, test } from '../playwright/fixtures';
import { PLAYWRIGHT_BASE_URL } from '../playwright/config';

test.describe('Events page', () => {
    test('loads events list view for users with calendar event access', async ({ page }) => {
        await page.goto(`${PLAYWRIGHT_BASE_URL}/events`);
        await expect(page.getByTestId('events_view')).toBeVisible({ timeout: 15000 });
        await expect(page.getByRole('heading', { level: 3, name: /Events/ })).toBeVisible();
    });
});
