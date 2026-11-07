// The Doctor pages (E17, docs/features/specs/2026-11-05-003-e17-doctor-design.md
// §5, §8): a domain whose alias is missing from its certificate shows
// web.ssl-aliases failing with Fix (the confirm dialog names the command,
// which is not run: it would ask Let's Encrypt), Run now follows the
// doctor.run job, the web domain page has a Doctor tab, the administrator
// sees the server checks, and a reseller reaches neither the server page
// nor a domain outside its tree.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, expectToast, login, RESELLER, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

const DOMAIN = 'e2e-doctor.example';

test.beforeAll(() => {
	runOnServer(['doctor']);
});

test.afterAll(() => {
	runOnServer(['doctor-clean']);
});

test('user: the Doctor lists the domain and its failing alias with Fix', async ({ page }) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Doctor', exact: true })
		.click();
	await expect(page).toHaveURL(new RegExp(`/doctor/${USER}$`));
	await expect(page.locator(`tr[data-doctor-domain="${DOMAIN}"]`)).toContainText('web.ssl-aliases');
	await expectAccessible(page);

	await page.getByRole('link', { name: DOMAIN, exact: true }).click();
	await expect(page).toHaveURL(new RegExp(`/doctor/${USER}/${DOMAIN}$`));
	const check = page.locator('[data-doctor-check="web.ssl-aliases"]');
	await expect(check).toHaveAttribute('data-status', 'fail');
	await expect(check).toContainText(`www.${DOMAIN} is not in the certificate.`);
	await check.getByRole('button', { name: /Fix/ }).click();
	const dialog = page.getByRole('alertdialog');
	await expect(dialog).toContainText(
		`Run v-add-letsencrypt-domain ${USER} ${DOMAIN} www.${DOMAIN}?`,
	);
	await dialog.getByRole('button', { name: 'Cancel' }).click();
	await expect(dialog).toBeHidden();
	await expectAccessible(page);
});

test('user: the Docs links of the checks line up whatever actions a row has', async ({ page }) => {
	await login(page, USER);
	await page.goto(`/doctor/${USER}/${DOMAIN}`);
	const docs = page.locator('[data-doctor-check]').getByRole('link', { name: /^Docs/ });
	await expect(docs.first()).toBeVisible();
	const lefts = new Set();
	for (const box of await docs.evaluateAll((links) =>
		links.map((a) => Math.round(a.getBoundingClientRect().left)),
	)) {
		lefts.add(box);
	}
	expect([...lefts]).toHaveLength(1);
});

test('user: Run now follows the doctor.run job and shows the new result', async ({ page }) => {
	test.setTimeout(120_000);
	await login(page, USER);
	await page.goto(`/doctor/${USER}/${DOMAIN}`);
	await page.getByRole('button', { name: 'Run now' }).click();
	await expectToast(page, `The Doctor checks ${DOMAIN}.`);
	await expect(page).toHaveURL(/\?job=\d{14}$/);
	// The job follows in the task panel; the DNS checks wait for the
	// TEST-NET resolver to time out
	await expect(page.getByText('Running', { exact: true })).toBeVisible();
	await expect(page.locator('[data-toast-region]')).toContainText('The Doctor has finished.', {
		timeout: 90_000,
	});
	await page.waitForEvent('load', { timeout: 15_000 }).catch(() => {});
	await expect(page.locator('[data-doctor-check="web.ssl-aliases"]')).toHaveAttribute(
		'data-status',
		'fail',
	);
});

test('user: the web domain page has a Doctor tab', async ({ page }) => {
	await login(page, USER);
	await page.goto(`/web/domains/${USER}/${DOMAIN}/edit`);
	await page.getByRole('tab', { name: 'Doctor' }).click();
	await expect(page.locator('[data-doctor-check="web.ssl-aliases"]')).toBeVisible();
	await expect(page.getByRole('link', { name: /Doctor page/ })).toHaveAttribute(
		'href',
		new RegExp(`/doctor/${USER}/${DOMAIN}$`),
	);
});

test('admin: Server › Doctor shows the server checks and the domain', async ({ page }) => {
	await login(page, ADMIN);
	await page.goto('/server/doctor');
	await expect(page.getByRole('heading', { name: 'Server Doctor' })).toBeVisible();
	await expect(page.locator('[data-doctor-check="server.firewall"]')).toBeVisible();
	await expect(page.locator(`tr[data-doctor-domain="${DOMAIN}"]`)).toBeVisible();
	await expectAccessible(page);
});

test('reseller: no server checks and no domain outside its tree', async ({ page }) => {
	await login(page, RESELLER);
	expect((await page.goto('/server/doctor'))?.status()).toBe(403);
	expect((await page.goto(`/doctor/${USER}/${DOMAIN}`))?.status()).toBe(404);
	expect((await page.goto(`/doctor/${USER}`))?.status()).toBe(404);
	await page.goto(`/doctor/${RESELLER}`);
	await expect(page.locator(`tr[data-doctor-domain="${DOMAIN}"]`)).toHaveCount(0);
});
