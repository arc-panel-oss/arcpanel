// The Alerts pages (E16, docs/features/specs/2026-11-05-002-e16-alerts-design.md
// §7, §10): the Alerts page of a user with a Slack channel whose webhook is a
// local stub, a test message sent to it and a rule changed; the server page
// of the administrator; a reseller who reaches neither the server scope nor
// an account outside its tree.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, RESELLER, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

const STUB_PORT = '39931';

test.beforeAll(() => {
	runOnServer(['alerts-clean', USER]);
	runOnServer(['alerts-listen', STUB_PORT]);
});

test.afterAll(() => {
	runOnServer(['alerts-clean', USER]);
});

test('user: the Alerts page lists the rules and the panel channel', async ({ page }) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Alerts', exact: true })
		.click();
	await expect(page).toHaveURL(new RegExp(`/alerts/${USER}`));
	await expect(page.getByRole('heading', { name: 'Alerts', exact: true })).toBeVisible();
	await page.getByRole('tab', { name: 'Rules' }).click();
	await expect(page.locator('tr[data-rule="ssl.expiring"]')).toBeVisible();
	await expect(page.locator('tr[data-rule="disk.usage"]')).toHaveCount(0);
	await page.getByRole('tab', { name: 'Channels' }).click();
	await expect(page.locator('#alerts-channels')).toContainText('Panel');
	await expectAccessible(page);
});

test('user: adds a Slack channel, sends a test to the stub and deletes it', async ({ page }) => {
	await login(page, USER);
	await page.goto(`/alerts/${USER}?tab=channels`);
	await page.getByRole('button', { name: 'Add Channel' }).click();
	const drawer = page.getByRole('dialog', { name: 'Add Channel' });
	await drawer.getByLabel('Name').fill('E2E Slack');
	await drawer.getByLabel('Type').selectOption('slack');
	await drawer
		.getByLabel('Bot token or webhook URL')
		.fill(`http://127.0.0.1:${STUB_PORT}/arce2e-hook`);
	await drawer.getByRole('button', { name: 'Save channel' }).click();
	await expectToast(page, 'Channel E2E Slack has been added.');

	const row = page.locator('tr[data-channel]', { hasText: 'E2E Slack' });
	await expect(row).toContainText('Webhook URL saved');
	await expect(page.locator('body')).not.toContainText('arce2e-hook');

	await row.getByRole('button', { name: /Send test/ }).click();
	await expectToast(page, 'A test message went to E2E Slack.');
	expect(runOnServer(['alerts-posts', STUB_PORT]).trim()).toBe('1');

	await page
		.locator('tr[data-channel]', { hasText: 'E2E Slack' })
		.getByRole('button', { name: 'Delete E2E Slack' })
		.click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'Channel E2E Slack has been deleted.');
	await expect(page.locator('tr[data-channel]', { hasText: 'E2E Slack' })).toHaveCount(0);
});

test('user: changes the threshold of a rule', async ({ page }) => {
	await login(page, USER);
	await page.goto(`/alerts/${USER}?tab=rules`);
	const row = page.locator('tr[data-rule="ssl.expiring"]');
	await row.getByRole('button', { name: /Edit/ }).click();
	const dialog = page.getByRole('dialog').filter({ has: page.getByLabel(/Warning at/) });
	await dialog.getByLabel(/Warning at/).fill('21');
	await dialog.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'has been saved.');
	await expect(
		page.locator('tr[data-rule="ssl.expiring"] td[data-col="thresholds"]'),
	).toContainText('warning 21');
	// Back to the catalog default
	await page.locator('tr[data-rule="ssl.expiring"]').getByRole('button', { name: /Edit/ }).click();
	await page
		.getByRole('dialog')
		.getByLabel(/Warning at/)
		.fill('14');
	await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'has been saved.');
});

test('administrator: the server page has the server rules and channels', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/alerts?tab=rules');
	await expect(page.getByRole('heading', { name: 'Server alerts' })).toBeVisible();
	await expect(page.locator('tr[data-rule="disk.usage"]')).toBeVisible();
	await expect(page.locator('tr[data-rule="service.down"]')).toBeVisible();
	await page.getByRole('tab', { name: 'Channels' }).click();
	await expect(page.locator('#alerts-channels')).toContainText('Every administrator');
	await page.getByRole('tab', { name: 'History' }).click();
	await expect(page.getByRole('button', { name: 'Filter' })).toBeVisible();
	await expectAccessible(page);
});

test('reseller: reaches neither the server alerts nor an account outside its tree', async ({
	page,
}) => {
	await login(page, RESELLER);
	await page.goto('/alerts');
	await expect(page).toHaveURL(new RegExp(`/alerts/${RESELLER}`));
	expect((await page.goto('/server/alerts')).status()).toBe(403);
	expect((await page.goto(`/alerts/${USER}`)).status()).toBe(404);
});
