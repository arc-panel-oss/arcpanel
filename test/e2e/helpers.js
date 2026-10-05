// Shared steps of the e2e specs.
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

export const ADMIN = 'arce2eadmin';
export const USER = 'arce2euser';
export const SITE = 'e2e-site.example';
export const RESELLER = 'arce2ereseller';
export const CUSTOMER = 'arce2ecustomer';
export const BASE_URL = process.env.E2E_BASE_URL ?? 'https://127.0.0.1:8083';

/** Sign in on /app/login and land on the dashboard. */
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
	await page.goto('/app/login');
	await page.fill('#user', user);
	await page.fill('#password', process.env.E2E_PASSWORD);
	await page.click('button[type=submit]');
	await expect(page).toHaveURL(/\/app\/$/);
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
