// Shared steps of the e2e specs.
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

export const ADMIN = 'arce2eadmin';
export const USER = 'arce2euser';
export const SITE = 'e2e-site.example';
export const RESELLER = 'arce2ereseller';
export const CUSTOMER = 'arce2ecustomer';
/** On the developer package, with Node.js (E13) */
export const DEV = 'arce2edev';
export const BASE_URL = process.env.E2E_BASE_URL ?? 'https://127.0.0.1:8083';

/** Sign in on /login and land on the dashboard. */
export async function login(page, user, theme = 'nocturne') {
	// Leave the current page first: a request still running there could set
	// the previous session cookie again after it was cleared.
	await page.goto('about:blank');
	await page.context().clearCookies();
	await page.context().addCookies([
		{
			name: 'arc_ui',
			value: encodeURIComponent(JSON.stringify({ theme, density: 'comfortable' })),
			url: BASE_URL,
		},
	]);
	await page.goto('/login');
	await page.fill('#user', user);
	await page.fill('#password', process.env.E2E_PASSWORD);
	await page.click('button[type=submit]');
	await expect(page).toHaveURL(/^https?:\/\/[^/]+\/$/);
}

/**
 * Open a page from the sidebar. A page in a hub (sidebar hubs spec) is a tab
 * of the hub when the hub shows two or more pages, and its own sidebar item
 * otherwise; `hub` and `tab` name the hub item and the tab.
 */
export async function openSection(page, item, hub, tab) {
	const sections = page.getByRole('navigation', { name: 'Sections' });
	const direct = sections.getByRole('link', { name: item, exact: true });
	if (!hub || (await direct.count()) > 0) {
		await direct.click();
		return;
	}
	await sections.getByRole('link', { name: hub, exact: true }).click();
	const link = page
		.getByRole('navigation', { name: hub })
		.getByRole('link', { name: tab, exact: true });
	if ((await link.getAttribute('aria-current')) !== 'page') {
		await link.click();
	}
	await expect(link).toHaveAttribute('aria-current', 'page');
}

/**
 * Sudo mode (E39 W2 spec §7): confirm the password when the panel asks for
 * it before a sensitive change, and land back on the form.
 */
export async function confirmPassword(page) {
	await expect(page).toHaveURL(/\/sudo$/);
	await expectAccessible(page);
	await page.getByLabel('Password').fill(process.env.E2E_PASSWORD);
	await page.getByRole('button', { name: 'Confirm' }).click();
	await expect(page).not.toHaveURL(/\/sudo$/);
}

/** No axe violations (WCAG 2.1 A and AA) on the current page. */
export async function expectAccessible(page) {
	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.analyze();
	const summary = results.violations.map(
		(v) =>
			`${v.id} (${v.impact}): ${v.help}\n  ${v.nodes
				.map((n) => n.target.join(' '))
				.slice(0, 5)
				.join('\n  ')}`,
	);
	expect(summary, `axe violations on ${page.url()}`).toEqual([]);
}

/** The toast region shows a message. */
export async function expectToast(page, text) {
	await expect(page.locator('[data-toast-region]')).toContainText(text);
}
