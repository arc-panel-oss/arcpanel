// Search page and the CSR generator (E7 batch 3 spec §12).
import { expect, test } from '@playwright/test';
import { expectAccessible, login, SITE, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('user: all results of the palette on the search page', async ({ page }) => {
	await login(page, USER);
	await page.keyboard.press('Control+k');
	const dialog = page.getByRole('dialog', { name: 'Search' });
	await dialog.getByRole('combobox').fill('e2e-site');
	await expect(dialog.getByRole('option', { name: new RegExp(SITE) }).first()).toBeVisible();
	await dialog.getByRole('link', { name: 'View all results' }).click();

	await expect(page).toHaveURL(/\/app\/search\?q=e2e-site$/);
	await expect(page.getByRole('link', { name: new RegExp(SITE) }).first()).toBeVisible();
	await expectAccessible(page);

	const form = page.getByRole('main').getByRole('search');
	await form.getByRole('textbox').fill('zz-nothing-here');
	await form.getByRole('button', { name: 'Search' }).click();
	await expect(page.getByRole('heading', { name: 'No results' })).toBeVisible();
});

test('user: generate a CSR into the SSL form of a web domain', async ({ page }) => {
	test.setTimeout(120_000);
	await login(page, USER);
	await page.goto(`/app/web/domains/${USER}/${SITE}/edit`);
	await page.getByRole('tab', { name: 'SSL' }).click();
	const ssl = page.locator('#ssl');
	await expect(ssl).toBeVisible();
	// the certificate fields show for a custom certificate
	const sslSwitch = ssl.getByRole('switch', { name: 'Enable SSL for this domain' });
	if (!(await sslSwitch.isChecked())) {
		await sslSwitch.check();
	}
	const le = ssl.getByRole('switch', { name: /Let’s Encrypt/ });
	if (await le.isChecked()) {
		await le.uncheck();
	}
	await ssl.getByRole('button', { name: 'Generate CSR' }).click();
	const dialog = page.getByRole('dialog', { name: 'Generate CSR' });
	await expect(dialog.getByLabel('Domain')).toHaveValue(SITE);
	await dialog.getByLabel('Email').fill(`${USER}@example.invalid`);
	await expectAccessible(page);
	await dialog.getByRole('button', { name: 'Generate', exact: true }).click();

	await expect(dialog.getByLabel('SSL CSR')).toHaveValue(/BEGIN CERTIFICATE REQUEST/, {
		timeout: 60_000,
	});
	await dialog.getByRole('button', { name: 'Close' }).first().click();
	await expect(ssl.getByLabel('SSL Certificate', { exact: true })).toHaveValue(/BEGIN CERTIFICATE/);
	await expect(ssl.getByLabel('SSL Private Key')).toHaveValue(/PRIVATE KEY/);
});
