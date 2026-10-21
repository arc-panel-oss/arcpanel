// Happy paths of the Updates page of the Update Center (E33 phase 1 spec §8).
// The dialog test loads the pre-flight of a release and closes the dialog.
// Updating to a release and rolling it back runs only against the local
// signed repository of the end-to-end run of M8 (spec §12), with
// E2E_UPDATE_RELEASE set; elsewhere that test is skipped.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('admin: the status, the releases and the history', async ({ page }) => {
	await login(page, ADMIN);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Updates' })
		.click();
	await expect(page).toHaveURL(/\/server\/updates$/);
	const status = page.getByRole('region', { name: 'Update status' });
	await expect(status).toContainText('Installed');
	await expect(status).toContainText('Release manifest');
	await expect(page.getByRole('heading', { name: 'Available releases' })).toBeVisible();
	await expect(page.getByRole('heading', { name: 'History' })).toBeVisible();
	await expectAccessible(page);

	await page.getByRole('button', { name: 'Check now' }).click();
	await expect(page.locator('.ap-toast')).toBeVisible();
});

test('admin: change the policy and its window, then restore it', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/updates');
	const form = page.locator('form', { has: page.getByRole('button', { name: 'Save policy' }) });
	const policy = await form.locator('input[name="policy"]:checked').inputValue();
	const from = await form.getByLabel('From').inputValue();
	const until = await form.getByLabel('Until').inputValue();
	const delay = await form.getByLabel('Delay, days').inputValue();
	const other = policy === 'notify' ? 'Off' : 'Notify only';

	await form.getByText(other, { exact: true }).click();
	await form.getByLabel('From').selectOption('1');
	await form.getByLabel('Until').selectOption('3');
	await form.getByLabel('Delay, days').fill('4');
	await form.getByRole('button', { name: 'Save policy' }).click();
	await expectToast(page, 'The update policy has been saved.');
	await expect(page.getByRole('region', { name: 'Update status' })).toContainText(other);

	const saved = page.locator('form', { has: page.getByRole('button', { name: 'Save policy' }) });
	await saved.locator(`input[name="policy"][value="${policy}"]`).check();
	await saved.getByLabel('From').selectOption(from);
	await saved.getByLabel('Until').selectOption(until);
	await saved.getByLabel('Delay, days').fill(delay);
	await saved.getByRole('button', { name: 'Save policy' }).click();
	await expectToast(page, 'The update policy has been saved.');
});

test('admin: the update dialog loads the pre-flight of a release', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/updates');
	const release = page.locator('.ap-release:not(.is-withdrawn)').first();
	test.skip((await release.count()) === 0, 'no installable release newer than this server');

	await release.getByRole('button').click();
	const dialog = page.getByRole('dialog', { name: 'Update ArcPanel' });
	await expect(dialog).toBeVisible();
	await expect(dialog.locator('.ap-preflight li').first()).toBeVisible({ timeout: 60_000 });
	await expect(dialog.locator('.ap-preflight')).toContainText('Manifest');
	await dialog.getByRole('button', { name: 'Cancel' }).click();
	await expect(dialog).toBeHidden();
});

// Installs a release and rolls it back: only against the local signed
// repository of the end-to-end run (spec §12), where E2E_UPDATE_RELEASE names
// a patch release without upgrade steps that is flagged for attention and
// newer than the installed version.
test('admin: update to a release after confirming its items, then roll it back', async ({
	page,
}) => {
	const version = process.env.E2E_UPDATE_RELEASE;
	test.skip(!version, 'needs E2E_UPDATE_RELEASE and the local repository of the end-to-end run');
	test.setTimeout(900_000);
	await login(page, ADMIN);
	await page.goto('/server/updates');
	const status = page.getByRole('region', { name: 'Update status' });
	const installed = (await status.innerText()).match(/\d+\.\d+\.\d+/)[0];
	// The release is new: fetch the manifest and the package list
	await page.getByRole('button', { name: 'Check now' }).click();
	await expect(page.locator('.ap-toast')).toBeVisible();

	await page
		.locator('.ap-release', { hasText: version })
		.getByRole('button', { name: 'Review and update' })
		.click();
	const dialog = page.getByRole('dialog', { name: 'Update ArcPanel' });
	await expect(dialog.locator('.ap-preflight li').first()).toBeVisible({ timeout: 60_000 });
	const update = dialog.getByRole('button', { name: 'Update', exact: true });
	const items = dialog.locator('.ap-confirm-items input[type="checkbox"]');
	await expect(items.first()).toBeVisible();
	await expect(update).toBeDisabled();
	for (const item of await items.all()) {
		await item.check();
	}
	await expect(update).toBeEnabled();
	await update.click();
	await expect(page.getByText('The update has finished.')).toBeVisible({ timeout: 600_000 });
	// The update and the rollback restart the panel: reload until it answers
	// with the expected version
	const reloadUntil = (expected) =>
		expect
			.poll(
				async () => {
					await page.goto('/server/updates').catch(() => null);
					return page
						.getByRole('region', { name: 'Update status' })
						.innerText({ timeout: 5_000 })
						.catch(() => '');
				},
				{ timeout: 600_000, intervals: [5_000] },
			)
			.toContain(expected);
	await reloadUntil(version);

	await page.getByRole('button', { name: 'Roll back' }).click();
	await page
		.getByRole('dialog', { name: `Roll back to ${installed}?` })
		.getByRole('button', { name: `Roll back to ${installed}` })
		.click();
	await reloadUntil(installed);
	// The run is marked once the rollback has finished
	const newest = page.locator('table tbody tr').first();
	await expect(newest).toContainText(`${installed} → ${version}`);
	await expect
		.poll(
			async () => {
				await page.goto('/server/updates').catch(() => null);
				return newest.innerText({ timeout: 5_000 }).catch(() => '');
			},
			{ timeout: 300_000, intervals: [5_000] },
		)
		.toContain('Rolled back');
});

test('user: no updates in the menu, 403 on the page', async ({ page }) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Updates' }),
	).toHaveCount(0);
	const response = await page.goto('/server/updates');
	expect(response.status()).toBe(403);
});
