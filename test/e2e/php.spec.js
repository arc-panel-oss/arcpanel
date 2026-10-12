// PHP Manager (E41 spec 2026-10-12-001 §12): an administrator installs a PHP
// version and an extension through the task log, changes memory_limit
// through the form and is refused a syntax error in the editor, creates a
// PHP-FPM profile and allows it in the user's package; the user picks it
// for a web domain. php-clean puts everything back.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, SITE, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
	runOnServer(['php']);
});

test.afterAll(() => {
	runOnServer(['php-clean']);
});

/** Wait for the system task of the page to end and for the page to reload with the new state. */
async function waitForTask(page) {
	await expect(page.locator('.ap-task-last')).toContainText('Done', { timeout: 400_000 });
	await page.waitForEvent('load', { timeout: 10_000 }).catch(() => {});
}

async function confirm(page) {
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
}

test('admin: the PHP page lists the catalog and installs a version through the task log', async ({
	page,
}) => {
	test.setTimeout(500_000);
	await login(page, ADMIN);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'PHP', exact: true })
		.click();
	await expect(page).toHaveURL(/\/server\/php$/);
	// versions that can no longer be installed are named under the table only
	await expect(page.locator('[data-php-version="7.3"]')).toHaveCount(0);
	await expect(page.getByText(/Not shown: PHP .*7\.3/)).toBeVisible();
	await expect(page.locator('[data-php-version="8.4"]')).toContainText('Installed');
	await expectAccessible(page);

	const row = page.locator('[data-php-version="8.3"]');
	test.skip(
		(await row.getByRole('button', { name: 'Install' }).count()) === 0,
		'PHP 8.3 is installed on this server',
	);
	await row.getByRole('button', { name: 'Install' }).click();
	await confirm(page);
	await expectToast(page, 'PHP 8.3 is being installed.');
	await expect(page.getByRole('log', { name: 'Task output' })).toContainText('$ v-add-web-php 8.3');
	await waitForTask(page);
	await expect(page.locator('[data-php-version="8.3"]')).toContainText('Installed');
});

test('admin: install an extension, turn it off for the command line, remove it', async ({
	page,
}) => {
	test.setTimeout(500_000);
	await login(page, ADMIN);
	await page.goto('/server/php/8.4/extensions');
	await expectAccessible(page);
	// the filter keeps the matching extensions and their groups only
	await page.getByRole('searchbox', { name: 'Filter extensions' }).fill('uuid');
	await expect(page.locator('li.ap-php-extension:visible')).toHaveCount(1);
	const uuid = page.locator('[data-extension="uuid"]');
	await uuid.getByRole('button', { name: 'Install' }).click();
	await confirm(page);
	await expectToast(page, 'uuid is being installed.');
	await waitForTask(page);
	await expect(page.locator('[data-extension="uuid"]')).toContainText('Installed');

	const cli = page.getByRole('checkbox', { name: 'uuid for CLI' });
	await expect(cli).toBeChecked();
	await cli.uncheck();
	await expectToast(page, 'Changes have been saved.');
	await page.reload();
	await expect(page.getByRole('checkbox', { name: 'uuid for CLI' })).not.toBeChecked();
	await expect(page.getByRole('checkbox', { name: 'uuid for PHP-FPM' })).toBeChecked();

	await page.locator('[data-extension="uuid"]').getByRole('button', { name: 'Remove' }).click();
	await confirm(page);
	await expectToast(page, 'uuid is being removed.');
	await waitForTask(page);
	await expect(
		page.locator('[data-extension="uuid"]').getByRole('button', { name: 'Install' }),
	).toBeVisible();
});

test('admin: change memory_limit through the form; the editor refuses an invalid value', async ({
	page,
}) => {
	await login(page, ADMIN);
	await page.goto('/server/php/8.4/ini-fpm');
	await expectAccessible(page);
	await page.getByLabel('Memory limit', { exact: true }).fill('192M');
	await page.getByRole('button', { name: 'Save', exact: true }).first().click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByLabel('Memory limit', { exact: true })).toHaveValue('192M');
	await page.getByRole('button', { name: /Backups/ }).click();
	await expect(
		page.getByRole('dialog', { name: 'Backups' }).getByRole('button', { name: 'Restore' }).first(),
	).toBeVisible();
	await page
		.getByRole('dialog', { name: 'Backups' })
		.getByRole('button', { name: 'Close' })
		.click();
	await expect(page.getByRole('dialog', { name: 'Backups' })).toBeHidden();

	const content = page.locator('.ap-php-ini-editor .cm-content');
	await content.click();
	await page.keyboard.press('Control+End');
	// a value check of §6.3 (the editor closes brackets and quotes by itself)
	await page.keyboard.type('\nmemory_limit = lots');
	await page.getByRole('button', { name: 'Validate' }).click();
	await expect(
		page.locator('.ap-ini-finding.is-error', { hasText: 'memory_limit must be a size' }),
	).toBeVisible();
	await expect(page.locator('.ap-ini-marker.is-error')).toBeVisible();
	await page.locator('.ap-php-ini-editor').getByRole('button', { name: 'Save' }).click();
	await expect(page.locator('.ap-php-ini-editor').getByRole('alert')).toContainText(
		'The php.ini has errors and was not saved.',
	);
});

test('admin: create a profile and allow it in the default package', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/php/profiles');
	await expectAccessible(page);
	await page.getByRole('link', { name: 'Add profile' }).click();
	await page.locator('input[name="name"]').fill('arce2e');
	await page.locator('input[name="description"]').fill('E2E profile');
	await page.locator('select[name="pm"]').selectOption('static');
	await expect(page.locator('input[name="idle_timeout"]')).toBeHidden();
	await page.locator('input[name="max_children"]').fill('3');
	await page.locator('input[name="max_requests"]').fill('200');
	await page.locator('input[name="memory_limit"]').fill('160M');
	await expectAccessible(page);
	await page.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Profile arce2e has been created.');
	await expect(page.locator('[data-profile="arce2e"]')).toContainText('160M');

	await page.goto('/packages/default/edit');
	await page.getByRole('checkbox', { name: 'arce2e', exact: true }).check();
	await page.getByRole('button', { name: 'Save' }).last().click();
	await expectToast(page, 'Changes have been saved.');
});

test('user: pick the profile for a web domain and see its limits', async ({ page }) => {
	await login(page, USER);
	await page.goto(`/web/domains/${USER}/${SITE}/edit`);
	await page.getByRole('tab', { name: 'PHP' }).click();
	await expectAccessible(page);
	await page.locator('label.ap-pick', { hasText: 'arce2e' }).click();
	await page.getByRole('tabpanel', { name: 'PHP' }).getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await page.getByRole('tab', { name: 'PHP' }).click();
	await expect(page.getByRole('tabpanel', { name: 'PHP' })).toContainText('160M');
	await expect(page.locator('label.ap-pick', { hasText: 'arce2e' }).locator('input')).toBeChecked();
});

test('user: no PHP page in the menu, 403 on the server pages', async ({ page }) => {
	await login(page, USER);
	await expect(
		page
			.getByRole('navigation', { name: 'Sections' })
			.getByRole('link', { name: 'PHP', exact: true }),
	).toHaveCount(0);
	expect((await page.goto('/server/php')).status()).toBe(403);
	expect((await page.goto('/server/php/profiles')).status()).toBe(403);
});

test('admin: remove the version the spec installed', async ({ page }) => {
	test.setTimeout(500_000);
	await login(page, ADMIN);
	await page.goto('/server/php');
	const remove = page.locator('[data-php-version="8.3"]').getByRole('button', { name: 'Remove' });
	test.skip(
		(await remove.count()) === 0 || (await remove.isDisabled()),
		'PHP 8.3 stays: it was there before or is in use',
	);
	await remove.click();
	await confirm(page);
	await expectToast(page, 'PHP 8.3 is being removed.');
	await waitForTask(page);
	await expect(page.locator('[data-php-version="8.3"]')).not.toContainText('Installed');
});
