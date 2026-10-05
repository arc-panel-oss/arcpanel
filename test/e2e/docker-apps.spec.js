// Docker apps (E4 spec §4 "Done when"): an administrator sees the engine on
// Server → Docker; a developer installs Uptime Kuma from the store on a
// domain with HTTPS, follows the install task and the logs on the app page,
// and deletes it again, which frees its port and the domain. A user without
// Docker apps in the package has no entry, and a reseller outside the tree
// gets 404. The image comes from Docker Hub.
import { expect, test } from '@playwright/test';
import { ADMIN, DEV, expectAccessible, login, RESELLER, USER } from './helpers.js';
import { runOnServer } from './server.js';

// The tests build on each other (install, use, delete): one worker, in order.
test.describe.configure({ mode: 'serial', timeout: 900_000 });

const KUMA = 'e2e-kuma.example';

test.beforeAll(() => {
	runOnServer(['dockerapps']);
});

test.afterAll(() => {
	runOnServer(['dockerapps-clean']);
});

/** HTTPS status and body start of a domain of the developer, read on the server */
function https(domain) {
	const [status, ...body] = runOnServer(['https', domain, DEV]).split('\n');
	return { status, body: body.join('\n') };
}

test('an administrator sees the Docker engine on Server → Docker', async ({ page }) => {
	await login(page, ADMIN);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Docker', exact: true })
		.click();
	await expect(page.getByRole('heading', { name: 'Docker', level: 1 })).toBeVisible();
	await expect(page.getByRole('heading', { name: 'Docker engine' })).toBeVisible();
	await expect(page.locator('#docker-engine-title').locator('..')).toContainText('Running');
	await expect(page.getByText('/var/lib/docker')).toBeVisible();
	await expectAccessible(page);
});

test('a developer installs Uptime Kuma from the store and it answers over HTTPS', async ({
	page,
}) => {
	await login(page, DEV);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Docker apps' })
		.click();
	await expect(page.getByRole('heading', { name: 'Docker apps', level: 1 })).toBeVisible();
	await expect(page.getByText('No Docker app yet.')).toBeVisible();
	await expectAccessible(page);

	const started = Date.now();
	await page.getByRole('link', { name: 'Open the store' }).first().click();
	await expect(page.getByRole('heading', { name: 'Store', level: 1 })).toBeVisible();
	await page.getByRole('searchbox', { name: 'Search apps' }).fill('kuma');
	await expect(page.locator('a.ap-pick')).toHaveCount(1);
	await expectAccessible(page);
	await page.locator('a.ap-pick', { hasText: 'Uptime Kuma' }).click();

	await expect(page.getByRole('heading', { name: 'Uptime Kuma', level: 1 })).toBeVisible();
	await expect(page.getByLabel('App name')).toHaveValue('uptime-kuma');
	await page.getByLabel('Web domain').selectOption(KUMA);
	await expect(page.getByLabel('Time zone')).not.toHaveValue('');
	await expectAccessible(page);
	await page.getByRole('button', { name: 'Install', exact: true }).click();

	// The app page follows the install task
	await expect(page).toHaveURL(new RegExp(`/app/docker/${DEV}/apps/uptime-kuma$`));
	const panel = page.locator('[aria-live="polite"]', { hasText: 'Install Uptime Kuma' });
	await expect(panel.locator('.ap-badge')).toHaveText(/Done|Failed/, { timeout: 600_000 });
	await expect(panel.locator('.ap-badge')).toHaveText('Done');
	await expect(page.locator('#app-title').locator('..')).toContainText('running', {
		timeout: 30_000,
	});
	await expect
		.poll(() => https(KUMA).status, { timeout: 120_000, intervals: [1000, 2000, 5000] })
		.toMatch(/^(200|302)$/);
	expect(Date.now() - started).toBeLessThan(300_000);
	await expectAccessible(page);

	// Live container logs
	await page.getByRole('tab', { name: 'Logs' }).click();
	await expect(page.getByRole('log', { name: 'Logs' })).not.toContainText('Nothing logged yet.', {
		timeout: 60_000,
	});
	await expectAccessible(page);

	// Settings: the variables, secrets write-only
	await page.getByRole('tab', { name: 'Settings' }).click();
	await expect(page.getByLabel('Time zone')).toBeVisible();
	await expectAccessible(page);

	// My apps
	await page.goto('/app/docker-apps');
	const row = page.locator('tbody tr', { hasText: 'uptime-kuma' });
	await expect(row).toContainText('running');
	await expect(row).toContainText(KUMA);
	await expectAccessible(page);
});

test('a user without Docker apps and a reseller outside the tree cannot reach the apps', async ({
	page,
}) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Docker apps' }),
	).toHaveCount(0);
	await page.goto(`/app/docker/${USER}/apps`);
	await expect(page.getByText('Docker apps are not included in your package.')).toBeVisible();
	const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
	const install = await page.request.post(`/app/docker/${USER}/api/apps`, {
		data: { slug: 'uptime-kuma', name: 'kuma' },
		headers: { 'X-CSRF-TOKEN': csrf ?? '', Accept: 'application/json' },
	});
	expect(install.status()).toBe(403);
	expect((await page.request.get(`/app/docker/${DEV}/apps`)).status()).toBe(404);

	await login(page, RESELLER);
	expect((await page.request.get(`/app/docker/${DEV}/apps`)).status()).toBe(404);
	expect((await page.request.get('/app/server/docker')).status()).toBe(403);
});

test('deleting the app frees its port and gives the domain back', async ({ page }) => {
	const port = runOnServer(['dockerapp-port', 'uptime-kuma']).trim();
	expect(port).toMatch(/^\d+$/);
	expect(runOnServer(['port-app', port]).trim()).toBe('docker:uptime-kuma');

	await login(page, DEV);
	await page.goto(`/app/docker/${DEV}/apps/uptime-kuma#danger`);
	await page.getByLabel('Keep the data folder').uncheck();
	await page.getByRole('button', { name: 'Delete the app' }).click();
	const dialog = page.getByRole('dialog', { name: 'Delete the app' });
	await dialog.getByRole('button', { name: 'Delete', exact: true }).click();
	await expect(page).toHaveURL(new RegExp(`/app/docker/${DEV}/apps$`));
	await expect(page.getByText('No Docker app yet.')).toBeVisible();
	expect(runOnServer(['port-app', port]).trim()).toBe('');
	await expect.poll(() => https(KUMA).status, { timeout: 60_000 }).toBe('200');
});
