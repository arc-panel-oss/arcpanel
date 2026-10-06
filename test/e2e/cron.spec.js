// Happy paths of the Cron module (E7 batch 1 spec §7).
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const KNOWN = 'echo e2e-cron';
const ADDED = 'echo e2e-added';
const row = (page, command) => page.getByRole('row', { name: new RegExp(command) });

test('user: add a cron job with the generator, edit it, bulk suspend, unsuspend, delete', async ({
	page,
}) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Cron Jobs' })
		.click();
	await expect(page).toHaveURL(/\/cron$/);
	await expect(row(page, KNOWN)).toBeVisible();
	await expectAccessible(page);

	// Add: "Every 3 days" of the Daily tab, at the legacy default 12:00
	await page.getByRole('link', { name: 'Add Cron Job' }).first().click();
	await expect(page.getByText('System time')).toBeVisible();
	await expectAccessible(page);
	await page.getByRole('textbox', { name: 'Command', exact: true }).fill(ADDED);
	await page.getByRole('button', { name: 'Daily', exact: true }).click();
	await page.locator('#cron-gen-job-daily-day').selectOption({ label: 'Every 3 days' });
	await page.getByRole('button', { name: 'Generate' }).click();
	await expect(page.getByRole('textbox', { name: 'Minute', exact: true })).toHaveValue('0');
	await expect(page.getByRole('textbox', { name: 'Hour', exact: true })).toHaveValue('12');
	await expect(page.getByRole('textbox', { name: 'Day', exact: true })).toHaveValue('*/3');
	await expect(page.locator('.ap-cron-preview code')).toHaveText('0 12 */3 * *');
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(/\/cron$/);
	await expectToast(page, 'Cron job has been created successfully.');
	await expect(row(page, ADDED)).toContainText('*/3');

	// Edit: another hour
	await page.getByRole('link', { name: ADDED }).click();
	await expect(page).toHaveURL(new RegExp(`/cron/${USER}/\\d+/edit`));
	await page.getByRole('textbox', { name: 'Hour', exact: true }).fill('6');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByRole('textbox', { name: 'Hour', exact: true })).toHaveValue('6');

	// Bulk suspend both
	await page.goto('/cron');
	await page.getByRole('checkbox', { name: `Select ${KNOWN}` }).check();
	await page.getByRole('checkbox', { name: `Select ${ADDED}` }).check();
	await page.locator('.ap-bulk-bar').getByRole('button', { name: 'Suspend', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Suspend', exact: true }).click();
	await expectToast(page, '2 done, 0 failed');
	await expect(row(page, KNOWN)).toContainText('Suspended');
	await expect(row(page, ADDED)).toContainText('Suspended');

	// Unsuspend and delete from the row menu
	await row(page, KNOWN).getByLabel(`Actions for ${KNOWN}`).first().click();
	await row(page, KNOWN).getByRole('button', { name: 'Unsuspend', exact: true }).click();
	await expect(page.getByRole('alertdialog')).toContainText(
		'Are you sure you want to unsuspend this cron job?',
	);
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'Cron job has been unsuspended.');
	await expect(row(page, KNOWN)).toContainText('Active');

	await row(page, ADDED).getByLabel(`Actions for ${ADDED}`).first().click();
	await row(page, ADDED).getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'Cron job has been deleted.');
	await expect(row(page, ADDED)).toHaveCount(0);
});

test('user: enable and disable cron e-mail reports', async ({ page }) => {
	await login(page, USER);
	await page.goto('/cron');
	await page.getByRole('button', { name: 'Enable Notifications' }).click();
	await expectToast(page, 'Cron job email reporting has been successfully enabled.');
	await page.getByRole('button', { name: 'Disable Notifications' }).click();
	await expectToast(page, 'Cron job email reporting has been successfully disabled.');
	await expect(page.getByRole('button', { name: 'Enable Notifications' })).toBeVisible();
});

test('user: another user’s cron job is not found', async ({ page }) => {
	await login(page, USER);
	const response = await page.goto(`/cron/${ADMIN}/1/edit`);
	expect(response.status()).toBe(404);
});

test('admin: every owner in the list, filter by owner', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/cron');
	await expect(row(page, KNOWN)).toContainText(USER);
	await page.getByLabel('Owner').selectOption(USER);
	await expect(page).toHaveURL(new RegExp(`owner=${USER}`));
	await expect(row(page, KNOWN)).toBeVisible();
	await expect(page.getByRole('button', { name: 'Enable Notifications' })).toBeVisible();
	await expectAccessible(page);
});

test('legacy cron list redirects to the panel app', async ({ page }) => {
	await login(page, USER);
	await page.goto('/list/cron/');
	await expect(page).toHaveURL(/\/cron$/);
});
