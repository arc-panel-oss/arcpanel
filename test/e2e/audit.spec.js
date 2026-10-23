// The audit log page (E23, docs/features/specs/2026-10-23-002-e23-audit-log-design.md §7.1, §10).
import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import {
	ADMIN,
	CUSTOMER,
	expectAccessible,
	login,
	openSection,
	RESELLER,
	USER,
} from './helpers.js';

test.describe.configure({ mode: 'serial' });

/** Log in as NAME from its account page and come back. */
async function loginAsAndReturn(page, name) {
	await page.goto(`/users/${name}`);
	await page.getByRole('button', { name: 'Log in as' }).click();
	await expect(page.getByRole('heading', { name: `Welcome, ${name}` })).toBeVisible();
	await page.getByRole('button', { name: 'Return' }).click();
}

test('administrator: filter by user and see a login-as pair, then verify the chain', async ({
	page,
}) => {
	await login(page, ADMIN);
	await loginAsAndReturn(page, USER);

	await openSection(page, 'Audit log');
	await expect(page).toHaveURL(/\/audit$/);
	await page.fill('#f-user', USER);
	await page.getByRole('button', { name: 'Filter' }).click();
	await expect(page).toHaveURL(new RegExp(`user=${USER}`));

	const log = page.locator('#audit-log');
	const start = log.locator('tbody tr', { hasText: 'impersonate.start' }).first();
	const stop = log.locator('tbody tr', { hasText: 'impersonate.stop' }).first();
	await expect(start).toContainText(ADMIN);
	await expect(start).toContainText(`as ${USER}`);
	await expect(stop).toContainText(`as ${USER}`);
	await expectAccessible(page);

	await start.getByRole('button', { name: 'Show', exact: true }).click();
	const drawer = page.getByRole('dialog', { name: 'Audit event' });
	await expect(drawer).toContainText('"action": "impersonate.start"');
	await expect(drawer).toContainText('"hash"');
	await drawer.getByRole('button', { name: 'Close' }).click();

	await page.getByRole('link', { name: 'Verify chain' }).click();
	await expect(page.getByRole('status').filter({ hasText: 'The chain is intact' })).toBeVisible();
});

test('reseller: sees only its tree, without the chain check', async ({ page }) => {
	await login(page, RESELLER);
	await loginAsAndReturn(page, CUSTOMER);

	await openSection(page, 'Audit log');
	await expect(page.getByRole('link', { name: 'Verify chain' })).toHaveCount(0);
	await expect(page.locator('#audit-log')).toContainText(`as ${CUSTOMER}`);

	// The administrator's own customer is outside the tree
	await page.goto(`/audit?user=${USER}`);
	await expect(page.locator('main')).toContainText('No events');
	expect((await page.goto('/audit/verify')).status()).toBe(403);
});

test('administrator: export the filtered events as CSV', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto(`/audit?action=auth.login`);
	const [download] = await Promise.all([
		page.waitForEvent('download'),
		page.getByRole('link', { name: 'Export CSV' }).click(),
	]);
	expect(download.suggestedFilename()).toMatch(/^audit-\d{4}-\d{2}-\d{2}-\d{4}-\d{2}-\d{2}\.csv$/);
	const csv = readFileSync(await download.path(), 'utf8');
	expect(csv.split('\n')[0]).toBe(
		'TIME,ID,CHANNEL,ACTOR,ROLE,IP,KEY,EFFECTIVE_USER,ACTION,OBJECT_TYPE,OBJECT_USER,OBJECT_NAME,RESULT,STATUS,FIELDS,REQUEST_ID,HASH',
	);
	// This test's own sign-in is in the export
	expect(csv).toContain(`,${ADMIN},`);
	expect(csv).toContain('auth.login');
});
