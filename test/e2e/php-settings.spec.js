// Per-domain PHP settings (E19 spec 2026-10-23-001 §9): the user sets PHP
// values of a web domain within the package maximums on the PHP tab, sees a
// value above the maximum refused, the values applied, and resets them.
import { expect, test } from '@playwright/test';
import { expectAccessible, expectToast, login, SITE, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

/** The PHP tab of the user's site, with the settings form. */
async function openSettings(page) {
	await page.goto(`/web/domains/${USER}/${SITE}/edit`);
	await page.getByRole('tab', { name: 'PHP' }).click();
	return page.locator('form[action$="/php/settings"]');
}

test('user: a value above the package maximum is refused', async ({ page }) => {
	await login(page, USER);
	const form = await openSettings(page);
	await expect(page.getByRole('tabpanel', { name: 'PHP' })).toContainText('PHP settings');
	await expectAccessible(page);
	await form.locator('input[name="settings[memory_limit]"]').fill('4G');
	await form.getByRole('button', { name: 'Save' }).click();
	await page.getByRole('tab', { name: 'PHP' }).click();
	await expect(page.getByRole('tabpanel', { name: 'PHP' })).toContainText(
		'At most 512M on the package of this domain.',
	);
});

test('user: set memory and upload sizes, see them applied, reset them', async ({ page }) => {
	await login(page, USER);
	let form = await openSettings(page);
	await form.locator('input[name="settings[memory_limit]"]').fill('256M');
	await form.locator('input[name="settings[post_max_size]"]').fill('64M');
	await form.locator('input[name="settings[upload_max_filesize]"]').fill('48M');
	await form.locator('select[name="settings[display_errors]"]').selectOption('off');
	await form.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');

	form = await openSettings(page);
	await expect(form.locator('input[name="settings[memory_limit]"]')).toHaveValue('256M');
	await expect(form.locator('input[name="settings[upload_max_filesize]"]')).toHaveValue('48M');
	await expect(page.getByRole('tabpanel', { name: 'PHP' })).toContainText('This domain');
	await expectAccessible(page);

	await page
		.getByRole('tabpanel', { name: 'PHP' })
		.getByRole('button', { name: 'Reset all' })
		.click();
	await expectToast(page, 'Changes have been saved.');
	form = await openSettings(page);
	await expect(form.locator('input[name="settings[memory_limit]"]')).toHaveValue('');
	await expect(form.locator('input[name="settings[upload_max_filesize]"]')).toHaveValue('');
});
