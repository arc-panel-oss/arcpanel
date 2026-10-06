// Happy paths of the updates page (E7 batch 3 spec §10). "Update all" runs the
// system task for real; on the test server the packages are up to date, so
// apt only checks them.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('admin: list the packages and toggle the automatic updates and back', async ({ page }) => {
	await login(page, ADMIN);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Updates' })
		.click();
	await expect(page).toHaveURL(/\/server\/updates$/);
	await expect(page.getByRole('row', { name: /arcpanel-php/ })).toBeVisible();
	await expectAccessible(page);

	const enable = page.getByRole('button', { name: 'Enable Automatic Updates' });
	const disable = page.getByRole('button', { name: 'Disable Automatic Updates' });
	const wasOn = (await disable.count()) > 0;
	await (wasOn ? disable : enable).click();
	await expectToast(page, wasOn ? 'Automatic updates are off.' : 'Automatic updates are on.');
	await (wasOn ? enable : disable).click();
	await expectToast(page, wasOn ? 'Automatic updates are on.' : 'Automatic updates are off.');
	await expect(wasOn ? disable : enable).toBeVisible();
});

test('admin: update every package in the background and see the task end', async ({ page }) => {
	test.setTimeout(240_000);
	await login(page, ADMIN);
	await page.goto('/server/updates');
	await page.getByRole('button', { name: 'Update all' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'The packages are being updated.');
	await expect(page.getByRole('button', { name: 'Update all' })).toBeDisabled();
	await expect(page.getByRole('log', { name: 'Task output' })).toContainText(
		'$ v-update-sys-hestia-all',
	);

	// The page reloads once the task has ended and shows it as the last task
	await expect(page.locator('.ap-task-last')).toContainText('Done', { timeout: 180_000 });
	await expect(page.getByRole('button', { name: 'Update all' })).toBeEnabled();
});

test('user: no updates in the menu, 403 on the page', async ({ page }) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Updates' }),
	).toHaveCount(0);
	const response = await page.goto('/server/updates');
	expect(response.status()).toBe(403);
});
