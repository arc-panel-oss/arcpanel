// Happy paths of the config editors (E7 batch 3 spec §9).
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('admin: change an nginx basic option without restart and restore it', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/app/server/services');
	await page.getByRole('link', { name: 'nginx', exact: true }).click();
	await expect(page).toHaveURL(/\/app\/server\/services\/nginx\/config$/);
	const option = page.getByLabel('client_max_body_size');
	await expect(option).toBeVisible();
	await expect(page.locator('.cm-content')).toBeVisible();
	await expectAccessible(page);

	const before = await option.inputValue();
	const other = before === '512m' ? '1024m' : '512m';
	await option.fill(other);
	await option.press('Tab');
	await expect(page.locator('.cm-content')).toContainText(`client_max_body_size ${other};`);

	await page.getByRole('checkbox', { name: 'Restart the service after saving' }).uncheck();
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');

	await page.reload();
	await expect(page.getByLabel('client_max_body_size')).toHaveValue(other);
	await page.getByLabel('client_max_body_size').fill(before);
	await page.getByLabel('client_max_body_size').press('Tab');
	await page.getByRole('checkbox', { name: 'Restart the service after saving' }).uncheck();
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await page.reload();
	await expect(page.getByLabel('client_max_body_size')).toHaveValue(before);
});

test('admin: the system crontab is read-only', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/app/server/services/cron/config');
	await expect(page.getByText('/etc/crontab')).toBeVisible();
	await expect(page.getByText('Read-only')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Save' })).toHaveCount(0);
});
