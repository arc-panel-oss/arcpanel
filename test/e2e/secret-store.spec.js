// Happy path of the Secrets pages (E38 spec §7): add a secret, see its
// reference, replace and roll back its value, delete it. The value never
// shows on a page.
import { expect, test } from '@playwright/test';
import { expectAccessible, expectToast, login, USER } from './helpers.js';

test.describe.configure({ mode: 'serial' });

const NAME = '/e2e/db_password';
const VALUE = `e2e-value-${Date.now()}`;

test('user: add, replace, roll back and delete a secret', async ({ page }) => {
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Secrets' })
		.click();
	await expect(page).toHaveURL(new RegExp(`/secrets/${USER}$`));
	await expectAccessible(page);

	await page.getByRole('button', { name: 'Add Secret' }).first().click();
	const drawer = page.getByRole('dialog');
	await drawer.getByRole('textbox', { name: 'Name' }).fill(NAME);
	await drawer.getByRole('textbox', { name: 'Value', exact: true }).fill(VALUE);
	await drawer.getByRole('textbox', { name: 'Description' }).fill('E2E database');
	await drawer.getByRole('button', { name: 'Save' }).click();

	await expectToast(page, `Secret ${NAME} has been created.`);
	await expect(page.getByRole('textbox', { name: 'Reference' })).toHaveValue(`{{secret:${NAME}}}`);
	await expect(page.getByText(VALUE)).toHaveCount(0);
	expect(await page.content()).not.toContain(VALUE);
	await expectAccessible(page);

	await page.getByRole('button', { name: 'Replace value' }).click();
	await page
		.getByRole('dialog')
		.getByRole('textbox', { name: 'New value', exact: true })
		.fill(`${VALUE}-2`);
	await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
	await expectToast(page, `Secret ${NAME} has been changed.`);
	await expect(page.getByRole('cell', { name: /Version 2/ })).toBeVisible();
	expect(await page.content()).not.toContain(VALUE);

	await page
		.getByRole('row', { name: /Version 1/ })
		.getByRole('button', { name: 'Roll back' })
		.click();
	await page
		.getByRole('dialog', { name: 'Roll back to version 1?' })
		.getByRole('button', { name: 'Roll back' })
		.click();
	await expectToast(page, `Secret ${NAME} has been rolled back to version 1.`);
	await expect(page.getByRole('cell', { name: /Version 3/ })).toBeVisible();

	await page.getByRole('link', { name: 'Secrets' }).last().click();
	await expect(page.getByRole('row', { name: new RegExp(NAME) })).toBeVisible();
	await page.getByRole('link', { name: NAME, exact: true }).click();
	await page.getByRole('button', { name: 'Delete' }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();
	await expectToast(page, `Secret ${NAME} has been deleted.`);
	await expect(page.getByRole('row', { name: new RegExp(NAME) })).toHaveCount(0);
});
