// axe on every panel app page for both roles and both themes, and Lighthouse
// accessibility ≥ 90 on the dashboard, Web Domains, Users, Packages and Ports
// pages (E1-S2 spec §4 "Done when", docs/design/05 §6, E12 spec §10) and
// the Node.js page (E13 spec §10).
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { chromium, expect, test } from '@playwright/test';
import lighthouse from 'lighthouse';
import {
	ADMIN,
	BASE_URL,
	CUSTOMER,
	DEV,
	expectAccessible,
	login,
	RESELLER,
	SITE,
	USER,
} from './helpers.js';

const pages = {
	[USER]: [
		'/app/',
		'/app/web/domains',
		'/app/web/domains/create',
		`/app/web/domains/${USER}/${SITE}/edit`,
		`/app/web/domains/${USER}/${SITE}/logs/access`,
		'/app/ports',
		`/app/node/${USER}`,
	],
	[DEV]: [`/app/node/${DEV}`],
	[ADMIN]: [
		'/app/',
		'/app/web/domains',
		'/app/web/domains/create',
		`/app/web/domains/${USER}/${SITE}/edit`,
		'/app/_styleguide',
		'/app/users',
		`/app/users/${RESELLER}`,
		`/app/users/${CUSTOMER}/edit`,
		'/app/packages',
		'/app/packages/default/edit',
		'/app/server/ports',
		`/app/node/${DEV}`,
	],
	[RESELLER]: [
		'/app/',
		'/app/users',
		'/app/users/create',
		`/app/users/${CUSTOMER}`,
		'/app/packages',
		'/app/account',
		'/app/ports',
	],
};

for (const theme of ['nocturne', 'light']) {
	for (const [user, paths] of Object.entries(pages)) {
		test(`axe: ${user} pages in ${theme}`, async ({ page }) => {
			await login(page, user, theme);
			for (const url of paths) {
				await page.goto(url, { waitUntil: 'networkidle' });
				await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
				await expectAccessible(page);
			}
		});
	}
}

test('axe: login page', async ({ page }) => {
	await page.goto('/app/login');
	await expectAccessible(page);
});

test('Lighthouse accessibility is at least 90 for both roles and themes', async () => {
	test.setTimeout(900_000);
	const port = 9300 + Math.floor(Math.random() * 500);
	const profile = mkdtempSync(path.join(tmpdir(), 'arc-lighthouse-'));
	const context = await chromium.launchPersistentContext(profile, {
		headless: true,
		ignoreHTTPSErrors: true,
		args: [`--remote-debugging-port=${port}`, '--ignore-certificate-errors'],
	});
	const scores = [];
	try {
		const page = context.pages()[0] ?? (await context.newPage());
		for (const theme of ['nocturne', 'light']) {
			for (const [user, paths] of Object.entries(pages)) {
				await login(page, user, theme);
				for (const url of paths.filter(
					(p) => !p.includes('_styleguide') && !p.includes('/logs/'),
				)) {
					const result = await lighthouse(`${BASE_URL}${url}`, {
						port,
						onlyCategories: ['accessibility'],
						disableStorageReset: true,
						output: 'json',
						logLevel: 'error',
						formFactor: 'desktop',
						screenEmulation: { disabled: true },
					});
					const score = Math.round((result.lhr.categories.accessibility.score ?? 0) * 100);
					const failed = Object.values(result.lhr.audits)
						.filter((a) => a.score === 0 && a.scoreDisplayMode === 'binary')
						.map((a) => a.id);
					scores.push({ theme, user, url, score, failed });
				}
			}
		}
	} finally {
		await context.close();
		rmSync(profile, { recursive: true, force: true });
	}
	console.table(scores.map(({ failed, ...row }) => ({ ...row, failed: failed.join(', ') })));
	expect(scores.filter((s) => s.score < 90)).toEqual([]);
});
