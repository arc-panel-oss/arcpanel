// Node.js apps (E14 spec §4 "Done when"): a developer creates an Express app
// and a Next.js app from the wizard, installs their dependencies and builds,
// and both answer over HTTPS within 5 minutes; logs and the environment work
// from the app page; deleting frees the domain. A user without Node.js in
// the package has no entry, and accounts outside a reseller's tree answer
// 404. Dependencies come from the npm registry.
import { expect, test } from '@playwright/test';
import { DEV, expectAccessible, expectToast, login, RESELLER, USER } from './helpers.js';
import { runOnServer } from './server.js';

// The tests build on each other (create, use, delete): one worker, in order,
// so a failure does not rerun beforeAll and wipe the apps of the next tests.
test.describe.configure({ mode: 'serial', timeout: 900_000 });

const API = 'e2e-api.example';
const NEXT = 'e2e-next.example';

test.beforeAll(() => {
	runOnServer(['nodeapps']);
});

test.afterAll(() => {
	runOnServer(['nodeapps-clean']);
});

/** HTTPS status and body start of a domain of the developer, read on the server */
function https(domain) {
	const [status, ...body] = runOnServer(['https', domain, DEV]).split('\n');
	return { status, body: body.join('\n') };
}

async function eventuallyServes(domain, text, timeout = 120_000) {
	await expect
		.poll(() => https(domain).body, { timeout, intervals: [1000, 2000, 5000] })
		.toContain(text);
	expect(https(domain).status).toBe('200');
}

/** The task panel of the page shows LABEL as done. */
async function expectTaskDone(page, label) {
	const panel = page.locator('[aria-live="polite"]', { hasText: label });
	await expect(panel.locator('.ap-badge')).toHaveText(/Done|Failed/, { timeout: 600_000 });
	await expect(panel.locator('.ap-badge')).toHaveText('Done');
}

/** Walk the wizard with the proposals of package.json and create the app. */
async function createApp(page, domain, template) {
	await page.goto('/app/node-apps');
	await page.getByRole('link', { name: 'Create app' }).click();
	await expect(page.getByRole('heading', { name: 'Create app', level: 1 })).toBeVisible();
	await page.getByLabel('Web domain').selectOption(domain);
	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.getByLabel('A script of package.json')).toBeChecked();
	await page.getByRole('button', { name: 'Continue' }).click();
	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.getByLabel('Process name')).toHaveValue(domain);
	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.locator(`input[name="app-template"][value="${template}"]`)).toBeChecked();
	await expectAccessible(page);
	await page.getByRole('button', { name: 'Create app' }).click();
	await expect(page.getByRole('heading', { name: `The app of ${domain} runs.` })).toBeVisible({
		timeout: 180_000,
	});
}

test('a developer deploys an Express app and a Next.js app with HTTPS from the wizard', async ({
	page,
}) => {
	await login(page, DEV);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Node apps' })
		.click();
	await expect(page.getByRole('heading', { name: 'Node apps', level: 1 })).toBeVisible();
	await expect(page.getByText('No Node.js app yet.')).toBeVisible();
	await expectAccessible(page);

	// Express: create, install the dependencies; the app restarts with them
	let started = Date.now();
	await createApp(page, API, 'nodejs-proxy');
	await page.getByRole('button', { name: 'Install dependencies' }).click();
	await expectTaskDone(page, `Install the dependencies of ${API}`);
	await eventuallyServes(API, 'arce2e-express');
	expect(Date.now() - started).toBeLessThan(300_000);

	// Next.js: the template comes from package.json; install, then build
	started = Date.now();
	await createApp(page, NEXT, 'nextjs');
	await page.getByRole('button', { name: 'Install dependencies' }).click();
	await expectTaskDone(page, `Install the dependencies of ${NEXT}`);
	await page.getByRole('button', { name: 'Run build' }).click();
	await expectTaskDone(page, `Build ${NEXT}`);
	await eventuallyServes(NEXT, 'arce2e-next');
	expect(Date.now() - started).toBeLessThan(300_000);

	// The list shows both online with their resources
	await page.goto('/app/node-apps');
	for (const domain of [API, NEXT]) {
		const row = page.locator('tbody tr', { hasText: domain });
		await expect(row).toContainText('Online', { timeout: 30_000 });
		await expect(row).toContainText('MB');
	}
	await expectAccessible(page);
});

test('the app page shows live logs and saves the environment the app reads', async ({ page }) => {
	await login(page, DEV);
	await page.goto(`/app/node/${DEV}/apps/${API}`);
	await expect(page.getByRole('heading', { name: API, level: 1 })).toBeVisible();
	await expectAccessible(page);

	await page.getByRole('tab', { name: 'Logs' }).click();
	https(API);
	await expect(page.getByRole('log')).toContainText('request /', { timeout: 30_000 });
	await expectAccessible(page);

	await page.getByRole('tab', { name: 'Environment' }).click();
	await page.getByRole('button', { name: 'Add a variable' }).click();
	await page.getByLabel('Key').last().fill('GREETING');
	await page.locator('input[id^="env-value-"]').last().fill('hello-e2e');
	await expect(page.locator('input[id^="env-value-"]').last()).toHaveAttribute('type', 'text');
	await page.getByRole('button', { name: 'Save and restart' }).click();
	await expectToast(page, 'Saved; the app was restarted.');
	await eventuallyServes(API, 'hello-e2e', 60_000);
	await page.reload();
	await page.getByRole('tab', { name: 'Environment' }).click();
	await expect(page.locator('input[id^="env-value-"]').first()).toHaveAttribute('type', 'password');
	await expectAccessible(page);

	await page.getByLabel('Key').first().fill('PORT');
	await expect(page.getByText('PORT is set by the panel.')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Save and restart' })).toBeDisabled();
});

test('a user without Node.js and a reseller outside the tree cannot reach the apps', async ({
	page,
}) => {
	await login(page, USER);
	await expect(
		page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Node apps' }),
	).toHaveCount(0);
	expect((await page.request.get(`/app/node/${USER}/apps/create`)).status()).toBe(403);
	expect((await page.request.get(`/app/node/${DEV}/apps`)).status()).toBe(404);

	await login(page, RESELLER);
	expect((await page.request.get(`/app/node/${DEV}/apps`)).status()).toBe(404);
});

test('deleting the apps restores the domains', async ({ page }) => {
	await login(page, DEV);
	for (const domain of [NEXT, API]) {
		await page.goto(`/app/node/${DEV}/apps/${domain}#danger`);
		await page.getByRole('button', { name: 'Delete the app' }).click();
		const dialog = page.getByRole('dialog', { name: 'Delete the app' });
		await dialog.getByRole('button', { name: 'Delete', exact: true }).click();
		await expect(page).toHaveURL(new RegExp(`/app/node/${DEV}/apps$`));
		await expect.poll(() => https(domain).body, { timeout: 60_000 }).not.toContain('arce2e-');
	}
	await expect(page.getByText('No Node.js app yet.')).toBeVisible();
});
