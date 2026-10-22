// Upload from URL in the file manager
// (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §10 M5):
// an administrator fetches into a customer's folder from a local server with
// private addresses allowed, in each mode, sees the progress, cancels a slow
// download and finds the list again after a reload; a user is refused the
// same local address and never sees the private option.
import { expect, test } from '@playwright/test';
import { ADMIN, expectAccessible, login, SITE, USER } from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial', timeout: 300_000 });
test.use({ actionTimeout: 30_000 });
// A download ends between two polls of the list (one second apart)
const WAIT = { timeout: 30_000 };

const PORT = 39931;
const BASE = `http://127.0.0.1:${PORT}`;
const ROOT = `web/${SITE}/public_html/e2e-files`;
const PAGE = `/files/${USER}?path=${ROOT}`;

test.beforeAll(() => {
	runOnServer(['files']);
	runOnServer(['fetch-clean']);
	runOnServer(['fetch-listen', String(PORT)]);
});

test.afterAll(() => {
	runOnServer(['fetch-clean']);
	runOnServer(['files-clean']);
});

/** owner:group mode size, then the first bytes, of a file of the folder */
function stat(file) {
	const [meta, ...body] = runOnServer(['files-stat', file]).split('\n');
	const [owner, mode, size] = meta.split(' ');
	return { owner, mode, size: Number(size), body: body.join('\n') };
}

async function openDialog(page) {
	await page.goto(PAGE);
	await expect(page.locator('[data-island="file-manager"][data-island-mounted]')).toBeVisible();
	await page.getByRole('button', { name: 'Upload from URL' }).click();
	const dialog = page.getByRole('dialog', { name: 'Upload from URL' });
	await expect(dialog).toBeVisible();
	return dialog;
}

/** The row of the list whose name or URL is TEXT */
function item(dialog, text) {
	return dialog.locator('.ap-upload-list li', { hasText: text });
}

test('an administrator fetches in each mode, cancels a slow download and finds the list after a reload', async ({
	page,
}) => {
	await login(page, ADMIN);
	let dialog = await openDialog(page);
	await expect(dialog.getByText(`Files go into ~/${ROOT}`)).toBeVisible();
	await expectAccessible(page);

	// Simple: one URL, the field empties for the next one
	await dialog.getByLabel('Allow private addresses').check();
	await dialog
		.getByRole('textbox', { name: 'URL', exact: true })
		.fill(`${BASE}/file/2048/simple.bin`);
	await dialog.getByRole('button', { name: 'Add', exact: true }).click();
	await expect(dialog.getByRole('textbox', { name: 'URL', exact: true })).toHaveValue('');
	await expect(item(dialog, 'simple.bin').getByText('Done', { exact: true })).toBeVisible(WAIT);
	// The open folder reloads when a download lands in it
	await expect(page.getByRole('link', { name: 'simple.bin', exact: true })).toBeVisible();
	expect(stat('simple.bin')).toMatchObject({ owner: `${USER}:${USER}`, mode: '644', size: 2048 });

	// A name that exists: name_(1).ext
	await dialog
		.getByRole('textbox', { name: 'URL', exact: true })
		.fill(`${BASE}/file/10/simple.bin`);
	await dialog.getByRole('button', { name: 'Add', exact: true }).click();
	await expect(item(dialog, 'simple_(1).bin').getByText('Done', { exact: true })).toBeVisible(WAIT);

	// cURL: headers and the output name come from the command
	await dialog.getByRole('tab', { name: 'cURL' }).click();
	await dialog
		.getByLabel('Paste one curl command')
		.fill(`curl '${BASE}/echo' \\\n  -H 'X-Test: from-curl' -o curl.txt --compressed`);
	await dialog.getByRole('button', { name: 'Add', exact: true }).click();
	await expect(item(dialog, 'curl.txt').getByText('Done', { exact: true })).toBeVisible(WAIT);
	expect(stat('curl.txt').body).toBe('|from-curl|');
	// A refused option is named under the field and nothing is added
	await dialog.getByLabel('Paste one curl command').fill(`curl -d a=1 ${BASE}/echo`);
	await dialog.getByRole('button', { name: 'Add', exact: true }).click();
	await expect(dialog.getByText('The option -d is not supported.')).toBeVisible();

	// Custom: a header, Bearer authentication and a file name
	await dialog.getByRole('tab', { name: 'Custom' }).click();
	await dialog.getByRole('textbox', { name: 'URL', exact: true }).fill(`${BASE}/echo`);
	await dialog.getByLabel('Header name 1').fill('X-Test');
	await dialog.getByLabel('Header value 1').fill('from-custom');
	await dialog.getByLabel('Authentication').selectOption('bearer');
	await dialog.getByLabel('Token').fill('e2e-token');
	await dialog.getByLabel('File name (optional)').fill('custom.txt');
	await dialog.getByRole('button', { name: 'Add', exact: true }).click();
	await expect(item(dialog, 'custom.txt').getByText('Done', { exact: true })).toBeVisible(WAIT);
	expect(stat('custom.txt').body).toBe('Bearer e2e-token|from-custom|');

	// A slow download shows its progress, then a cancel removes the partial file
	await dialog.getByRole('tab', { name: 'Simple' }).click();
	await dialog.getByRole('textbox', { name: 'URL', exact: true }).fill(`${BASE}/slow`);
	await dialog.getByRole('button', { name: 'Add', exact: true }).click();
	const slow = item(dialog, `${BASE}/slow`);
	await expect(slow.getByText('Downloading')).toBeVisible(WAIT);
	await expect(slow.getByRole('progressbar')).toBeVisible();
	await expect(slow.getByText(/ B \/ 1000 B/)).toBeVisible(WAIT);
	await slow.getByRole('button', { name: /^Cancel / }).click();
	await expect(slow.getByText('Cancelled', { exact: true }).first()).toBeVisible(WAIT);
	expect(runOnServer(['fetch-temp']).trim()).toBe('0');

	// The list lives on the server
	await page.reload();
	dialog = await openDialog(page);
	await expect(item(dialog, 'custom.txt')).toBeVisible();
	await expect(item(dialog, 'curl.txt')).toBeVisible();
	await dialog.getByRole('button', { name: 'Clear finished' }).click();
	await expect(dialog.getByText('Files you add from a URL appear here.')).toBeVisible();
});

test('a user is refused a local address and has no private option', async ({ page }) => {
	await login(page, USER);
	const dialog = await openDialog(page);
	await expect(dialog.getByLabel('Allow private addresses')).toHaveCount(0);
	await dialog.getByRole('textbox', { name: 'URL', exact: true }).fill(`${BASE}/file/10/local.bin`);
	await dialog.getByRole('button', { name: 'Add', exact: true }).click();
	await expect(
		dialog.getByText('Blocked: private and local addresses are not allowed'),
	).toBeVisible(WAIT);
	// No file was written: files-stat fails on a missing file
	expect(() => runOnServer(['files-stat', 'local.bin'])).toThrow();
});
