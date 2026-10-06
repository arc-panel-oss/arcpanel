// Port registry (E12 spec §10): the administrator allocates public-capable
// ports to a reseller, the reseller gives some to its customer, the customer
// reserves one and opens it to the internet; nobody reaches other tenants.
import { expect, test } from '@playwright/test';
import {
	ADMIN,
	CUSTOMER,
	expectAccessible,
	expectToast,
	login,
	RESELLER,
	USER,
} from './helpers.js';

/** Confirm the apConfirm dialog. */
async function confirm(page) {
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
}

test('ports flow from the administrator to a customer and open to the internet', async ({
	page,
}) => {
	await login(page, ADMIN);
	await page.goto('/server/ports');
	await expect(page.getByRole('heading', { name: 'Ports', level: 1 })).toBeVisible();
	await expectAccessible(page);

	await page.getByRole('button', { name: 'Allocate ports' }).click();
	const allocate = page.getByRole('dialog', { name: 'Allocate ports' });
	await allocate.getByRole('combobox', { name: /^Account/ }).selectOption(RESELLER);
	await allocate.getByRole('spinbutton', { name: 'Number of ports' }).fill('10');
	await allocate.getByRole('switch', { name: 'May be public' }).check();
	await page.getByRole('button', { name: 'Allocate', exact: true }).click();
	await expectToast(page, 'Ports have been allocated.');

	await login(page, RESELLER);
	await page.goto('/ports');
	await expect(page.getByRole('heading', { name: 'Your pool' })).toBeVisible();
	await expectAccessible(page);
	await page.getByRole('button', { name: 'Give ports' }).click();
	const give = page.getByRole('dialog', { name: 'Give ports' });
	await give.getByLabel('Customer').selectOption(CUSTOMER);
	await give.getByRole('spinbutton', { name: 'Number of ports' }).fill('4');
	await page.getByRole('button', { name: 'Give', exact: true }).click();
	await expectToast(page, 'Ports have been given.');
	await expect(page.locator('.ap-ports-customers')).toContainText('4 / 0 allocated');

	await login(page, CUSTOMER);
	await page.goto('/ports');
	await page.getByRole('button', { name: 'Reserve a port' }).click();
	const reserve = page.getByRole('dialog', { name: 'Reserve a port' });
	await reserve.getByLabel('What runs on it').fill('e2e app');
	await page.getByRole('button', { name: 'Reserve', exact: true }).click();
	await expectToast(page, 'The port has been reserved.');

	const row = page.locator('tr[data-row]', { hasText: 'e2e app' });
	await expect(row).toContainText('Private, may be public');
	await row.locator('summary').click();
	await row.getByRole('button', { name: 'Open to the internet' }).click();
	await confirm(page);
	await expectToast(page, 'The port is open to the internet.');
	await expect(page.locator('tr[data-row]', { hasText: 'e2e app' })).toContainText('Public');
	await expectAccessible(page);

	// The administrator sees the public port with its owner and no drift
	await login(page, ADMIN);
	await page.goto('/server/ports');
	const entry = page.locator('#ports tr[data-row]', { hasText: 'e2e app' });
	await expect(entry).toContainText(CUSTOMER);
	await expect(entry).toContainText('Public');
	await expect(entry).not.toContainText('No firewall rule');
});

test('ports of other tenants stay out of reach', async ({ page }) => {
	await login(page, CUSTOMER);
	expect((await page.goto('/server/ports'))?.status()).toBe(403);
	await page.goto('/ports');
	const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
	const response = await page.request.post(`/ports/${USER}/reserve`, {
		form: { _token: token ?? '', proto: 'tcp' },
	});
	expect(response.status()).toBe(404);

	await login(page, RESELLER);
	await page.goto('/ports');
	await expect(page.locator('.ap-ports-customers')).not.toContainText(USER);
});
