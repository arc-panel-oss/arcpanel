// The shell: command palette, theme switch, notifications.
import { expect, test } from '@playwright/test';
import { ADMIN, login, SITE, USER } from './helpers.js';

test('the command palette finds objects and pages from the keyboard', async ({ page }) => {
	await login(page, ADMIN);
	await page.keyboard.press('Control+k');
	const dialog = page.getByRole('dialog', { name: 'Search' });
	await expect(dialog).toBeVisible();
	await page.keyboard.type('e2e-site');
	await expect(dialog.getByRole('option', { name: new RegExp(SITE) })).toBeVisible();
	await page.keyboard.press('Enter');
	await expect(page).toHaveURL(new RegExp(`/web/domains/${USER}/${SITE}/edit`));

	await page.keyboard.press('Control+k');
	// The edit page has just loaded: type only once the search field has focus.
	await expect(dialog.getByRole('combobox')).toBeFocused();
	await page.keyboard.type('dash');
	await expect(dialog.getByRole('option', { name: /Dashboard/ })).toBeVisible();
	await page.keyboard.press('Escape');
	await expect(dialog).toBeHidden();
});

test('the theme switch applies at once and is kept in the arc_ui cookie', async ({
	page,
	context,
}) => {
	await login(page, USER, 'nocturne');
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'nocturne');

	await page.getByRole('button', { name: new RegExp(`${USER}.*Account menu`) }).click();
	await page.getByRole('button', { name: 'Light' }).click();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
	await page.getByRole('button', { name: 'Compact' }).click();
	await expect(page.locator('html')).toHaveAttribute('data-density', 'compact');

	await page.reload();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
	const cookie = (await context.cookies()).find((c) => c.name === 'arc_ui');
	expect(JSON.parse(decodeURIComponent(cookie.value))).toEqual({
		theme: 'light',
		density: 'compact',
	});
});

test('the notification tray opens and closes', async ({ page }) => {
	await login(page, USER);
	await page.getByRole('button', { name: /^Notifications/ }).click();
	await expect(page.locator('#ap-tray-panel')).toBeVisible();
	await page.keyboard.press('Escape');
	await expect(page.locator('#ap-tray-panel')).toBeHidden();
});

test('old bookmarks lead to the panel pages', async ({ page }) => {
	await login(page, USER);
	// the /app prefix of 1.x
	await page.goto('/app/db');
	await expect(page).toHaveURL(/\/db$/);
	await expect(page.getByRole('heading', { level: 1 })).toContainText('Databases');
	// the legacy page paths
	await page.goto('/list/web/');
	await expect(page).toHaveURL(/\/web\/domains$/);
	await page.goto(`/edit/web/?domain=${SITE}`);
	await expect(page).toHaveURL(new RegExp(`/web/domains/${USER}/${SITE}/edit`));
});
