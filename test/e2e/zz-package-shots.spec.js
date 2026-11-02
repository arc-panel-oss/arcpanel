// Temporary: screenshots of the package form for the UI review (not committed).
import { test } from '@playwright/test';
import { ADMIN, confirmPassword, login } from './helpers.js';

const OUT = process.env.SHOTS_DIR;

async function open(page, url) {
	await page.goto(url);
	if (page.url().endsWith('/sudo')) {
		await confirmPassword(page);
	}
}

test('package form screenshots', async ({ page }) => {
	await login(page, ADMIN);
	const names = await page.evaluate(async () => {
		const html = await (await fetch('/packages')).text();
		return [...html.matchAll(/\/packages\/([^/"]+)\/edit/g)].map((m) => m[1]);
	});
	const target = names.includes('default') ? 'default' : names[0];
	for (const [label, size] of [
		['desktop', { width: 1440, height: 900 }],
		['mobile', { width: 375, height: 812 }],
	]) {
		await page.setViewportSize(size);
		await open(page, `/packages/${target}/edit`);
		await page.screenshot({ path: `${OUT}/edit-${label}-full.png`, fullPage: true });
		await page.screenshot({ path: `${OUT}/edit-${label}-top.png` });
		await page.getByLabel('Web Domains').fill('9');
		await page.locator('#package-docker').scrollIntoViewIfNeeded();
		await page.screenshot({ path: `${OUT}/edit-${label}-dirty.png` });
		await page.reload();
	}
	await page.setViewportSize({ width: 1440, height: 900 });
	await open(page, '/packages/create');
	await page.screenshot({ path: `${OUT}/create-desktop-full.png`, fullPage: true });
});
