// Happy paths of the Databases module (E7 batch 1 spec §6).
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const PREFIX = `${USER}_`;

test('user: add a database, change its password, bulk suspend, delete', async ({ page }) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Databases' })
		.click();
	await expect(page).toHaveURL(/\/app\/db$/);
	await expect(page.getByRole('row', { name: new RegExp(`${PREFIX}e2edb`) })).toBeVisible();
	await expectAccessible(page);

	// Add
	await page.getByRole('link', { name: 'Add Database' }).first().click();
	await expectAccessible(page);
	await page.getByRole('textbox', { name: 'Database', exact: true }).fill('e2eshop');
	await page.getByRole('textbox', { name: 'Username', exact: true }).fill('e2eshop');
	await page.locator('#f-password').fill('E2e-Shop-Pass1');
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(new RegExp(`/app/db/${USER}/${PREFIX}e2eshop/edit`));
	await expectToast(page, `Database ${PREFIX}e2eshop has been created successfully.`);

	// Edit: new password
	await page.locator('#f-password').fill('E2e-Shop-Pass2');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');

	// Bulk suspend both
	await page.goto('/app/db');
	await page.getByRole('checkbox', { name: `Select ${PREFIX}e2edb` }).check();
	await page.getByRole('checkbox', { name: `Select ${PREFIX}e2eshop` }).check();
	await page.locator('.ap-bulk-bar').getByRole('button', { name: 'Suspend', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Suspend', exact: true }).click();
	await expectToast(page, '2 done, 0 failed');
	await expect(page.getByRole('row', { name: new RegExp(`${PREFIX}e2eshop`) })).toContainText(
		'Suspended',
	);

	// Unsuspend and delete from the row menu
	const row = page.getByRole('row', { name: new RegExp(`${PREFIX}e2edb`) });
	await row.getByLabel(`Actions for ${PREFIX}e2edb`).first().click();
	await row.getByRole('button', { name: 'Unsuspend', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `Database ${PREFIX}e2edb has been unsuspended.`);

	const shop = page.getByRole('row', { name: new RegExp(`${PREFIX}e2eshop`) });
	await shop.getByLabel(`Actions for ${PREFIX}e2eshop`).first().click();
	await shop.getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `Database ${PREFIX}e2eshop has been deleted.`);
	await expect(page.getByRole('row', { name: new RegExp(`${PREFIX}e2eshop`) })).toHaveCount(0);
});

test('user: another user’s database is not found', async ({ page }) => {
	await login(page, USER);
	const response = await page.goto(`/app/db/${ADMIN}/${ADMIN}_anything/edit`);
	expect(response.status()).toBe(404);
});

test('admin: every owner in the list, filter by owner', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/app/db');
	await expect(page.getByRole('row', { name: new RegExp(`${PREFIX}e2edb`) })).toContainText(USER);
	await page.getByLabel('Owner').selectOption(USER);
	await expect(page).toHaveURL(new RegExp(`owner=${USER}`));
	await expect(page.getByRole('row', { name: new RegExp(`${PREFIX}e2edb`) })).toBeVisible();
	await expectAccessible(page);
});

test('legacy database list redirects to the panel app', async ({ page }) => {
	await login(page, USER);
	await page.goto('/list/db/');
	await expect(page).toHaveURL(/\/app\/db$/);
});
