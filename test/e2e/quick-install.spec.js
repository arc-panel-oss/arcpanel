// Web terminal and Quick Install (E7 batch 2 spec §10): a user installs
// DokuWiki on a fresh domain, watches the task and opens the wiki. DokuWiki
// is downloaded from dokuwiki.org and needs PHP 8.0–8.4 on the server.
import { expect, test } from '@playwright/test';
import { expectAccessible, expectToast, login, openSection, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial', timeout: 600_000 });

const DOMAIN = 'e2e-wiki.example';

test.afterAll(() => {
	runOnServer(['quickinstall-clean']);
	runOnServer(['terminal-off']);
});

test('user: install DokuWiki from the domain page', async ({ page }) => {
	runOnServer(['quickinstall']);
	await login(page, USER);
	await page.goto(`/web/domains/${USER}/${DOMAIN}/edit`);
	await page.getByRole('link', { name: 'Quick Install App' }).click();
	await expect(page).toHaveURL(new RegExp(`/web/domains/${USER}/${DOMAIN}/install$`));
	await expectAccessible(page);

	await page.getByRole('link', { name: /DokuWiki/ }).click();
	await expect(page.getByRole('heading', { name: 'Install DokuWiki' })).toBeVisible();
	await expect(page.getByText('Data Loss Warning!')).toHaveCount(0);
	await expectAccessible(page);
	await page.getByRole('textbox', { name: 'Wiki Name' }).fill('E2E Wiki');
	await page.getByRole('textbox', { name: 'Superuser' }).fill('admin');
	await page.getByRole('textbox', { name: 'Real Name' }).fill('E2E Admin');
	await page.getByRole('textbox', { name: 'Email' }).fill('admin@example.invalid');
	await page.locator('#qi-password').fill(`E2e-${Date.now()}-Wiki!`);
	await page.getByRole('button', { name: 'Install DokuWiki' }).click();

	await expectToast(page, 'Installing DokuWiki. This can take a few minutes.');
	await expect(page.getByRole('button', { name: 'Install DokuWiki' })).toBeDisabled();
	await expect(page.locator('.ap-task-log')).toContainText('v-add-web-domain-app');
	await expect(page.locator('[data-toast-region]')).toContainText(
		'DokuWiki installed successfully.',
		{
			timeout: 300_000,
		},
	);
	await expect(page.locator('.ap-task-log')).toContainText('DokuWiki installed successfully.');
	await expect(page.getByRole('link', { name: `Open ${DOMAIN}` })).toBeVisible();

	expect(runOnServer(['quickinstall-http'])).toContain('E2E Wiki');
});

test('user: the web terminal page loads xterm and reaches for the shell', async ({ page }) => {
	runOnServer(['terminal-on']);
	await login(page, USER);
	await openSection(page, 'Web terminal', 'Files', 'Terminal');
	await expect(page).toHaveURL(/\/terminal$/);
	// xterm draws its screen; this server has no terminal service, so the
	// WebSocket fails with the legacy message
	await expect(page.locator('.ap-terminal-screen .xterm')).toBeVisible();
	// xterm draws on a canvas: read its buffer through the Alpine component
	const firstLine = () =>
		page.evaluate(() => {
			const terminal = window.Alpine.$data(document.querySelector('.ap-terminal')).terminal;
			return terminal?.buffer.active.getLine(0)?.translateToString(true) ?? '';
		});
	await expect
		.poll(firstLine, { timeout: 30_000 })
		.toMatch(/Connection error\.|Connection closed\./);
	expect(await page.locator('#ap-xterm-css').count()).toBe(1);
});
