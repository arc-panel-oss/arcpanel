// Happy paths of the server settings (E7 batch 3 spec §8).
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const TABS = [
	'General',
	'Web Server',
	'DNS Server',
	'Mail Server',
	'Databases',
	'Backups',
	'SSL',
	'Security',
	'Plugins',
	'White Label',
];

const tabs = (page) => page.getByRole('navigation', { name: 'Server settings' });

test('admin: every tab opens and passes axe', async ({ page }) => {
	await login(page, ADMIN);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Configure' })
		.click();
	await expect(page).toHaveURL(/\/server\/settings\/general$/);
	for (const name of TABS) {
		const link = tabs(page).getByRole('link', { name, exact: true });
		if ((await link.count()) === 0) {
			continue;
		}
		await link.click();
		await expect(link).toHaveAttribute('aria-current', 'page');
		await expectAccessible(page);
	}
});

test('admin: change the time zone and back', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/settings/general');
	const zone = page.getByRole('combobox', { name: 'Time Zone' });
	const before = await zone.inputValue();
	const other = before === 'UTC' ? 'Europe/Berlin' : 'UTC';

	await zone.selectOption(other);
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByRole('combobox', { name: 'Time Zone' })).toHaveValue(other);

	await page.getByRole('combobox', { name: 'Time Zone' }).selectOption(before);
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByRole('combobox', { name: 'Time Zone' })).toHaveValue(before);
});

test('admin: toggle a user policy and back', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/settings/security');
	const policy = page.getByRole('combobox', { name: 'Allow users to delete log history' });
	const before = await policy.inputValue();
	const other = before === 'yes' ? 'no' : 'yes';

	await policy.selectOption(other);
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(
		page.getByRole('combobox', { name: 'Allow users to delete log history' }),
	).toHaveValue(other);

	await page
		.getByRole('combobox', { name: 'Allow users to delete log history' })
		.selectOption(before);
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
});

test('admin: the plugin list and the PHP versions show their state', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/settings/plugins');
	await expect(page.getByRole('row', { name: /Firewall/ })).toContainText(
		/Installed|Not installed/,
	);
	// the versions themselves are on the PHP page (E41)
	await page.goto('/server/settings/web');
	await expect(page.getByRole('combobox', { name: 'Default PHP Version' })).toBeVisible();
	await page.getByRole('link', { name: 'Open the PHP page' }).click();
	await expect(page).toHaveURL(/\/server\/php$/);
	await expect(page.locator('[data-php-version]').first()).toBeVisible();
});

test('user: no server settings, 403 on the page', async ({ page }) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Configure' }),
	).toHaveCount(0);
	const response = await page.goto('/server/settings/general');
	expect(response.status()).toBe(403);
});
