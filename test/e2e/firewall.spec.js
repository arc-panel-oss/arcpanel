// Happy paths of the Firewall pages (E7 batch 2 spec §6).
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, openSection, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const RULE = 'e2e-fw-rule';
const LIST = 'arce2e-list';
const BANNED = '198.51.100.77';
const row = (page, text) => page.getByRole('row', { name: new RegExp(text) });

test('admin: add, edit, move, suspend and delete a firewall rule', async ({ page }) => {
	await login(page, ADMIN);
	await openSection(page, 'Firewall', 'Network', 'Firewall');
	await expect(page).toHaveURL(/\/server\/firewall$/);
	await expect(page.getByRole('heading', { name: 'Firewall' })).toBeVisible();
	await expectAccessible(page);

	// Add: spaces in the port list become commas
	await page.getByRole('link', { name: 'Add Rule' }).click();
	await expectAccessible(page);
	await page.getByRole('combobox', { name: 'Action' }).selectOption('DROP');
	await page.getByRole('combobox', { name: 'Protocol' }).selectOption('TCP');
	await page.getByRole('textbox', { name: 'Port', exact: true }).fill('4444 4445');
	await page.getByRole('textbox', { name: 'IP Address / IPset IP List' }).fill('198.51.100.0/24');
	await page.getByRole('textbox', { name: 'Comment' }).fill(RULE);
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(/\/server\/firewall$/);
	await expectToast(page, 'Rule has been created successfully.');
	await expect(row(page, RULE)).toContainText('4444,4445');

	// Edit: another port
	await row(page, RULE)
		.getByLabel(/Actions for rule/)
		.first()
		.click();
	await row(page, RULE).getByRole('link', { name: 'Edit Firewall Rule' }).click();
	await expect(page).toHaveURL(/\/server\/firewall\/\d+\/edit$/);
	await page.getByRole('textbox', { name: 'Port', exact: true }).fill('4446');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByRole('textbox', { name: 'Port', exact: true })).toHaveValue('4446');

	// Move it up one place
	await page.goto('/server/firewall');
	const before = await row(page, RULE).locator('[data-col="pos"]').innerText();
	await row(page, RULE)
		.getByLabel(/Actions for rule/)
		.first()
		.click();
	await row(page, RULE).getByRole('button', { name: 'Move Firewall Rule Up' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expect(row(page, RULE).locator('[data-col="pos"]')).toHaveText(String(Number(before) - 1));

	// Bulk suspend, then delete from the row menu
	await row(page, RULE).getByRole('checkbox').check();
	await page.locator('.ap-bulk-bar').getByRole('button', { name: 'Suspend', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Suspend', exact: true }).click();
	await expectToast(page, '1 done, 0 failed');
	await expect(row(page, RULE)).toContainText('Suspended');
	await row(page, RULE)
		.getByLabel(/Actions for rule/)
		.first()
		.click();
	await row(page, RULE).getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'Rule has been deleted.');
	await expect(row(page, RULE)).toHaveCount(0);
});

test('admin: add and delete an IPset list', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/firewall');
	await page.getByRole('link', { name: 'IPset IP Lists' }).click();
	await expect(page).toHaveURL(/\/server\/firewall\/ipsets$/);
	await page.getByRole('link', { name: 'Add IP list' }).first().click();
	await expectAccessible(page);
	await page.getByRole('textbox', { name: 'IP List Name' }).fill(LIST);
	await page.getByRole('textbox', { name: 'Data Source' }).fill('file:/root/arce2e-ipset.txt');
	await page.getByRole('combobox', { name: 'Auto Update' }).selectOption('no');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'IP list has been created successfully.');
	await expect(row(page, LIST)).toContainText('Disabled');
	await expectAccessible(page);

	// The list is a choice of the rule form
	await page.goto('/server/firewall/create');
	await page.getByRole('combobox', { name: 'IPset IP Lists' }).selectOption(`ipset:${LIST}`);
	await expect(page.getByRole('textbox', { name: 'IP Address / IPset IP List' })).toHaveValue(
		`ipset:${LIST}`,
	);

	await page.goto('/server/firewall/ipsets');
	await row(page, LIST)
		.getByRole('button', { name: `Delete ${LIST}` })
		.click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'IP list has been deleted.');
	await expect(row(page, LIST)).toHaveCount(0);
});

test('admin: ban and unban an address', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/firewall');
	await page.getByRole('link', { name: 'Banned IP Addresses' }).click();
	await expect(page).toHaveURL(/\/server\/firewall\/bans$/);
	await page.getByRole('link', { name: 'Ban IP Address' }).first().click();
	await page.getByRole('textbox', { name: 'IP Address' }).fill(BANNED);
	await page.getByRole('combobox', { name: 'Banlist' }).selectOption('SSH');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'IP address has been banned successfully.');
	await expect(row(page, BANNED)).toContainText('SSH');
	await expectAccessible(page);

	await row(page, BANNED)
		.getByRole('button', { name: `Delete ${BANNED}` })
		.click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'IP address has been unbanned.');
	await expect(row(page, BANNED)).toHaveCount(0);
});

test('user: no firewall in the menu, 403 on the page', async ({ page }) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Firewall' }),
	).toHaveCount(0);
	const response = await page.goto('/server/firewall');
	expect(response.status()).toBe(403);
});
