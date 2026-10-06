// Happy paths of the Access keys and SSH keys pages (E7 batch 2 spec §8).
// prepare.sh sets API_SYSTEM=2 for the run, so every account may have keys.
import { expect, test } from '@playwright/test';
import { expectAccessible, expectToast, login, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

const SSH_ID = 'e2e-key@arcpanel';

test('user: create an access key, see the secret once, delete it', async ({ page }) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Access keys' })
		.click();
	await expect(page).toHaveURL(new RegExp(`/app/access-keys/${USER}$`));
	await expectAccessible(page);

	await page.getByRole('link', { name: 'Add Access Key' }).first().click();
	await expectAccessible(page);
	await page.getByRole('checkbox', { name: 'purge-nginx-cache' }).check();
	await page.getByRole('textbox', { name: 'Comment' }).fill('e2e key');
	await page.getByRole('button', { name: 'Save' }).click();

	await expect(page.getByText('Warning! Last chance to save secret key!')).toBeVisible();
	const secret = await page.getByRole('textbox', { name: 'Secret Access Key' }).inputValue();
	expect(secret.length).toBeGreaterThan(20);
	await expectAccessible(page);
	// The address became the key page: reloading shows no secret and posts nothing
	await expect(page).toHaveURL(new RegExp(`/app/access-keys/${USER}/[A-Za-z0-9]{20}$`));
	await page.reload();
	await expect(page.getByText('Warning! Last chance to save secret key!')).toHaveCount(0);
	await expect(page.getByText(secret)).toHaveCount(0);
	await expect(page.getByText('purge-nginx-cache')).toBeVisible();

	await page.getByRole('link', { name: 'Back', exact: true }).click();
	const row = page.getByRole('row', { name: /e2e key/ });
	await expect(row).toBeVisible();
	await row.getByRole('checkbox').check();
	await page.locator('.ap-bulk-bar').getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();
	await expectToast(page, '1 done, 0 failed');
	await expect(page.getByRole('row', { name: /e2e key/ })).toHaveCount(0);
});

test('user: add and delete an SSH key', async ({ page }) => {
	const key = runOnServer(['ssh-key']).trim();
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'SSH keys' })
		.click();
	await expect(page).toHaveURL(new RegExp(`/app/ssh-keys/${USER}$`));
	await expectAccessible(page);

	await page.getByRole('button', { name: 'Add SSH Key' }).first().click();
	await page.getByRole('dialog').getByRole('textbox', { name: 'SSH Key' }).fill(key);
	await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'SSH Key has been created successfully.');
	await expect(page.getByRole('row', { name: new RegExp(SSH_ID) })).toContainText('SHA256:');

	// The same key again is refused
	await page.getByRole('button', { name: 'Add SSH Key' }).first().click();
	await page.getByRole('dialog').getByRole('textbox', { name: 'SSH Key' }).fill(key);
	await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'SSH Key already exists.');
	// the drawer opens again with the key to fix it
	await expect(page.getByRole('dialog').getByRole('textbox', { name: 'SSH Key' })).toHaveValue(key);
	await page.keyboard.press('Escape');

	await page.getByRole('button', { name: `Delete ${SSH_ID}` }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `SSH key ${SSH_ID} has been deleted.`);
	await expect(page.getByRole('row', { name: new RegExp(SSH_ID) })).toHaveCount(0);
});
