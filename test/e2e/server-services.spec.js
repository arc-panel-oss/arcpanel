// Happy paths of the services page (E7 batch 3 spec §7).
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('admin: list the services, read the reports and restart cron', async ({ page }) => {
	await login(page, ADMIN);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Services' })
		.click();
	await expect(page).toHaveURL(/\/app\/server\/services$/);
	await expect(page.getByRole('row', { name: /\bcron\b/ })).toBeVisible();
	await expectAccessible(page);

	// Status reports
	await page.getByRole('navigation', { name: 'Status reports' }).getByRole('link', { name: 'CPU' }).click();
	await expect(page).toHaveURL(/\/app\/server\/services\/status\/cpu$/);
	await expect(page.locator('.ap-code')).toContainText('load average');
	await expectAccessible(page);
	await page.getByRole('navigation', { name: 'Status reports' }).getByRole('link', { name: 'Disk' }).click();
	await expect(page.locator('.ap-code')).toContainText('Filesystem');

	// Restart cron from its row menu
	await page.goto('/app/server/services');
	const row = page.getByRole('row', { name: /\bcron\b/ });
	await row.getByLabel('Actions for cron').first().click();
	await row.getByRole('button', { name: 'Restart' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'Service cron has been restarted.');
	await expect(page.getByRole('row', { name: /\bcron\b/ })).toContainText('running');

	// The restart dialog asks for the host name
	await page.getByRole('button', { name: 'Restart server' }).click();
	await expect(page.getByRole('dialog', { name: 'Restart server' })).toBeVisible();
	await expect(page.getByRole('dialog', { name: 'Restart server' }).getByRole('textbox')).toBeVisible();
	await page.getByRole('dialog', { name: 'Restart server' }).getByRole('button', { name: 'Cancel' }).click();
});

test('user: no services in the menu, 403 on the page', async ({ page }) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Services' }),
	).toHaveCount(0);
	const response = await page.goto('/app/server/services');
	expect(response.status()).toBe(403);
});
