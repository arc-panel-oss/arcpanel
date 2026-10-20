// Object storage on Silo (E44 spec §11): a developer adds a bucket and an S3
// key, sees the secret once, deletes both again; the administrator sees the
// service and the usage per account. Skipped when Silo is not installed.
import { expect, test } from '@playwright/test';
import { ADMIN, DEV, expectAccessible, expectToast, login } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial', timeout: 180_000 });

const BUCKET = 'arce2edev-media';
const KEY = 'e2e-deploy';
let ready = false;

test.beforeAll(() => {
	ready = runOnServer(['objectstorage']).trim().endsWith('ready');
});

test.afterAll(() => {
	runOnServer(['objectstorage-clean']);
});

test('a developer adds a bucket and an S3 key, then deletes both', async ({ page }) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, DEV);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'Object storage' })
		.click();
	await expect(page).toHaveURL(new RegExp(`/object-storage/${DEV}$`));
	await expect(page.getByRole('heading', { name: 'Object storage', level: 1 })).toBeVisible();
	await expect(page.getByText('No buckets yet')).toBeVisible();
	await expectAccessible(page);

	// A bucket within the package quota (300 MB)
	await page.getByRole('button', { name: 'Add bucket' }).first().click();
	const add = page.getByRole('dialog', { name: 'Add bucket' });
	await add.getByRole('textbox', { name: 'Bucket name' }).fill(BUCKET);
	await add.getByRole('spinbutton', { name: 'Quota (MB)' }).fill('100');
	await add.getByRole('checkbox', { name: /Keep versions/ }).check();
	await add.getByRole('button', { name: 'Add bucket' }).click();
	await expectToast(page, `Bucket ${BUCKET} has been created.`);
	const bucketRow = page.getByRole('row', { name: new RegExp(BUCKET) });
	await expect(bucketRow).toContainText('On');
	await expectAccessible(page);

	// A read-only key for that bucket; the secret shows once
	await page.getByRole('button', { name: 'Add S3 key' }).first().click();
	const keyForm = page.getByRole('dialog', { name: 'Add S3 key' });
	await keyForm.getByRole('textbox', { name: 'Key name' }).fill(KEY);
	await keyForm.getByRole('combobox', { name: 'Buckets' }).selectOption(BUCKET);
	await keyForm.getByRole('combobox', { name: 'Access' }).selectOption('ro');
	await keyForm.getByRole('button', { name: 'Add S3 key' }).click();
	await expect(page.getByRole('heading', { name: 'S3 key created' })).toBeVisible();
	await expect(page.getByText('Copy the secret now')).toBeVisible();
	const secret = (
		await page.getByRole('textbox', { name: 'Secret access key' }).inputValue()
	).trim();
	expect(secret.length).toBeGreaterThan(20);
	await expectAccessible(page);
	// Nothing was copied, so leaving asks first (beforeunload)
	page.once('dialog', (dialog) => dialog.accept());
	await page.getByRole('link', { name: 'Done' }).click();
	await expect(page).toHaveURL(new RegExp(`/object-storage/${DEV}$`));

	const keyRow = page.getByRole('row', { name: new RegExp(KEY) });
	await expect(keyRow).toContainText('Read only');
	expect(await page.content()).not.toContain(secret);

	await keyRow.getByRole('button', { name: `Delete ${KEY}` }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, /S3 key .* has been deleted\./);
	await expect(keyRow).toHaveCount(0);

	await bucketRow.getByRole('button', { name: `Delete ${BUCKET}` }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `Bucket ${BUCKET} has been deleted.`);
	await expect(page.getByText('No buckets yet')).toBeVisible();
});

test('the administrator sees the service of the server', async ({ page }) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, ADMIN);
	await page.goto('/server/object-storage');
	await expect(
		page.getByRole('heading', { name: 'Object storage', level: 1, exact: true }),
	).toBeVisible();
	await expect(page.getByText('Running')).toBeVisible();
	await expectAccessible(page);
});
