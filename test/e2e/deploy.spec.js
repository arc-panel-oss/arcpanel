// Git Deploy (E20 spec 2026-10-29-001 §10): the user turns on pull mode and
// sees the webhook secret once, starts a deploy that fails and reads its log,
// turns deploy off; on a push-mode domain with two releases the user rolls
// back and manages the trusted publishers; the tab works at 375 px.
import { expect, test } from '@playwright/test';
import { expectAccessible, expectToast, login, SITE, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

const PUSH = 'e2e-deploy.example';

test.beforeAll(() => {
	runOnServer(['deploy']);
});

test.afterAll(() => {
	runOnServer(['deploy-clean']);
});

/** The Deploy tab of DOMAIN. */
async function openDeploy(page, domain) {
	await page.goto(`/web/domains/${USER}/${domain}/edit#deploy`);
	await page.getByRole('tab', { name: 'Deploy' }).click();
	return page.getByRole('tabpanel', { name: 'Deploy' });
}

test('user: pull mode shows the webhook secret once', async ({ page }) => {
	await login(page, USER);
	let tab = await openDeploy(page, SITE);
	await expect(tab).toContainText('Pull from Git');
	await expectAccessible(page);
	await tab.locator('input[name="url"]').fill('ssh://git@127.0.0.1/e2e/none.git');
	await tab.locator('select[name="preset"]').selectOption('none');
	await tab.locator('select[name="webroot"]').selectOption('.');
	await tab.getByRole('button', { name: 'Set up pull deploy' }).click();

	tab = page.getByRole('tabpanel', { name: 'Deploy' });
	await page.getByRole('tab', { name: 'Deploy' }).click();
	await expect(tab).toContainText('Finish the setup');
	await expect(tab).toContainText('Copy the secret now');
	await expect(tab.locator('#deploy-pubkey')).toHaveValue(/^ssh-ed25519 /);
	await expect(tab.locator('#deploy-hooksecret')).toHaveValue(/^[A-Za-z0-9]{40}$/);
	await expect(tab.locator('#deploy-hookurl')).toHaveValue(/:8088\/hooks\/[a-z0-9]{12}$/);
	await expectAccessible(page);

	// Never again: not after a reload
	tab = await openDeploy(page, SITE);
	await expect(tab).toContainText('Finish the setup');
	await expect(tab.locator('#deploy-hooksecret')).toHaveCount(0);
	await expect(tab.locator('#deploy-pubkey')).toHaveValue(/^ssh-ed25519 /);
});

test('user: a deploy that cannot fetch fails, with its log', async ({ page }) => {
	await login(page, USER);
	let tab = await openDeploy(page, SITE);
	await tab.getByRole('button', { name: 'Deploy now' }).click();
	await expectToast(page, 'The deploy has started.');
	await expect(async () => {
		tab = await openDeploy(page, SITE);
		await expect(tab).toContainText('Deploy failed', { timeout: 1000 });
	}).toPass({ timeout: 60_000 });
	await tab.getByRole('button', { name: 'View log' }).first().click();
	const drawer = page.getByRole('dialog', { name: 'Deploy log' });
	await expect(drawer).toContainText('fetching');
	await page.keyboard.press('Escape');
});

test('user: turn pull mode off again', async ({ page }) => {
	await login(page, USER);
	const tab = await openDeploy(page, SITE);
	await tab.getByText('Turn off Git Deploy', { exact: true }).first().click();
	await tab.getByRole('button', { name: 'Turn off Git Deploy' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Turn off' }).click();
	await expectToast(page, 'Git Deploy is off.');
	await expect(await openDeploy(page, SITE)).toContainText('Push from CI');
});

test('user: the active state lists the releases and rolls back', async ({ page }) => {
	await login(page, USER);
	let tab = await openDeploy(page, PUSH);
	await expect(tab).toContainText('Live release');
	await expect(tab).toContainText('Releases');
	await expect(tab.locator('.ap-release')).toHaveCount(2);
	await expectAccessible(page);
	const older = (
		await tab.locator('.ap-release').nth(1).locator('.ap-release-version').textContent()
	).trim();

	await tab.locator('.ap-release').nth(1).getByRole('button', { name: 'Roll back' }).click();
	await expect(page.getByRole('alertdialog')).toContainText(
		'Database migrations of later releases are not undone.',
	);
	await page.getByRole('alertdialog').getByRole('button', { name: 'Roll back' }).click();
	await expectToast(page, 'The rollback has started.');
	await expect(async () => {
		tab = await openDeploy(page, PUSH);
		await expect(tab).toContainText(`Rolled back to ${older}`, { timeout: 1000 });
	}).toPass({ timeout: 60_000 });
	await expect(tab.locator('.ap-release', { hasText: older })).toContainText('Live');
});

test('user: add and remove a trusted publisher', async ({ page }) => {
	await login(page, USER);
	let tab = await openDeploy(page, PUSH);
	await expect(tab).toContainText('acme/e2e');
	await expect(tab).toContainText('id-token: write');
	await tab.getByText('Add publisher', { exact: true }).first().click();
	const form = tab.locator('form[action$="/deploy/publishers"]');
	await form.locator('input[name="repo"]').fill('acme/other');
	await form.locator('input[name="ref"]').fill('main');
	await form.getByRole('button', { name: 'Add publisher' }).click();
	await expectToast(page, 'The trusted publisher has been added.');

	tab = await openDeploy(page, PUSH);
	await expect(tab).toContainText('acme/other');
	await tab.getByRole('button', { name: 'Remove acme/other' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, 'The trusted publisher has been removed.');
	await expect(await openDeploy(page, PUSH)).not.toContainText('acme/other');
});

test('user: the Deploy tab fits a 375 px screen', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 800 });
	await login(page, USER);
	await openDeploy(page, PUSH);
	const overflow = await page.evaluate(
		() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth,
	);
	expect(overflow).toBeLessThanOrEqual(0);
	await expectAccessible(page);
});
