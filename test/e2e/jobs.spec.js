// The Job Center pages (E15, docs/features/specs/2026-11-05-001-e15-job-center-design.md
// §6.2, §10): the Jobs page per role, a job followed on its page, cancel of a
// queued job, retry of a failed one, the tray, and a reseller who gets 404 for
// a job of the server.
import { expect, test } from '@playwright/test';
import { ADMIN, CUSTOMER, expectAccessible, login, RESELLER, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

let failed = '';
let queued = '';

test.beforeAll(() => {
	failed = runOnServer(['jobs-failed', USER]).trim();
});

test.afterAll(() => {
	runOnServer(['jobs-release', USER]);
});

test('user: the Jobs page lists the user’s jobs and opens one', async ({ page }) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Jobs', exact: true })
		.click();
	await expect(page).toHaveURL(/\/jobs$/);
	const row = page.locator(`tr[data-job-id="${failed}"]`);
	await expect(row).toContainText('backup.download');
	await expect(row).toContainText('Failed');
	await expectAccessible(page);

	await row.getByRole('link', { name: 'backup.download' }).click();
	await expect(page).toHaveURL(new RegExp(`/jobs/${failed}$`));
	await expect(page.locator('[data-job-error]')).toBeVisible();
	await expectAccessible(page);
});

test('user: retry runs a failed job again as a new job', async ({ page }) => {
	await login(page, USER);
	await page.goto(`/jobs/${failed}`);
	await page.getByRole('button', { name: 'Retry' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expect(page).not.toHaveURL(new RegExp(`/jobs/${failed}$`));
	await expect(page).toHaveURL(/\/jobs\/\d{14}$/);
	await expect(page.getByRole('link', { name: failed })).toBeVisible();
});

test('user: cancel stops a queued job, and the tray lists it', async ({ page }) => {
	queued = runOnServer(['jobs-queued', USER]).trim();
	await login(page, USER);
	await page.goto(`/jobs/${queued}`);
	await expect(page.locator('[data-job-status]')).toContainText('Queued');
	await page.getByRole('button', { name: 'Cancel' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expect(page.locator('[data-job-status]')).toContainText('Cancelled');

	await page.getByRole('button', { name: /^Notifications/ }).click();
	await page.locator('[data-tray-jobs]').click();
	await expect(page.locator('[data-tray-jobs-list]')).toContainText('backup.user');
});

test('reseller: sees the jobs of its customers, not those of the server', async ({ page }) => {
	await login(page, RESELLER);
	await page.goto('/jobs');
	await expect(page.getByRole('heading', { name: 'Jobs' })).toBeVisible();
	await expect(page.locator('tr[data-job-id="99999999999990"]')).toHaveCount(0);
	const response = await page.goto('/jobs/99999999999990');
	expect(response.status()).toBe(404);
	await expect(page.locator(`tr[data-job-id="${failed}"]`)).toHaveCount(0);
	expect(CUSTOMER).toBeTruthy();
});

test('administrator: sees every job and filters by status', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/jobs?status=failed');
	await expect(page.locator(`tr[data-job-id="${failed}"]`)).toBeVisible();
	await expect(page.locator('tr[data-job-id] td[data-col="status"]').first()).toContainText(
		'Failed',
	);
	await expectAccessible(page);
});
