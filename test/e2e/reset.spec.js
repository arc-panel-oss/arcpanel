// Password reset and 2FA reset for guests (E7 batch 3 spec §11). The reset
// mail goes to a mailbox on the test server; prepare.sh reads the code.
import { expect, test } from '@playwright/test';
import { expectAccessible, login } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial' });

const USER = 'arce2ereset';
const EMAIL = 'reset@e2e-mail.example';
const SENT =
	'Password reset instructions have been sent to the email address associated with this account.';

test.beforeAll(() => {
	runOnServer(['reset-user', process.env.E2E_PASSWORD]);
});

test.afterAll(() => {
	runOnServer(['reset-clean']);
});

test('guest: reset the password with the code of the e-mail and log in', async ({ page }) => {
	test.setTimeout(120_000);
	await page.context().clearCookies();
	await page.goto('/login');
	await page.getByRole('link', { name: 'Forgot Password' }).click();
	await expect(page).toHaveURL(/\/reset$/);
	await expectAccessible(page);

	await page.getByLabel('Username').fill(USER);
	await page.getByLabel('Email').fill(EMAIL);
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page).toHaveURL(new RegExp(`/reset/code\\?user=${USER}$`));
	await expect(page.getByRole('status')).toContainText(SENT);
	await expectAccessible(page);

	// The mail arrives through the local mail server
	let code = '';
	await expect
		.poll(
			() => {
				code = runOnServer(['reset-code']).trim();
				return code;
			},
			{ timeout: 60_000, intervals: [2_000] },
		)
		.toMatch(/^[A-Za-z0-9]{12}$/);

	await page.getByLabel('Reset Code').fill(code);
	await page.getByRole('button', { name: 'Confirm' }).click();
	await expect(page).toHaveURL(/\/reset\/confirm\?/);
	const password = `${process.env.E2E_PASSWORD}Nw1`;
	await page.getByLabel('New Password').fill(password);
	await page.getByLabel('Confirm Password').fill(password);
	await page.getByRole('button', { name: 'Reset' }).click();
	await expect(page).toHaveURL(/\/login$/);
	await expect(page.getByRole('status')).toContainText(
		'Your password has been changed. You can now log in.',
	);

	// The code works once only
	await page.goto(`/reset/confirm?user=${USER}&code=${code}`);
	await page.getByLabel('New Password').fill(password);
	await page.getByLabel('Confirm Password').fill(password);
	await page.getByRole('button', { name: 'Reset' }).click();
	await expect(page.getByRole('alert')).toContainText('Invalid username or code');

	process.env.E2E_RESET_PASSWORD = password;
	await page.goto('/login');
	await page.fill('#user', USER);
	await page.fill('#password', password);
	await page.click('button[type=submit]');
	await expect(page).toHaveURL(/^https?:\/\/[^/]+\/$/);
});

test('guest: a wrong 2FA reset code unlocks nothing', async ({ page }) => {
	await page.context().clearCookies();
	await page.goto('/reset2fa');
	await expect(page.getByRole('heading', { name: 'Unlock Account' })).toBeVisible();
	await expectAccessible(page);
	await page.getByLabel('Username').fill(USER);
	await page.getByLabel('2FA Reset Code').fill('not-the-code');
	await page.getByRole('button', { name: 'Submit' }).click();
	await expect(page.getByRole('alert')).toContainText('Invalid username or code');
});

test('the login page links to the reset pages', async ({ page }) => {
	await login(page, 'arce2euser');
	await page.context().clearCookies();
	await page.goto('/login');
	await expect(page.getByRole('link', { name: 'Forgot Password' })).toHaveAttribute(
		'href',
		/\/reset$/,
	);
});
