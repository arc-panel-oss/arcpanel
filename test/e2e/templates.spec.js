// Template picker and backend port (E6 spec §9): an administrator adds a
// web domain for a user with the Node.js template from the card picker and
// a port of the user's pool; NGINX answers 502 until the app listens.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, USER } from './helpers.js';
import { runOnServer } from './server.js';

const DOMAIN = 'e2e-node.example';
const PORT = '39900';

/** HTTP status of the domain, read on the server. */
const status = () => runOnServer(['http', DOMAIN]).split('\n')[0].trim();

test('admin adds a Node.js domain from the card picker with a pool port', async ({ page }) => {
	runOnServer(['ports', `${PORT}-39904`]);

	await login(page, ADMIN);
	await page.goto(`/app/web/domains/create?owner=${USER}`);
	await page.getByRole('textbox', { name: 'Domain', exact: true }).fill(DOMAIN);
	await page.getByText('Advanced Options').click();

	const proxy = page.getByRole('group', { name: 'Proxy Template' });
	await expect(proxy.getByText('Standard proxy', { exact: true })).toBeVisible();
	const port = page.getByRole('combobox', { name: 'App port' });
	await expect(port).toBeHidden();

	// Category chip, then the card
	await proxy.getByRole('button', { name: 'Node.js', exact: true }).click();
	await expect(proxy.getByText('Standard proxy', { exact: true })).toBeHidden();
	await proxy.getByText('Node.js app', { exact: true }).click();
	await expect(port).toBeVisible();
	await expect(port).toHaveValue('auto');
	await expectAccessible(page);

	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page).toHaveURL(new RegExp(`/app/web/domains/${USER}/${DOMAIN}/edit`));
	await expectToast(page, DOMAIN);
	await expect(page.locator('.ap-entity')).toContainText('Node.js app');
	await expect(page.locator('.ap-entity')).toContainText(`Port ${PORT}`);

	await expect.poll(status, { timeout: 15_000 }).toBe('502');

	runOnServer(['listen', PORT]);
	await expect.poll(status, { timeout: 15_000 }).toBe('200');
	expect(runOnServer(['http', DOMAIN])).toContain(`arce2e-app-${PORT}`);

	// The Advanced tab shows the template and the port
	await page.getByRole('tab', { name: 'Advanced' }).click();
	await expect(page.locator('#advanced').getByRole('combobox', { name: 'App port' })).toHaveValue(
		PORT,
	);
});
