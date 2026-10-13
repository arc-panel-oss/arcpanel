// Happy paths of the IP address pages (E7 batch 2 spec §7).
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, openSection, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const NAME = 'e2e-ip.example';

test('admin: list, edit the assigned domain and refresh the addresses', async ({ page }) => {
	await login(page, ADMIN);
	await openSection(page, 'IP Addresses', 'Network', 'IP addresses');
	await expect(page).toHaveURL(/\/server\/ips$/);
	await expectAccessible(page);

	// The first address of the server: give it a domain name, then take it away
	const link = page.locator('#server-ips tbody [data-col="ip"] a').first();
	const address = (await link.innerText()).trim();
	await link.click();
	await expect(page).toHaveURL(new RegExp(`/server/ips/${address.replace(/\./g, '\\.')}/edit$`));
	await expectAccessible(page);
	const nat = await page.getByRole('textbox', { name: 'NAT IP Association' }).inputValue();
	await page.getByRole('textbox', { name: 'Assigned Domain' }).fill(NAME);
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByRole('textbox', { name: 'Assigned Domain' })).toHaveValue(NAME);
	// unchanged fields are not sent to the server again
	await expect(page.getByRole('textbox', { name: 'NAT IP Association' })).toHaveValue(nat);

	await page.goto('/server/ips');
	await expect(page.getByRole('row', { name: new RegExp(address) })).toContainText(NAME);

	await page.goto(`/server/ips/${address}/edit`);
	await page.getByRole('textbox', { name: 'Assigned Domain' }).fill('');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');

	await page.goto('/server/ips');
	await page.getByRole('button', { name: 'Refresh IP Addresses' }).click();
	await expectToast(page, 'IP addresses have been refreshed.');
	await expect(page.getByRole('row', { name: new RegExp(address) })).not.toContainText(NAME);
});

test('user: no IP addresses in the menu, 403 on the page', async ({ page }) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'IP Addresses' }),
	).toHaveCount(0);
	const response = await page.goto('/server/ips');
	expect(response.status()).toBe(403);
});
