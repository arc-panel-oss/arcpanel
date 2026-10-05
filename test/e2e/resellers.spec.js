// Resellers (E11 spec §11): onboarding through the wizard, "log in as" a
// customer and back, and no access to other tenants.
import { expect, test } from '@playwright/test';
import {
	ADMIN,
	CUSTOMER,
	expectAccessible,
	expectToast,
	login,
	RESELLER,
	SITE,
	USER,
} from './helpers.js';

test('a reseller onboards a customer and logs in as it', async ({ page }) => {
	const name = `arce2en${Date.now() % 100000}`;
	await login(page, RESELLER);
	await expect(page.getByRole('heading', { name: `Welcome, ${RESELLER}` })).toBeVisible();

	await page.getByRole('link', { name: 'Add Customer' }).first().click();
	await expect(page).toHaveURL(/\/app\/users\/create$/);
	await page.fill('#f-username', name);
	await page.fill('#f-name', 'E2E Customer');
	await page.fill('#f-email', `${name}@example.invalid`);
	await page.fill('#f-password', process.env.E2E_PASSWORD);
	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.locator('#f-package')).toBeVisible();
	await expect(page.locator('[role=status].ap-error')).toBeHidden();
	await page.getByRole('button', { name: 'Continue' }).click();
	await page.getByRole('button', { name: 'Continue' }).click();
	await page.getByRole('button', { name: 'Continue' }).click();
	await expectAccessible(page);
	await page.getByRole('button', { name: 'Add Customer' }).click();

	await expect(page).toHaveURL(new RegExp(`/app/users/${name}$`));
	await expectToast(page, 'Customer has been created.');

	await page.getByRole('button', { name: 'Log in as' }).click();
	await expect(page.getByRole('heading', { name: `Welcome, ${name}` })).toBeVisible();
	await expect(page.getByRole('heading', { name: 'Your provider' })).toBeVisible();
	await expect(page.getByRole('link', { name: 'DNS' })).toHaveCount(0);

	await page.getByRole('button', { name: 'Return' }).click();
	await expect(page.getByRole('heading', { name: `Welcome, ${RESELLER}` })).toBeVisible();

	await page.goto(`/app/users/${name}`);
	await page.getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expect(page).toHaveURL(/\/app\/users$/);
});

test('a reseller cannot reach other tenants', async ({ page }) => {
	await login(page, RESELLER);
	for (const url of [
		`/app/users/${USER}`,
		`/app/users/${ADMIN}/edit`,
		`/app/web/domains/${USER}/${SITE}/edit`,
		`/app/web/domains?owner=${USER}`,
	]) {
		const response = await page.goto(url);
		expect(response?.status(), url).toBe(404);
	}
	await page.goto('/app/users');
	await expect(page.getByRole('link', { name: CUSTOMER, exact: true })).toBeVisible();
	await expect(page.getByText(USER, { exact: true })).toHaveCount(0);
});

test('an admin sees the owner tree', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/app/users#tree');
	const tree = page.locator('.ap-tree');
	await expect(tree.getByRole('link', { name: RESELLER })).toBeVisible();
	await expect(tree.locator('li.is-child').getByRole('link', { name: CUSTOMER })).toBeVisible();
});
