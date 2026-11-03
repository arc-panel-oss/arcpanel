// The package form: sections with in-page links, the features behind their
// switches, limits with units posted as one value and the save bar that
// tells about unsaved changes.
import { expect, test } from '@playwright/test';
import { ADMIN, confirmPassword, expectAccessible, expectToast, login } from './helpers.js';

const PACKAGE = 'arce2epkg';

/** Open a page that may ask for the password first. */
async function open(page, url) {
	await page.goto(url);
	if (page.url().endsWith('/sudo')) {
		await confirmPassword(page);
	}
}

test.describe.serial('package form', () => {
	test.beforeEach(async ({ page }) => {
		await login(page, ADMIN);
	});

	test('adds a package with a feature turned on and a limit in GB', async ({ page }) => {
		await open(page, '/packages/create');
		await expect(page.getByRole('navigation', { name: 'Package sections' })).toBeVisible();
		await expectAccessible(page);

		await page.getByLabel('Package Name').fill(PACKAGE);
		const docker = page.locator('#package-docker-settings');
		await expect(docker).toHaveAttribute('inert', /.*/);
		await page.getByRole('switch', { name: 'Docker apps' }).check();
		await expect(docker).not.toHaveAttribute('inert', /.*/);

		await page.getByLabel('Memory per app').fill('2');
		await page.getByLabel('Unit of Memory per app').selectOption('G');
		await expect(page.locator('input[type=hidden][name=docker_memory]')).toHaveValue('2G');
		await expect(page.locator('[data-form-bar]')).toContainText('Unsaved changes');

		await page.locator('[data-form-bar]').getByRole('button', { name: 'Add Package' }).click();
		await expect(page).toHaveURL(/\/packages$/);
		await expectToast(page, 'Package has been created.');
	});

	test('edits the package, says who uses it and deletes it', async ({ page }) => {
		await open(page, `/packages/${PACKAGE}/edit`);
		await expect(page.getByText('No user has this package yet.')).toBeVisible();
		await expect(page.locator('input[type=hidden][name=docker_memory]')).toHaveValue('2G');
		await expect(page.locator('#package-docker-settings')).not.toHaveAttribute('inert', /.*/);
		await expect(page.locator('#package-node-settings')).toHaveAttribute('inert', /.*/);
		await expect(page.locator('[data-form-bar]')).not.toContainText('Unsaved changes');
		await expectAccessible(page);

		await page.getByLabel('Web Domains').fill('7');
		await expect(page.locator('[data-form-bar]')).toContainText('Unsaved changes');
		await page
			.getByLabel('Web Domains')
			.fill(await page.getByLabel('Web Domains').getAttribute('value'));
		await expect(page.locator('[data-form-bar]')).not.toContainText('Unsaved changes');

		await page.getByLabel('Web Domains').fill('7');
		await page.locator('[data-form-bar]').getByRole('button', { name: 'Save' }).click();
		await expectToast(page, 'Changes have been saved.');
		await expect(page.getByLabel('Web Domains')).toHaveValue('7');

		await page.getByRole('button', { name: 'Delete' }).click();
		await page
			.getByRole('alertdialog')
			.getByRole('button', { name: /Confirm|Delete/ })
			.click();
		await expect(page).toHaveURL(/\/packages$/);
		await expectToast(page, 'Package has been deleted.');
	});

	test('fits a phone screen', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await open(page, '/packages/create');
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth,
		);
		expect(overflow).toBeLessThanOrEqual(0);
		await expect(
			page.locator('[data-form-bar]').getByRole('button', { name: 'Add Package' }),
		).toBeInViewport();
	});
});
