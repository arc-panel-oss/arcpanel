// System packages and runtime badges (E5 spec 2026-10-06-006 §7, §8): an
// administrator installs the catalog entry arctest-e2e (GNU hello, provides
// ruby) as the system task, sees the Rails template's Ruby badge turn from
// red to green in the web domain form, and removes the entry again.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, openSection, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
	runOnServer(['syspackages']);
});

test.afterAll(() => {
	runOnServer(['syspackages-clean']);
});

/** The card of the visitor-facing template picker (proxy on NGINX + Apache, web on NGINX + PHP-FPM). */
async function railsCard(page) {
	await page.goto(`/web/domains/create?owner=${USER}`);
	await page.getByText('Advanced Options').click();
	const proxy = page.getByRole('group', { name: 'Proxy Template' });
	const picker =
		(await proxy.count()) > 0 ? proxy : page.getByRole('group', { name: 'Web Template' });
	return { picker, card: picker.locator('label.ap-pick', { hasText: 'Ruby on Rails app (Puma)' }) };
}

/** Wait for the system task of the packages page to end. */
async function waitForTask(page) {
	await expect(page.locator('.ap-task-last')).toContainText('Done', { timeout: 180_000 });
}

test('admin: the Ruby badge is red while no Ruby runtime is installed', async ({ page }) => {
	await login(page, ADMIN);
	const { picker, card } = await railsCard(page);
	const badge = card.locator('.ap-badge');
	test.skip(
		(await badge.getAttribute('class')).includes('is-ok'),
		'Ruby is installed on this server',
	);
	await expect(badge).toHaveClass(/is-danger/);
	await card.click();
	await expect(picker.getByText('Ruby is not installed on this server.')).toBeVisible();
	await expect(
		picker.getByRole('link', { name: 'Install it on the System packages page.' }),
	).toHaveAttribute('href', /\/server\/packages$/);
});

test('admin: install the entry as the system task and see the badge turn green', async ({
	page,
}) => {
	test.setTimeout(300_000);
	await login(page, ADMIN);
	await openSection(page, 'System packages', 'Updates', 'System packages');
	await expect(page).toHaveURL(/\/server\/packages$/);
	const entry = page.locator('[data-package="arctest-e2e"]');
	await expect(entry).toContainText('Not installed');
	await expect(entry).toContainText('Provides: Ruby');
	await expectAccessible(page);

	await entry.getByRole('button', { name: 'Install' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'E2E hello is being installed.');
	await expect(page.getByRole('log', { name: 'Task output' })).toContainText(
		'$ v-add-sys-package arctest-e2e',
	);
	await waitForTask(page);
	await expect(page.locator('[data-package="arctest-e2e"]')).toContainText('Installed');

	const { card } = await railsCard(page);
	await expect(card.locator('.ap-badge')).toHaveClass(/is-ok/);
});

test('admin: remove the entry again', async ({ page }) => {
	test.setTimeout(300_000);
	await login(page, ADMIN);
	await page.goto('/server/packages');
	const entry = page.locator('[data-package="arctest-e2e"]');
	await entry.getByRole('button', { name: 'Remove' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'E2E hello is being removed.');
	await waitForTask(page);
	await expect(page.locator('[data-package="arctest-e2e"]')).toContainText('Not installed');
});

test('user: no system packages in the menu, 403 on the page', async ({ page }) => {
	await login(page, USER);
	await expect(
		page
			.getByRole('navigation', { name: 'Sections' })
			.getByRole('link', { name: 'System packages' }),
	).toHaveCount(0);
	const response = await page.goto('/server/packages');
	expect(response.status()).toBe(403);
});
