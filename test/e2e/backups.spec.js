// Happy paths of the Backups module (E7 batch 1 spec §10, E48 spec §8.1):
// the overview, archives, the restore wizard, history, exclusions, restic
// snapshots and a single-file restore from the file manager's snapshot mode.
import { expect, test } from '@playwright/test';
import { expectAccessible, expectToast, login, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

/** On a package with incremental backups; prepare.sh backups made one archive and one snapshot */
const BACKUP_USER = 'arce2ebackup';

test.beforeAll(() => {
	runOnServer(['backups']);
});

test('user: the overview, then an archive and a restore of one object', async ({ page }) => {
	await login(page, BACKUP_USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Backups' })
		.click();
	await expect(page).toHaveURL(new RegExp(`/backups/${BACKUP_USER}$`));
	await expect(page.getByRole('heading', { name: 'Last backup' })).toBeVisible();
	await expect(page.getByRole('heading', { name: 'Next backups' })).toBeVisible();
	await expectAccessible(page);

	await page.getByRole('link', { name: 'Archives', exact: true }).click();
	await expect(page).toHaveURL(new RegExp(`/backups/${BACKUP_USER}/archives$`));
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

test('user: the restore wizard restores the items picked', async ({ page }) => {
	await login(page, BACKUP_USER);
	await page.goto(`/backups/${BACKUP_USER}/restore`);
	await expectAccessible(page);
	await page.getByRole('region', { name: 'Archives' }).getByRole('link').first().click();

	// Step 2 of 3: some items of the archive
	await expect(page.getByText('Step 2 / 3')).toBeVisible();
	await page.getByRole('radio', { name: 'Some items' }).check();
	await page.getByRole('checkbox', { name: 'e2e-backup.example' }).check();
	await expectAccessible(page);
	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.getByText('Restoring replaces what is there now')).toBeVisible();
	await page.getByRole('button', { name: 'Restore', exact: true }).click();
	await expectToast(page, 'Task has been added to the queue.');
	await expect(page).toHaveURL(new RegExp(`/backups/${BACKUP_USER}/restore\\?archive=`));
	runOnServer(['backups-clear-queue']);
});

test('user: the history lists the runs of the account', async ({ page }) => {
	await login(page, BACKUP_USER);
	await page.goto(`/backups/${BACKUP_USER}`);
	await page.getByRole('link', { name: 'History', exact: true }).click();
	await expect(page).toHaveURL(new RegExp(`/backups/${BACKUP_USER}/history$`));
	await expect(page.getByRole('heading', { name: 'Backup history' })).toBeVisible();
	await expect(page.getByRole('row').nth(1)).toContainText('Succeeded');
	await expectAccessible(page);
});

test('user: exclusions list the account records and keep the folders', async ({ page }) => {
	await login(page, BACKUP_USER);
	await page.goto(`/backups/${BACKUP_USER}/exclusions`);
	await expectAccessible(page);
	const web = page.locator('[data-exclusions="web"]');
	await expect(web).toContainText('e2e-backup.example');
	await page.getByLabel('Folders of the home directory').fill('tmp/cache');
	await web.getByRole('checkbox', { name: 'e2e-backup.example' }).check();
	await web.getByRole('textbox', { name: /e2e-backup\.example/ }).fill('public_html/cache');
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByLabel('Folders of the home directory')).toHaveValue('tmp/cache');
	await expect(web.getByRole('checkbox', { name: 'e2e-backup.example' })).toBeChecked();
	await expect(web.getByRole('textbox', { name: /e2e-backup\.example/ })).toHaveValue(
		'public_html/cache',
	);

	await page.getByLabel('Folders of the home directory').fill('');
	await web.getByRole('checkbox', { name: 'e2e-backup.example' }).uncheck();
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(web.getByRole('checkbox', { name: 'e2e-backup.example' })).not.toBeChecked();
});

test('user: browse a snapshot and restore one file', async ({ page }) => {
	runOnServer(['backups-change-file']);
	await login(page, BACKUP_USER);
	await page.goto(`/backups/${BACKUP_USER}/snapshots`);
	await expectAccessible(page);
	const row = page.getByRole('row').nth(1);
	await row
		.getByLabel(/Actions for/)
		.first()
		.click();
	await row.getByRole('link', { name: 'Browse Files' }).click();

	await expect(page).toHaveURL(/\/files\/arce2ebackup\?snapshot=[0-9a-f]+/);
	await expect(page.getByText(/read-only/)).toBeVisible();
	await page.getByRole('link', { name: 'tmp', exact: true }).first().click();
	await page.getByRole('link', { name: 'e2e-note.txt' }).first().click();
	await page.getByRole('button', { name: 'Restore', exact: true }).first().click();
	await page.getByRole('dialog').getByRole('button', { name: 'Restore' }).click();
	await expectToast(page, 'e2e-note.txt will be restored from the snapshot.');

	// Run the queued line as v-update-sys-queue would, then check the file
	expect(runOnServer(['backups-run-queue']).trim()).toBe('original');
});

test('user: the backup pages fit a phone', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await login(page, BACKUP_USER);
	for (const path of ['', '/archives', '/history', '/restore', '/exclusions']) {
		await page.goto(`/backups/${BACKUP_USER}${path}`);
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth > window.innerWidth,
		);
		expect(overflow, `/backups/${BACKUP_USER}${path} overflows`).toBe(false);
	}
});

test('another user’s backups are not found', async ({ page }) => {
	await login(page, USER);
	for (const path of ['', '/archives', '/history', '/restore']) {
		const response = await page.goto(`/backups/${BACKUP_USER}${path}`);
		expect(response.status()).toBe(404);
	}
});
