// Happy paths of the dashboard and the Web Domains module for both roles.
import { expect, test } from '@playwright/test';
import { ADMIN, expectToast, login, SITE, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('user: dashboard, add a domain, edit General and SSL, bulk suspend, delete', async ({
	page,
}) => {
	await login(page, USER);
	await expect(page.getByRole('heading', { level: 1 })).toHaveText(`Welcome, ${USER}`);
	await expect(page.getByRole('meter', { name: 'Web Domains' })).toBeVisible();

	// Add
	await page.getByRole('link', { name: 'Add Web Domain' }).first().click();
	await page.getByRole('textbox', { name: 'Domain', exact: true }).fill('e2e-new.example');
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(new RegExp(`/web/domains/${USER}/e2e-new.example/edit`));
	await expectToast(page, 'e2e-new.example');

	// General: one more alias
	await page.getByRole('tab', { name: 'General' }).click();
	const aliases = page.locator('#general').getByLabel('Aliases');
	await aliases.fill(`www.e2e-new.example\nblog.e2e-new.example`);
	await page.locator('#general').getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(/#general$/);
	await expectToast(page, 'Changes have been saved.');
	await expect(page.locator('#general').getByLabel('Aliases')).toHaveValue(
		/blog\.e2e-new\.example/,
	);

	// SSL with an uploaded certificate
	await page.getByRole('tab', { name: 'SSL' }).click();
	await expect(page.locator('#ssl')).toBeVisible();
	await expect(page.locator('#general')).toBeHidden();
	await page.locator('#ssl').getByRole('switch', { name: 'Enable SSL for this domain' }).check();
	await page
		.locator('#ssl')
		.getByLabel('SSL Certificate', { exact: true })
		.fill(process.env.E2E_CERTIFICATE);
	await page.locator('#ssl').getByLabel('SSL Private Key').fill(process.env.E2E_KEY);
	await page.locator('#ssl').getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(/#ssl$/);
	await expectToast(page, 'Changes have been saved.');
	await expect(page.locator('.ap-entity')).toContainText('SSL');
	expect(await page.content()).not.toContain('PRIVATE KEY');

	// Bulk suspend from the list
	await page.goto('/web/domains');
	await page.getByRole('checkbox', { name: 'Select e2e-bulk.example' }).check();
	await page.getByRole('checkbox', { name: 'Select e2e-new.example' }).check();
	await expect(page.locator('.ap-bulk-bar')).toContainText('2 selected');
	await page.locator('.ap-bulk-bar').getByRole('button', { name: 'Suspend', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Suspend', exact: true }).click();
	await expectToast(page, '2 done, 0 failed');
	await expect(page.getByRole('row', { name: /e2e-bulk\.example/ })).toContainText('Suspended');

	// Delete from the row menu
	const row = page.getByRole('row', { name: /e2e-new\.example/ });
	await row.getByLabel('Actions for e2e-new.example').first().click();
	await row.getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'e2e-new.example');
	await expect(page.getByRole('row', { name: /e2e-new\.example/ })).toHaveCount(0);
});

test('user: another user’s domain is not found', async ({ page }) => {
	await login(page, USER);
	const response = await page.goto(`/web/domains/${ADMIN}/anything.example/edit`);
	expect(response.status()).toBe(404);
});

test('admin: dashboard, every owner in the list, edit a user’s domain', async ({ page }) => {
	await login(page, ADMIN);
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Dashboard');
	await expect(page.getByRole('meter', { name: 'CPU' })).toBeVisible();
	await expect(page.getByRole('region', { name: 'Services' })).toBeVisible();

	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Web' })
		.click();
	await expect(page.getByRole('columnheader', { name: 'Owner' })).toBeVisible();
	await expect(page.getByRole('row', { name: new RegExp(SITE) })).toContainText(USER);

	await page.getByLabel('Owner').selectOption(USER);
	await expect(page).toHaveURL(/owner=arce2euser/);

	await page.getByRole('link', { name: SITE }).click();
	await expect(page.getByText(`Owner: ${USER}`, { exact: false })).toBeVisible();
	await page.getByRole('tab', { name: 'Advanced' }).click();
	await expect(
		page.locator('#advanced').getByRole('group', { name: 'Web Template' }),
	).toBeVisible();
	await page.getByRole('tab', { name: 'Redirect' }).click();
	await page
		.locator('#redirect')
		.getByRole('switch', { name: 'Enable domain redirection' })
		.check();
	await page.locator('#redirect').getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
});

test('admin: adds a domain for a user', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto(`/web/domains/create?owner=${USER}`);
	await page.getByRole('textbox', { name: 'Domain', exact: true }).fill('e2e-admin.example');
	await page.getByRole('button', { name: 'Save' }).last().click();
	await expect(page).toHaveURL(new RegExp(`/web/domains/${USER}/e2e-admin.example/edit`));
	await page.getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expect(page).toHaveURL(/\/web\/domains$/);
});

test('user: downloads the access log of a domain', async ({ page }) => {
	await login(page, USER);
	await page.goto(`/web/domains/${USER}/${SITE}/logs/access`);
	const [download] = await Promise.all([
		page.waitForEvent('download'),
		page.getByRole('link', { name: 'Download' }).click(),
	]);
	expect(download.suggestedFilename()).toBe(`${SITE}.access-log.txt`);
});
