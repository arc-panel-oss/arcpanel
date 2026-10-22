// Happy paths of the administrators' Backups hub (E48 spec §8.2): a local
// destination added with the wizard and tested, a job with a cron preview,
// Run now and the run in Activity, then both removed. Resellers and users
// do not reach the hub.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, RESELLER, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

const DEST = 'arce2e-dest';
const JOB = 'arce2e-job';
const BACKUP_USER = 'arce2ebackup';

test.beforeAll(() => {
	runOnServer(['backups']);
	runOnServer(['backups-admin-clean']);
});

test.afterAll(() => {
	runOnServer(['backups-admin-clean']);
});

test('admin: add a local destination with the wizard and test it', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/backups');
	await expectAccessible(page);
	await page.getByRole('link', { name: 'Destinations', exact: true }).first().click();
	await expect(page).toHaveURL(/\/server\/backups\/destinations$/);
	await page.getByRole('link', { name: 'Add destination' }).first().click();
	await expectAccessible(page);

	await page.getByRole('radio', { name: /Local folder/ }).check({ force: true });
	await page.getByRole('button', { name: 'Continue' }).click();
	await page.locator('#dest-local-path').fill(`/backup/${DEST}`);
	await page.getByRole('button', { name: 'Continue' }).click();
	await page.getByRole('textbox', { name: 'Name', exact: true }).fill(DEST);
	await page.getByRole('button', { name: 'Add and test' }).click();

	await expect(page).toHaveURL(new RegExp(`/server/backups/destinations/${DEST}$`));
	await expectToast(page, `Destination ${DEST} has been added.`);
	await expect(page.getByText('Working')).toBeVisible();
	await expectAccessible(page);
});

test('admin: add a paused job with a cron schedule, run it now and see the run', async ({
	page,
}) => {
	await login(page, ADMIN);
	await page.goto('/server/backups/jobs/new');
	await expectAccessible(page);
	await page.getByRole('textbox', { name: 'Name', exact: true }).fill(JOB);
	await page.getByRole('combobox', { name: 'Runs' }).selectOption('cron');
	await page.getByRole('textbox', { name: 'Cron expression' }).fill('30 */6 * * 1-5');
	// The next runs come from the server as the schedule is typed
	await expect(page.locator('[data-schedule-preview]')).toContainText(/\d{4}-\d{2}-\d{2} \d{2}:30/);
	await page.getByRole('radio', { name: 'Some users' }).check();
	await page.getByRole('checkbox', { name: BACKUP_USER }).check();
	await page.getByRole('combobox', { name: 'Destination' }).first().selectOption(DEST);
	await page.getByRole('switch', { name: 'Run on the schedule' }).uncheck();
	await page.getByRole('button', { name: 'Save' }).click();

	await expect(page).toHaveURL(/\/server\/backups\/jobs$/);
	await expectToast(page, `Job ${JOB} has been added.`);
	const row = page.getByRole('row', { name: new RegExp(JOB) });
	await expect(row).toContainText('Paused');
	await row.getByRole('button', { name: 'Run now' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `Job ${JOB} has started.`);

	// The run shows in Activity and ends with the account backed up
	await expect(async () => {
		await page.goto('/server/backups/activity');
		await expect(page.getByRole('row', { name: new RegExp(JOB) }).first()).toContainText(
			'Succeeded',
		);
	}).toPass({ timeout: 120_000, intervals: [3_000] });
	await expectAccessible(page);

	// The archive names the destination on the account's page
	await page.goto(`/backups/${BACKUP_USER}/archives?destination=${DEST}`);
	await expect(
		page.getByRole('link', { name: new RegExp(`^${BACKUP_USER}\\.`) }).first(),
	).toBeVisible();
});

test('admin: delete the job, then the destination', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/backups/jobs');
	await page
		.getByRole('row', { name: new RegExp(JOB) })
		.getByRole('link', { name: 'Edit' })
		.click();
	await page.getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `Job ${JOB} has been deleted.`);

	await page.goto(`/server/backups/destinations/${DEST}`);
	await page.getByRole('button', { name: 'Delete', exact: true }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `Destination ${DEST} has been deleted.`);
});

test('admin: the hub fits a phone', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await login(page, ADMIN);
	for (const path of [
		'',
		'/destinations',
		'/destinations/new',
		'/jobs',
		'/jobs/new',
		'/activity',
		'/settings',
	]) {
		await page.goto(`/server/backups${path}`);
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth > window.innerWidth,
		);
		expect(overflow, `/server/backups${path} overflows`).toBe(false);
	}
});

test('resellers and users do not reach the hub', async ({ page }) => {
	for (const user of [RESELLER, USER]) {
		await login(page, user);
		for (const path of ['', '/destinations', '/jobs', '/activity']) {
			const response = await page.goto(`/server/backups${path}`);
			expect(response.status(), `${user} ${path}`).toBe(403);
		}
		await page.context().clearCookies();
	}
});
