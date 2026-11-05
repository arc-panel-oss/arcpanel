// Object storage on Silo (E44 spec §11): a developer adds a bucket and an S3
// key, sees the secret once, deletes both again; the forms refuse bad names
// and quotas beyond the package; a bucket is edited; a key for every bucket
// is saved in the secret store; an account without object storage sees why;
// a reseller outside the tree gets 404; the administrator sees the service,
// the usage per account, and publishes and removes an endpoint. Skipped when
// Silo is not installed.
import { expect, test } from '@playwright/test';
import { ADMIN, DEV, expectAccessible, expectToast, login, RESELLER, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial', timeout: 180_000 });

const BUCKET = 'arce2edev-media';
const KEY = 'e2e-deploy';
// Both are cleaned by prepare.sh objectstorage-clean (OBJSTORE_SECRET, OBJSTORE_ENDPOINT)
const SECRET_NAME = '/e2e/s3-deploy';
const ENDPOINT = 'e2e-s3.example';
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

test('the bucket forms refuse a bad name and a quota beyond the package', async ({ page }) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, DEV);
	await page.goto(`/object-storage/${DEV}`);

	// The name rule is checked by the form request; the dialog opens again
	await page.getByRole('button', { name: 'Add bucket' }).first().click();
	let add = page.getByRole('dialog', { name: 'Add bucket' });
	await add.getByRole('textbox', { name: 'Bucket name' }).fill('-Bad_Name');
	await add.getByRole('spinbutton', { name: 'Quota (MB)' }).fill('10');
	await add.getByRole('button', { name: 'Add bucket' }).click();
	add = page.getByRole('dialog', { name: 'Add bucket' });
	await expect(add).toBeVisible();
	await expect(add).toContainText('Use 3 to 63 lower case letters, digits and hyphens');
	await expectAccessible(page);

	// The package allows 300 MB of bucket quotas: v-add-user-bucket refuses 400
	await add.getByRole('textbox', { name: 'Bucket name' }).fill(BUCKET);
	await add.getByRole('spinbutton', { name: 'Quota (MB)' }).fill('400');
	await add.getByRole('button', { name: 'Add bucket' }).click();
	await expectToast(page, /the package allows 300 MB/);
	await expect(page.getByText('No buckets yet')).toBeVisible();
});

test('a developer edits a bucket and adds a key for every bucket saved in the secret store', async ({
	page,
}) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, DEV);
	await page.goto(`/object-storage/${DEV}`);

	await page.getByRole('button', { name: 'Add bucket' }).first().click();
	const add = page.getByRole('dialog', { name: 'Add bucket' });
	await add.getByRole('textbox', { name: 'Bucket name' }).fill(BUCKET);
	await add.getByRole('spinbutton', { name: 'Quota (MB)' }).fill('100');
	await add.getByRole('button', { name: 'Add bucket' }).click();
	await expectToast(page, `Bucket ${BUCKET} has been created.`);
	const bucketRow = page.getByRole('row', { name: new RegExp(BUCKET) });
	await expect(bucketRow).toContainText('Off');
	await expect(bucketRow).toContainText('100 MB');

	// Edit: a larger quota within the package and versioning on
	await bucketRow.getByRole('button', { name: `Edit ${BUCKET}` }).click();
	const edit = page.getByRole('dialog', { name: `Edit bucket ${BUCKET}` });
	await edit.getByRole('spinbutton', { name: 'Quota (MB)' }).fill('250');
	await edit.getByRole('checkbox', { name: /Keep versions/ }).check();
	await expectAccessible(page);
	await edit.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'Changes have been saved.');
	await expect(bucketRow).toContainText('250 MB');
	await expect(bucketRow).toContainText('On');

	// Beyond the package on edit: refused, the quota stays
	await bucketRow.getByRole('button', { name: `Edit ${BUCKET}` }).click();
	await page
		.getByRole('dialog', { name: `Edit bucket ${BUCKET}` })
		.getByRole('spinbutton', { name: 'Quota (MB)' })
		.fill('500');
	await page
		.getByRole('dialog', { name: `Edit bucket ${BUCKET}` })
		.getByRole('button', { name: 'Save' })
		.click();
	await expectToast(page, /the package allows 300 MB/);
	await expect(page.getByRole('row', { name: new RegExp(BUCKET) })).toContainText('250 MB');

	// A read-write key for every bucket with an expiry date, saved as a secret
	const expires = new Date(Date.now() + 30 * 86_400_000).toISOString().slice(0, 10);
	await page.getByRole('button', { name: 'Add S3 key' }).first().click();
	const keyForm = page.getByRole('dialog', { name: 'Add S3 key' });
	await keyForm.getByRole('textbox', { name: 'Key name' }).fill(KEY);
	await expect(keyForm.getByRole('combobox', { name: 'Buckets' })).toHaveValue('');
	await expect(keyForm.getByRole('combobox', { name: 'Access' })).toHaveValue('rw');
	await keyForm.getByLabel('Expires on').fill(expires);
	await keyForm.getByRole('textbox', { name: 'Save the secret as' }).fill(SECRET_NAME);
	await keyForm.getByRole('button', { name: 'Add S3 key' }).click();
	await expect(page.getByRole('heading', { name: 'S3 key created' })).toBeVisible();
	await expect(
		page.getByText(`The secret is also saved in the secret store as ${SECRET_NAME}.`),
	).toBeVisible();
	const keyId = (await page.getByRole('textbox', { name: 'Access key ID' }).inputValue()).trim();
	expect(keyId.length).toBeGreaterThan(10);
	// The secret is saved, so leaving does not ask
	await page.getByRole('link', { name: 'Done' }).click();
	await expect(page).toHaveURL(new RegExp(`/object-storage/${DEV}$`));

	const keyRow = page.getByRole('row', { name: new RegExp(KEY) });
	await expect(keyRow).toContainText(keyId);
	await expect(keyRow).toContainText('Every bucket of the account');
	await expect(keyRow).toContainText('Read and write');
	await expect(keyRow).toContainText(expires);
	await expectAccessible(page);

	// The secret store page lists the saved secret
	await page.goto('/secrets');
	await expect(page.getByRole('link', { name: SECRET_NAME, exact: true })).toBeVisible();
});

test('an account without object storage in its package sees why, without forms', async ({
	page,
}) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, USER);
	await page.goto('/object-storage');
	await expect(page).toHaveURL(new RegExp(`/object-storage/${USER}$`));
	await expect(
		page.getByText('Object storage is not included in the package of this account.'),
	).toBeVisible();
	await expect(page.getByRole('button', { name: 'Add bucket' })).toHaveCount(0);
	await expect(page.getByRole('button', { name: 'Add S3 key' })).toHaveCount(0);
	await expectAccessible(page);
});

test('a reseller does not reach the object storage of an account outside its tree', async ({
	page,
}) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, RESELLER);
	const response = await page.goto(`/object-storage/${DEV}`);
	expect(response?.status()).toBe(404);
	expect(await page.content()).not.toContain(BUCKET);
});

test('the administrator sees the service, the usage per account and the account page', async ({
	page,
}) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, ADMIN);
	await page.goto('/server/object-storage');
	await expect(
		page.getByRole('heading', { name: 'Object storage', level: 1, exact: true }),
	).toBeVisible();
	await expect(page.getByText('Running')).toBeVisible();
	await expect(page.getByText(/^Silo release /)).toBeVisible();
	await expectAccessible(page);

	// The developer's bucket of the test before counts in the usage table
	const account = page.getByRole('row', { name: new RegExp(DEV) });
	await expect(account).toContainText('250 MB');
	// Remove waits until every bucket is deleted
	await expect(page.getByRole('button', { name: 'Remove' })).toBeDisabled();
	await expect(page.getByText('Removing needs every bucket deleted first.')).toBeVisible();

	await account.getByRole('link', { name: DEV }).click();
	await expect(page).toHaveURL(new RegExp(`/object-storage/${DEV}$`));
	await expect(page.getByRole('row', { name: new RegExp(BUCKET) })).toBeVisible();
	await expect(page.getByRole('row', { name: new RegExp(KEY) })).toBeVisible();
});

test('the administrator publishes an endpoint without a certificate and removes it', async ({
	page,
}) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, ADMIN);
	await page.goto('/server/object-storage');
	const card = page.locator('.ap-card', {
		has: page.getByRole('heading', { name: 'Endpoint' }),
	});
	test.skip(
		(await card.getByText('Not published', { exact: true }).count()) === 0,
		'The test server already publishes an endpoint; the spec leaves it alone',
	);

	await card.getByRole('textbox', { name: 'Hostname' }).fill(ENDPOINT);
	// The test server has no public DNS: no Let's Encrypt
	await card.getByRole('checkbox', { name: /Let's Encrypt/ }).uncheck();
	await card.getByRole('button', { name: 'Publish' }).click();
	await expectToast(page, `Object storage answers on ${ENDPOINT}.`);
	await expect(card.getByText('Published', { exact: true })).toBeVisible();
	await expect(card.getByRole('link', { name: new RegExp(ENDPOINT) })).toBeVisible();
	await expectAccessible(page);

	// The account page connects through the new endpoint
	await login(page, DEV);
	await page.goto(`/object-storage/${DEV}`);
	await expect(page.getByText('No public endpoint yet')).toHaveCount(0);
	await expect(page.locator('.ap-dl')).toContainText(ENDPOINT);

	await login(page, ADMIN);
	await page.goto('/server/object-storage');
	await card.getByRole('textbox', { name: 'Hostname' }).fill('');
	await card.getByRole('button', { name: 'Save' }).click();
	await expectToast(page, 'The object storage endpoint has been removed.');
	await expect(card.getByText('Not published', { exact: true })).toBeVisible();
});

test('a developer deletes the bucket, and the key limited to it goes too', async ({ page }) => {
	test.skip(!ready, 'Silo is not installed on the test server');
	await login(page, DEV);
	await page.goto(`/object-storage/${DEV}`);
	const bucketRow = page.getByRole('row', { name: new RegExp(BUCKET) });
	await bucketRow.getByRole('button', { name: `Delete ${BUCKET}` }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, `Bucket ${BUCKET} has been deleted.`);
	await expect(page.getByText('No buckets yet')).toBeVisible();
	// The key for every bucket stays until it is deleted
	const keyRow = page.getByRole('row', { name: new RegExp(KEY) });
	await keyRow.getByRole('button', { name: `Delete ${KEY}` }).click();
	await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
	await expectToast(page, /S3 key .* has been deleted\./);
	await expect(page.getByText('No S3 keys yet')).toBeVisible();
});
