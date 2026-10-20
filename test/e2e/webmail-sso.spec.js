// Webmail and database tools ports and the webmail sign-on (E46,
// docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md §9).
// Needs Roundcube and phpMyAdmin on the server and the ports 8086 and 8087
// on (v-add-sys-tool-ports).
import { expect, test } from '@playwright/test';
import { BASE_URL, expectAccessible, login, USER } from './helpers.js';

const DOMAIN = 'e2e-mail.example';
const ADDRESS = `info@${DOMAIN}`;
const host = new URL(BASE_URL).hostname;

test('user: Open Webmail uses the webmail port, the sign-on opens the inbox', async ({ page }) => {
	await login(page, USER);
	await page.goto(`/mail/${USER}/${DOMAIN}/accounts/info/edit`);
	await expectAccessible(page);

	const open = page.getByRole('link', { name: 'Open Webmail', exact: true });
	await expect(open).toHaveAttribute(
		'href',
		`https://${host}:8086/?_user=${encodeURIComponent(ADDRESS)}`,
	);

	const popup = page.waitForEvent('popup');
	await page.getByRole('button', { name: 'Open Webmail (signed in)' }).click();
	const webmail = await popup;
	await webmail.waitForURL(/:8086\/\?_task=mail/);
	await expect(webmail.getByText(ADDRESS).first()).toBeVisible();
	await webmail.close();
});

test('user: phpMyAdmin opens on the database tools port', async ({ page, request }) => {
	await login(page, USER);
	await page.goto('/db');
	const pma = page.getByRole('link', { name: 'phpMyAdmin', exact: true }).first();
	await expect(pma).toHaveAttribute('href', `//${host}:8087/phpmyadmin/`);
	const answer = await request.get(`https://${host}:8087/phpmyadmin/`, { ignoreHTTPSErrors: true });
	expect(answer.status()).toBe(200);
});
