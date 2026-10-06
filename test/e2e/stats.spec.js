// Happy paths of the Statistics and Task Monitor pages (E7 batch 2 spec §9.4, §9.5).
import { expect, test } from '@playwright/test';
import { ADMIN, CUSTOMER, expectAccessible, login, RESELLER, USER } from './helpers.js';

test('user: statistics of the own account', async ({ page }) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Statistics' })
		.click();
	await expect(page).toHaveURL(/\/app\/stats$/);
	await expect(page.getByText(`Usage of ${USER}, per month.`)).toBeVisible();
	await expectAccessible(page);
	await expect(page.getByRole('combobox', { name: 'Owner' })).toHaveCount(0);
});

test('reseller: totals of the tree, then one customer', async ({ page }) => {
	await login(page, RESELLER);
	await page.goto('/app/stats');
	await expect(
		page.getByText('Totals of your account and your customers, per month.'),
	).toBeVisible();
	await page.getByRole('combobox', { name: 'Owner' }).selectOption(CUSTOMER);
	await page.getByRole('button', { name: 'Show statistics' }).click();
	await expect(page).toHaveURL(new RegExp(`/app/stats\\?owner=${CUSTOMER}$`));
	await expect(page.getByText(`Usage of ${CUSTOMER}, per month.`)).toBeVisible();
	const response = await page.goto(`/app/stats?owner=${USER}`);
	expect(response.status()).toBe(404);
});

test('admin: server totals and the Task Monitor graphs', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/app/stats');
	await expect(page.getByText('Totals of every account on this server, per month.')).toBeVisible();
	await expectAccessible(page);

	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Task Monitor' })
		.click();
	await expect(page).toHaveURL(/\/app\/server\/rrd$/);
	await expect(page.getByRole('heading', { name: 'Load Average' })).toBeVisible();
	// The graph draws once its data arrived
	await expect(page.locator('.ap-rrd canvas').first()).toHaveAttribute('style', /height/);
	await expectAccessible(page);

	await page.getByRole('link', { name: 'Weekly' }).first().click();
	await expect(page).toHaveURL(/\/app\/server\/rrd\?period=weekly$/);
	await expect(page.getByRole('link', { name: 'Weekly' }).first()).toHaveAttribute(
		'aria-current',
		'page',
	);
});
