// Node.js manager (E13 spec §4 "Done when"): a user on the developer
// package enables Node.js, installs two runtimes, picks the default and adds
// global packages from the page; a user whose package has no Node.js sees
// no entry and cannot change anything. Downloads come from nodejs.org and
// the npm registry (cached on the server after the first run).
import { expect, test } from '@playwright/test';
import { DEV, expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ timeout: 900_000 });

/** The task panel shows the task as done. */
async function expectTaskDone(page, title) {
	const panel = page.locator('section', { has: page.getByRole('heading', { name: title }) });
	await expect(panel.locator('.ap-badge')).toHaveText(/Done|Failed/, { timeout: 600_000 });
	await expect(panel.locator('.ap-badge')).toHaveText('Done');
}

async function install(page, major) {
	await page.getByRole('button', { name: 'Install a version' }).click();
	const dialog = page.getByRole('dialog', { name: 'Install a version' });
	await expect(dialog.getByRole('radio').first()).toBeVisible();
	await dialog.getByRole('radio', { name: new RegExp(`^Node\\.js ${major} `) }).check();
	await expectAccessible(page);
	await dialog.getByRole('button', { name: 'Install', exact: true }).click();
	await expectTaskDone(page, `Install Node.js ${major}`);
	await expect(page.locator('tbody tr', { hasText: `v${major}.` })).toBeVisible();
}

test('a developer installs runtimes and global packages from the Node.js page', async ({
	page,
}) => {
	await login(page, DEV);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Node.js' })
		.click();
	await expect(page.getByRole('heading', { name: 'Node.js', level: 1 })).toBeVisible();
	await expectAccessible(page);

	await page.getByRole('button', { name: 'Enable Node.js' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(page.getByText('No runtime is installed yet.')).toBeVisible();

	await install(page, '22');
	await install(page, '24');

	const v24 = page.locator('tbody tr', { hasText: 'v24.' });
	const v22 = page.locator('tbody tr', { hasText: 'v22.' });
	await expect(v22).toContainText('Default');
	await v24.getByRole('button', { name: 'Make default' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(v24).toContainText('Default');
	await expect(v22).not.toContainText('Default');

	const version = (await v24.locator('.mono').first().textContent()).trim().replace(/^v/, '');
	await page.getByLabel('Select a runtime').selectOption(version);
	await page.getByRole('button', { name: 'yarn', exact: true }).click();
	await expectTaskDone(page, 'Install yarn');
	await page.getByLabel('Package name').fill('pm2');
	await page.getByRole('button', { name: 'Add', exact: true }).click();
	await expectTaskDone(page, 'Install pm2');

	const packages = page.locator('section', {
		has: page.getByRole('heading', { name: 'Global packages' }),
	});
	await expect(packages.locator('tbody tr', { hasText: 'yarn' })).toBeVisible();
	await expect(packages.locator('tbody tr', { hasText: 'pm2' })).toBeVisible();
	await expect(packages.locator('tbody tr', { hasText: 'npm' })).toContainText(
		'Comes with Node.js',
	);

	await page.getByLabel('Package name').fill('https://example.invalid/x.tgz');
	await page.getByRole('button', { name: 'Add', exact: true }).click();
	await expect(page.locator('#node-package-error')).toBeVisible();

	// The output of a task opens with it and can be folded
	await expect(page.getByRole('log')).toContainText('pm2');
	await page.getByRole('button', { name: 'Hide the output' }).click();
	await expect(page.getByRole('log')).toHaveCount(0);
	await page.getByRole('button', { name: 'Show the output' }).click();
	await expect(page.getByRole('log')).toContainText('added');
	await expectAccessible(page);

	// The page survives a reload: the server state is the source
	await page.reload();
	await expect(page.locator('tbody tr', { hasText: 'v24.' })).toContainText('Default');
});

test('a user without Node.js in the package has no entry and cannot change anything', async ({
	page,
}) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Node.js' }),
	).toHaveCount(0);

	await page.goto('/app/node');
	await expect(page).toHaveURL(new RegExp(`/app/node/${USER}$`));
	await expect(page.getByText('Node.js is not included in your package.').first()).toBeVisible();
	await expect(page.getByRole('button', { name: 'Enable Node.js' })).toHaveCount(0);
	await expectAccessible(page);

	const status = await page.evaluate(async (user) => {
		const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
		const response = await fetch(`/app/node/${user}/api/enable`, {
			method: 'POST',
			headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
		});
		return response.status;
	}, USER);
	expect(status).toBe(403);

	const other = await page.request.get(`/app/node/${DEV}`);
	expect(other.status()).toBe(404);
});
