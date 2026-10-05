// Happy paths of the Backups module (E7 batch 1 spec §10): tar archives,
// exclusions, restic snapshots and a single-file restore from the file
// manager's snapshot mode.
import { expect, test } from '@playwright/test';
import { expectAccessible, expectToast, login, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

/** On a package with incremental backups; prepare.sh backups made one archive and one snapshot */
const BACKUP_USER = 'arce2ebackup';

test.beforeAll(() => {
	runOnServer(['backups']);
});

test('user: archives list, detail and a restore of one object', async ({ page }) => {
	await login(page, BACKUP_USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Backups' })
		.click();
	await expect(page).toHaveURL(new RegExp(`/app/backups/${BACKUP_USER}$`));
	const archive = page.getByRole('link', { name: new RegExp(`^${BACKUP_USER}\\.`) }).first();
	await expect(archive).toBeVisible();
	await expectAccessible(page);

	await archive.click();
	await expect(page.getByRole('heading', { name: 'Web Domains' })).toBeVisible();
	await expectAccessible(page);
	await page.getByLabel('e2e-backup.example').check();
	await page.getByRole('button', { name: 'Restore Selected' }).click();
	await expectToast(page, 'Task has been added to the queue.');
	runOnServer(['backups-clear-queue']);
});

test('user: exclusions keep their lines and the toggle list', async ({ page }) => {
	await login(page, BACKUP_USER);
	await page.goto(`/app/backups/${BACKUP_USER}/exclusions`);
	await expectAccessible(page);
	await page.getByLabel('User Directories').fill('tmp/cache');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByLabel('User Directories')).toHaveValue('tmp/cache');

	await page.getByLabel('User Directories').fill('');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
});

test('user: browse a snapshot and restore one file', async ({ page }) => {
	runOnServer(['backups-change-file']);
	await login(page, BACKUP_USER);
	await page.goto(`/app/backups/${BACKUP_USER}/snapshots`);
	await expectAccessible(page);
	const row = page.getByRole('row').nth(1);
	await row
		.getByLabel(/Actions for/)
		.first()
		.click();
	await row.getByRole('link', { name: 'Browse Files' }).click();

	await expect(page).toHaveURL(/\/app\/files\/arce2ebackup\?snapshot=[0-9a-f]+/);
	await expect(page.getByText(/read-only/)).toBeVisible();
	await page.getByRole('link', { name: 'tmp', exact: true }).first().click();
	await page.getByRole('link', { name: 'e2e-note.txt' }).first().click();
	await page.getByRole('button', { name: 'Restore', exact: true }).first().click();
	await page.getByRole('dialog').getByRole('button', { name: 'Restore' }).click();
	await expectToast(page, 'e2e-note.txt will be restored from the snapshot.');

	// Run the queued line as v-update-sys-queue would, then check the file
	expect(runOnServer(['backups-run-queue']).trim()).toBe('original');
});

test('another user’s backups are not found', async ({ page }) => {
	await login(page, USER);
	const response = await page.goto(`/app/backups/${BACKUP_USER}`);
	expect(response.status()).toBe(404);
});

test('legacy backup list redirects to the panel app', async ({ page }) => {
	await login(page, BACKUP_USER);
	await page.goto('/list/backup/');
	await expect(page).toHaveURL(new RegExp(`/app/backups/${BACKUP_USER}$`));
});
