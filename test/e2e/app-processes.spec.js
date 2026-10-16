// App processes (E42 spec §9): a developer adds a custom web process from the
// wizard, the domain answers over HTTPS through it, logs and the environment
// work from the process page, and deleting it gives the domain its previous
// template back. A user without the grant has no entry, and accounts outside
// a reseller's tree answer 404. The process is python3's http.server, which
// needs nothing from the network.
import { expect, test } from '@playwright/test';
import { DEV, expectAccessible, expectToast, login, RESELLER, USER } from './helpers.js';
import { runOnServer } from './server.js';

// The tests build on each other (create, use, delete): one worker, in order.
test.describe.configure({ mode: 'serial', timeout: 300_000 });

const DOMAIN = 'e2e-proc.example';
const PAGE = `/app-processes/${DEV}/${DOMAIN}/web`;

test.beforeAll(() => {
	runOnServer(['appprocesses']);
});

test.afterAll(() => {
	runOnServer(['appprocesses-clean']);
});

/** HTTPS status and body start of a domain of the developer, read on the server */
function https(domain) {
	const [status, ...body] = runOnServer(['https', domain, DEV]).split('\n');
	return { status, body: body.join('\n') };
}

test('a developer runs a custom web process for a domain from the wizard', async ({ page }) => {
	await login(page, DEV);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'App processes' })
		.click();
	await expect(page.getByRole('heading', { name: 'App processes', level: 1 })).toBeVisible();
	await expect(page.getByText('No app process yet.')).toBeVisible();
	await expectAccessible(page);

	await page.getByRole('link', { name: 'Add process' }).click();
	await page.getByLabel('Web domain').selectOption(DOMAIN);
	await expect(page.getByLabel('Web process')).toBeChecked();
	await page.getByRole('button', { name: 'Continue' }).click();

	await page.locator('input[name="process-preset"][value="custom"]').check();
	await page.getByRole('button', { name: 'Continue' }).click();

	await page
		.getByRole('textbox', { name: 'Command' })
		.fill('python3 -m http.server %port% --bind 127.0.0.1');
	await expect(page.getByLabel('Name', { exact: true })).toHaveValue('web');
	await page.getByLabel('Template', { exact: true }).selectOption('golang-proxy');
	await page.getByRole('button', { name: 'Continue' }).click();

	await expect(page.getByLabel('Memory (MB)')).toHaveValue('512');
	await expectAccessible(page);
	await page.getByRole('button', { name: 'Add process' }).click();

	await expect(page).toHaveURL(new RegExp(`${PAGE}$`), { timeout: 60_000 });
	await expect(page.getByRole('heading', { name: `${DOMAIN}/web`, level: 1 })).toBeVisible();
	await expect(page.locator('#process-title + .ap-badge')).toHaveText('Running', {
		timeout: 30_000,
	});
	await expectAccessible(page);

	await expect
		.poll(() => https(DOMAIN).body, { timeout: 60_000, intervals: [1000, 2000, 5000] })
		.toContain('arce2e-process');
	expect(https(DOMAIN).status).toBe('200');

	await page.goto('/app-processes');
	const row = page.locator('tbody tr', { hasText: `${DOMAIN}/web` });
	await expect(row).toContainText('Running');
	await expect(row).toContainText('custom');
	await page.getByLabel('Status').selectOption('failed');
	await expect(page.getByText('No process matches the filters.')).toBeVisible();
	await page.getByLabel('Status').selectOption('running');
	await expect(row).toBeVisible();
});

test('the process page shows live logs and saves the environment', async ({ page }) => {
	await login(page, DEV);
	await page.goto(PAGE);

	await page.getByRole('tab', { name: 'Logs' }).click();
	https(DOMAIN);
	await expect(page.getByRole('log')).toContainText('GET / HTTP', { timeout: 30_000 });
	await expectAccessible(page);

	await page.getByRole('tab', { name: 'Environment' }).click();
	await page.getByRole('button', { name: 'Add a variable' }).click();
	await page.getByLabel('Key').last().fill('GREETING');
	await page.locator('input[id^="env-value-"]').last().fill('hello-e2e');
	await page.getByRole('button', { name: 'Save and restart' }).click();
	await expectToast(page, 'Saved; the process was restarted when it ran.');
	await expect
		.poll(() => https(DOMAIN).body, { timeout: 60_000, intervals: [1000, 2000, 5000] })
		.toContain('arce2e-process');
	await expectAccessible(page);

	await page.getByLabel('Key').first().fill('HOME');
	await expect(page.getByText('HOME is set by the panel.')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Save and restart' })).toBeDisabled();
});

test('a user without the grant and a reseller outside the tree cannot reach the processes', async ({
	page,
}) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'App processes' }),
	).toHaveCount(0);
	expect((await page.request.get(`/app-processes/${USER}/create`)).status()).toBe(403);
	expect((await page.request.get(`/app-processes/${DEV}`)).status()).toBe(404);

	await login(page, RESELLER);
	expect((await page.request.get(`/app-processes/${DEV}`)).status()).toBe(404);
	expect((await page.request.get(PAGE)).status()).toBe(404);
});

test('deleting the process restores the domain', async ({ page }) => {
	await login(page, DEV);
	await page.goto(`${PAGE}#danger`);
	await page.getByRole('button', { name: 'Delete the process' }).click();
	const dialog = page.getByRole('dialog', { name: 'Delete the process' });
	await dialog.getByRole('button', { name: 'Delete', exact: true }).click();
	await expect(page).toHaveURL(new RegExp(`/app-processes/${DEV}$`));
	await expect(page.getByText('No app process yet.')).toBeVisible();
	// The default template serves public_html itself again; the proxy
	// template would answer 502 without the process
	await expect.poll(() => https(DOMAIN).status, { timeout: 60_000 }).toBe('200');
	expect(https(DOMAIN).body).toContain('arce2e-process');
});
