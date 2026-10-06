// Happy paths of the DNS module (E7 batch 1 spec §8).
import { expect, test } from '@playwright/test';
import { ADMIN, BASE_URL, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const KNOWN = 'e2e-zone.example';
const ADDED = 'e2e-added.example';
const escapeRe = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const row = (page, text) => page.getByRole('row', { name: new RegExp(escapeRe(text)) });
const drawer = (page) => page.getByRole('dialog');

test('user: add a zone, add and edit records, bulk delete them, edit the zone, bulk suspend, delete', async ({
	page,
}) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'DNS', exact: true })
		.click();
	await expect(page).toHaveURL(/\/dns$/);
	await expect(row(page, KNOWN)).toBeVisible();
	await expectAccessible(page);

	// Add a zone: the IP and the name servers are prefilled
	await page.getByRole('link', { name: 'Add DNS Domain' }).first().click();
	await expect(page.getByRole('combobox', { name: 'IP Address' })).not.toHaveValue('');
	await page.getByText('Advanced Options').click();
	await expect(page.getByRole('textbox', { name: 'Name server 1', exact: true })).not.toHaveValue(
		'',
	);
	await expectAccessible(page);
	await page
		.getByRole('textbox', { name: 'Domain', exact: true })
		.fill(`www.${ADDED.toUpperCase()}`);
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(new RegExp(`/dns/${USER}/${escapeRe(ADDED)}/records$`));
	await expectToast(page, `DNS zone ${ADDED} has been created successfully.`);
	await expectAccessible(page);

	// Add an A record in the drawer
	await page.getByRole('button', { name: 'Add Record' }).click();
	await expect(drawer(page)).toBeVisible();
	await expect(drawer(page).getByRole('textbox', { name: 'Record', exact: true })).toHaveValue('@');
	await expect(drawer(page).getByRole('textbox', { name: 'Priority' })).toBeHidden();
	await drawer(page).getByRole('textbox', { name: 'Record', exact: true }).fill('e2e-a');
	await drawer(page).getByRole('combobox', { name: 'IP or Value' }).fill('198.51.100.20');
	await drawer(page).getByRole('button', { name: 'Save' }).click();
	await expectToast(page, `Record e2e-a.${ADDED} has been created successfully.`);
	await expect(row(page, '198.51.100.20')).toBeVisible();

	// ... and a TXT record; MX shows the priority
	await page.getByRole('button', { name: 'Add Record' }).click();
	await drawer(page).getByRole('combobox', { name: 'Type' }).selectOption('MX');
	await expect(drawer(page).getByRole('textbox', { name: 'Priority' })).toBeVisible();
	await drawer(page).getByRole('combobox', { name: 'Type' }).selectOption('TXT');
	await expect(drawer(page).getByRole('textbox', { name: 'Priority' })).toBeHidden();
	await drawer(page).getByRole('textbox', { name: 'Record', exact: true }).fill('e2e-txt');
	await drawer(page).getByRole('combobox', { name: 'IP or Value' }).fill('e2e-text-value');
	await drawer(page).getByRole('button', { name: 'Save' }).click();
	await expectToast(page, `Record e2e-txt.${ADDED} has been created successfully.`);
	await expect(row(page, 'e2e-text-value')).toBeVisible();

	// Edit the A record: the drawer opens on its URL and stays on it after saving
	await page.getByRole('link', { name: 'e2e-a', exact: true }).click();
	await expect(page).toHaveURL(/\/records\?edit=\d+$/);
	await expect(drawer(page)).toBeVisible();
	await expect(drawer(page).getByRole('heading', { name: 'Edit DNS Record' })).toBeVisible();
	await expectAccessible(page);
	await drawer(page).getByRole('combobox', { name: 'IP or Value' }).fill('198.51.100.21');
	await drawer(page).getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(drawer(page).getByRole('combobox', { name: 'IP or Value' })).toHaveValue(
		'198.51.100.21',
	);
	await page.keyboard.press('Escape');
	await expect(drawer(page)).toBeHidden();
	await expect(row(page, '198.51.100.21')).toBeVisible();

	// Bulk delete both records
	await page.getByRole('checkbox', { name: 'Select e2e-a A' }).check();
	await page.getByRole('checkbox', { name: 'Select e2e-txt TXT' }).check();
	await page.locator('.ap-bulk-bar').getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();
	await expectToast(page, '2 done, 0 failed');
	await expect(row(page, '198.51.100.21')).toHaveCount(0);
	await expect(row(page, 'e2e-text-value')).toHaveCount(0);

	// Edit the zone TTL
	await page.getByRole('link', { name: 'Edit DNS Domain' }).click();
	await expect(page).toHaveURL(new RegExp(`/dns/${USER}/${escapeRe(ADDED)}/edit$`));
	await expectAccessible(page);
	await page.getByRole('textbox', { name: 'TTL' }).fill('3600');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByRole('textbox', { name: 'TTL' })).toHaveValue('3600');

	// Bulk suspend both zones
	await page.goto('/dns');
	await page.getByRole('checkbox', { name: `Select ${KNOWN}` }).check();
	await page.getByRole('checkbox', { name: `Select ${ADDED}` }).check();
	await page.locator('.ap-bulk-bar').getByRole('button', { name: 'Suspend', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Suspend', exact: true }).click();
	await expectToast(page, '2 done, 0 failed');
	await expect(row(page, KNOWN)).toContainText('Suspended');
	await expect(row(page, ADDED)).toContainText('Suspended');

	// Unsuspend the known zone and delete the new one from the row menu
	await row(page, KNOWN).getByLabel(`Actions for ${KNOWN}`).first().click();
	await row(page, KNOWN).getByRole('button', { name: 'Unsuspend', exact: true }).click();
	await expect(page.getByRole('alertdialog')).toContainText(
		`Are you sure you want to unsuspend domain ${KNOWN}?`,
	);
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `DNS zone ${KNOWN} has been unsuspended.`);
	await expect(row(page, KNOWN)).toContainText('Active');

	await row(page, ADDED).getByLabel(`Actions for ${ADDED}`).first().click();
	await row(page, ADDED).getByRole('button', { name: 'Delete', exact: true }).click();
	await expect(page.getByRole('alertdialog')).toContainText(
		`Are you sure you want to delete domain ${ADDED}?`,
	);
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `DNS zone ${ADDED} has been deleted.`);
	await expect(row(page, ADDED)).toHaveCount(0);
});

test('user: another user’s zone is not found', async ({ page }) => {
	await login(page, USER);
	const response = await page.goto(`/dns/${ADMIN}/${KNOWN}/records`);
	expect(response.status()).toBe(404);
});

test('admin: every owner in the list, filter by owner', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/dns');
	await expect(row(page, KNOWN)).toContainText(USER);
	await page.getByLabel('Owner').selectOption(USER);
	await expect(page).toHaveURL(new RegExp(`owner=${USER}`));
	await expect(row(page, KNOWN)).toBeVisible();
	await expectAccessible(page);
});

test('legacy DNS pages redirect to the panel app', async ({ page }) => {
	await login(page, USER);
	await page.goto('/list/dns/');
	await expect(page).toHaveURL(/\/dns$/);
	await page.goto(`/list/dns/?domain=${KNOWN}`);
	await expect(page).toHaveURL(new RegExp(`/dns/${USER}/${escapeRe(KNOWN)}/records$`));
	// The legacy add page checks where the request came from
	await page.goto(`/add/dns/?domain=${KNOWN}`, { referer: `${BASE_URL}/list/dns/` });
	await expect(page).toHaveURL(/\/records\?add=1$/);
	await expect(drawer(page)).toBeVisible();
});
