// Happy paths of the Mail module (E7 batch 1 spec §9).
import { expect, test } from '@playwright/test';
import { ADMIN, BASE_URL, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const KNOWN = 'e2e-mail.example';
const ADDED = 'e2e-mail-added.example';
const BOX = 'e2e-box';
const escapeRe = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const row = (page, text) => page.getByRole('row', { name: new RegExp(escapeRe(text)) });

test('user: add a domain, see its DNS records, edit it, add and edit an account, bulk suspend, delete', async ({
	page,
}) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Mail', exact: true })
		.click();
	await expect(page).toHaveURL(/\/mail$/);
	await expect(row(page, KNOWN)).toBeVisible();
	await expectAccessible(page);

	// Add a domain
	await page.getByRole('link', { name: 'Add Mail Domain' }).first().click();
	await expect(page.getByRole('combobox', { name: 'Webmail Client' })).toHaveValue('roundcube');
	await expectAccessible(page);
	await page
		.getByRole('textbox', { name: 'Domain', exact: true })
		.fill(`www.${ADDED.toUpperCase()}`);
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(new RegExp(`/mail/${USER}/${escapeRe(ADDED)}/accounts$`));
	await expectToast(page, `Mail domain ${ADDED} has been created successfully.`);
	await expectAccessible(page);

	// DNS hints: A, MX, SPF, DMARC and the DKIM key
	await page.getByRole('link', { name: 'DNS Records' }).click();
	await expect(page).toHaveURL(new RegExp(`/mail/${USER}/${escapeRe(ADDED)}/dns$`));
	await expect(page.locator('#mail-dns-0-record')).toHaveValue(`mail.${ADDED}`);
	await expect(page.locator('input[value="v=DMARC1; p=quarantine; pct=100"]')).toBeVisible();
	await expect(page.locator('input[value="mail._domainkey"]')).toBeVisible();
	await expect(page.locator('input[value^="v=spf1 a mx ip4:"]')).toBeVisible();
	await expectAccessible(page);

	// Edit the general tab: a catch-all address
	await page.goto(`/mail/${USER}/${ADDED}/edit`);
	await expectAccessible(page);
	await page.getByRole('textbox', { name: 'Catch-All Email' }).fill('catch@example.net');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByRole('textbox', { name: 'Catch-All Email' })).toHaveValue(
		'catch@example.net',
	);

	// Add an account with an alias and a forward; the client panel mirrors it
	await page.goto(`/mail/${USER}/${ADDED}/accounts`);
	await page.getByRole('link', { name: 'Add Mail Account' }).click();
	await expect(page).toHaveURL(/\/accounts\/create$/);
	await page.getByRole('textbox', { name: 'Account', exact: true }).fill(BOX);
	await page.locator('#f-password').fill('E2e-Mail-Pass1');
	await expect(page.locator('#mail-client-username')).toHaveValue(`${BOX}@${ADDED}`);
	await expect(page.locator('#mail-client-password')).toHaveValue('E2e-Mail-Pass1');
	await expect(page.locator('#mail-client-hostname')).toHaveValue(`mail.${ADDED}`);
	await page.getByText('Advanced Options').click();
	await page.getByRole('textbox', { name: 'Aliases' }).fill('e2e-alias');
	await page.getByRole('textbox', { name: 'Forward to' }).fill('fwd@example.net');
	await expectAccessible(page);
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(new RegExp(`/mail/${USER}/${escapeRe(ADDED)}/accounts$`));
	await expectToast(page, `Mail account ${BOX}@${ADDED} has been created successfully.`);
	await expect(row(page, `${BOX}@${ADDED}`)).toContainText('e2e-alias');
	await expect(row(page, `${BOX}@${ADDED}`)).toContainText('fwd@example.net');

	// Edit the autoreply of the account
	await page.getByRole('link', { name: `${BOX}@${ADDED}`, exact: true }).click();
	await expect(page).toHaveURL(new RegExp(`/accounts/${BOX}/edit$`));
	await expectAccessible(page);
	await page.getByRole('tab', { name: 'Auto Reply' }).click();
	await page.getByRole('switch', { name: 'Auto Reply' }).check();
	await page.getByRole('textbox', { name: 'Message' }).fill('Away until Monday\nThanks');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await page.getByRole('tab', { name: 'Auto Reply' }).click();
	await expect(page.getByRole('textbox', { name: 'Message' })).toHaveValue(
		'Away until Monday\nThanks',
	);

	// Bulk suspend the account
	await page.goto(`/mail/${USER}/${ADDED}/accounts`);
	await page.getByRole('checkbox', { name: `Select ${BOX}@${ADDED}` }).check();
	await page.locator('.ap-bulk-bar').getByRole('button', { name: 'Suspend', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Suspend', exact: true }).click();
	await expectToast(page, '1 done, 0 failed');
	await expect(row(page, `${BOX}@${ADDED}`)).toContainText('Suspended');

	// Delete the new domain from the row menu
	await page.goto('/mail');
	await row(page, ADDED).getByLabel(`Actions for ${ADDED}`).first().click();
	await row(page, ADDED).getByRole('button', { name: 'Delete', exact: true }).click();
	await expect(page.getByRole('alertdialog')).toContainText(
		`Are you sure you want to delete domain ${ADDED}?`,
	);
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `Mail domain ${ADDED} has been deleted.`);
	await expect(row(page, ADDED)).toHaveCount(0);
});

test('user: another user’s mail domain is not found', async ({ page }) => {
	await login(page, USER);
	const response = await page.goto(`/mail/${ADMIN}/${KNOWN}/accounts`);
	expect(response.status()).toBe(404);
});

test('admin: every owner in the list, filter by owner', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/mail');
	await expect(row(page, KNOWN)).toContainText(USER);
	await page.getByLabel('Owner').selectOption(USER);
	await expect(page).toHaveURL(new RegExp(`owner=${USER}`));
	await expect(row(page, KNOWN)).toBeVisible();
	await expectAccessible(page);
});

test('legacy mail pages redirect to the panel app', async ({ page }) => {
	await login(page, USER);
	await page.goto('/list/mail/');
	await expect(page).toHaveURL(/\/mail$/);
	await page.goto(`/list/mail/?domain=${KNOWN}`);
	await expect(page).toHaveURL(new RegExp(`/mail/${USER}/${escapeRe(KNOWN)}/accounts$`));
	await expect(row(page, `info@${KNOWN}`)).toBeVisible();
	await page.goto(`/list/mail/?domain=${KNOWN}&dns=1`);
	await expect(page).toHaveURL(new RegExp(`/mail/${USER}/${escapeRe(KNOWN)}/dns$`));
	// The legacy edit page checks where the request came from
	await page.goto(`/edit/mail/?domain=${KNOWN}&account=info`, {
		referer: `${BASE_URL}/list/mail/`,
	});
	await expect(page).toHaveURL(/\/accounts\/info\/edit$/);
	await expect(page.locator('#mail-client-username')).toHaveValue(`info@${KNOWN}`);
});
