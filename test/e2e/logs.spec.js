// Happy paths of the Logs pages and the notification tray (E7 batch 2 spec §9).
import { expect, test } from '@playwright/test';
import { expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('user: read the action log and the login history, delete the log', async ({ page }) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Logs' })
		.click();
	await expect(page).toHaveURL(new RegExp(`/app/logs/${USER}$`));
	// prepare.sh added a web domain for the user
	await expect(page.locator('#action-log')).toContainText('e2e-site.example');
	await expectAccessible(page);

	// The login of this test is the newest line of the history
	await page.getByRole('link', { name: 'Login History' }).click();
	await expect(page).toHaveURL(new RegExp(`/app/logs/${USER}/auth$`));
	await expect(page.locator('#login-history tbody tr').first()).toContainText('Success');
	await expectAccessible(page);

	await page.getByRole('link', { name: 'Logs', exact: true }).last().click();
	await page.getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'Logs have been deleted.');
	await expect(page.locator('main')).not.toContainText('e2e-site.example');
});

test('user: the tray offers "Mark all read" when something is unread', async ({ page }) => {
	await login(page, USER);
	await page.getByRole('button', { name: /^Notifications/ }).click();
	const tray = page.locator('#ap-tray-panel');
	await expect(tray).toBeVisible();
	const unread = await tray.locator('.ap-notice.is-unread').count();
	if (unread > 0) {
		await tray.getByRole('button', { name: 'Mark all read' }).click();
		await expect(tray.locator('.ap-notice.is-unread')).toHaveCount(0);
	}
	await expect(tray.getByRole('button', { name: 'Mark all read' })).toBeHidden();
});
