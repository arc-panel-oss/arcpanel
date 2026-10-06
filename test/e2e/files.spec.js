// File manager (E3 spec §4 "Done when"): a user browses, uploads (with a
// network drop that resumes), edits with the hash guard, downloads, previews
// and runs every operation on the fixtures of prepare.sh files; admins and
// resellers open customers' files through the deep links; the legacy menu
// and FILE_MANAGER switch the page on and off.
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import {
	ADMIN,
	CUSTOMER,
	expectAccessible,
	expectToast,
	login,
	RESELLER,
	SITE,
	USER,
} from './helpers.js';
import { runOnServer } from './server.js';

test.describe.configure({ mode: 'serial', timeout: 600_000 });
// A missing element fails the step in 30 s, not at the end of the test
test.use({ actionTimeout: 30_000 });

const ROOT = `web/${SITE}/public_html/e2e-files`;
const PAGE = `/files/${USER}?path=${ROOT}`;
let local;

test.beforeAll(() => {
	runOnServer(['files']);
	local = mkdtempSync(path.join(tmpdir(), 'arce2e-files-'));
});

test.afterAll(() => {
	runOnServer(['files-clean']);
	rmSync(local, { recursive: true, force: true });
});

/** owner:group mode size of a fixture, then its first bytes */
function stat(file) {
	const [meta, ...body] = runOnServer(['files-stat', file]).split('\n');
	const [owner, mode, size] = meta.split(' ');
	return { owner, mode, size: Number(size), body: body.join('\n') };
}

function row(page, name) {
	return page.locator('.ap-fm-main tbody tr', {
		has: page.getByRole('link', { name, exact: true }),
	});
}

async function openFolder(page) {
	await page.goto(PAGE);
	await expect(page.getByRole('heading', { name: 'Files', level: 1 })).toBeVisible();
	await expect(page.locator('[data-island="file-manager"][data-island-mounted]')).toBeVisible();
}

/** Select one row by clicking its size cell */
async function pick(page, name, modifiers = []) {
	await row(page, name).locator('td').nth(2).click({ modifiers });
}

test('a user browses, creates and uploads a folder and a 200 MB file that survives a network drop', async ({
	page,
}) => {
	const started = Date.now();
	await login(page, USER);
	await page
		.getByRole('navigation', { name: 'Sections' })
		.getByRole('link', { name: 'File manager' })
		.click();
	await expect(page).toHaveURL(new RegExp(`/files/${USER}`));
	await openFolder(page);
	await expect(row(page, 'edit.php')).toBeVisible();
	await expectAccessible(page);

	// New folder
	await page.getByRole('button', { name: 'New folder' }).click();
	await page.getByLabel('Folder name').fill('uploads');
	await page.getByRole('button', { name: 'Create', exact: true }).click();
	await expect(row(page, 'uploads')).toBeVisible();
	await row(page, 'uploads').getByRole('link', { name: 'uploads' }).click();
	await expect(page).toHaveURL(/e2e-files\/uploads$/);

	// A folder of 20 files through the folder picker
	const folder = path.join(local, 'batch');
	mkdirSync(folder);
	for (let i = 1; i <= 20; i++) {
		writeFileSync(path.join(folder, `file-${i}.txt`), `content ${i}`);
	}
	await page.locator('input[type=file][webkitdirectory]').setInputFiles(folder);
	await expect(page.getByRole('region', { name: 'Uploads' })).toContainText('100%', {
		timeout: 60_000,
	});
	await expect(row(page, 'batch')).toBeVisible();
	expect(stat('uploads/batch/file-7.txt')).toMatchObject({
		owner: `${USER}:${USER}`,
		mode: '644',
		body: 'content 7',
	});
	await page.getByRole('button', { name: 'Done' }).click();

	// 200 MB with a network drop: chunk 5 fails twice, chunks from 12 on
	// fail until "Resume"; nothing that arrived is sent again
	const big = path.join(local, 'big.bin');
	writeFileSync(big, Buffer.alloc(200 * 1048576, 7));
	const sent = new Map();
	let drops = 2;
	let offline = true;
	await page.route(/\/api\/upload\/[a-f0-9]{32}\/\d+$/, async (route) => {
		const index = Number(route.request().url().split('/').pop());
		if ((index === 5 && drops-- > 0) || (index >= 12 && offline)) {
			await route.abort('connectionreset');
			return;
		}
		sent.set(index, (sent.get(index) ?? 0) + 1);
		await route.continue();
	});
	await page.locator('input[type=file]:not([webkitdirectory])').setInputFiles(big);
	const uploads = page.getByRole('region', { name: 'Uploads' });
	await expect(uploads.getByRole('button', { name: 'Resume' })).toBeVisible({ timeout: 120_000 });
	offline = false;
	await uploads.getByRole('button', { name: 'Resume' }).click();
	await expect(uploads).toContainText('Uploaded', { timeout: 300_000 });
	expect([...sent.values()].every((count) => count === 1)).toBe(true);
	expect(sent.size).toBe(25);
	expect(stat('uploads/big.bin')).toMatchObject({
		owner: `${USER}:${USER}`,
		mode: '644',
		size: 200 * 1048576,
	});
	await page.unroute(/\/api\/upload\//);

	// Drag and drop of a file
	await page.evaluate(async () => {
		const transfer = new DataTransfer();
		transfer.items.add(new File(['dropped'], 'dropped.txt', { type: 'text/plain' }));
		const target = document.querySelector('.ap-fm-main');
		target.dispatchEvent(new DragEvent('dragenter', { dataTransfer: transfer, bubbles: true }));
		target.dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true }));
	});
	await expect(row(page, 'dropped.txt')).toBeVisible({ timeout: 30_000 });

	// Uploading the same name again asks
	writeFileSync(path.join(local, 'dropped.txt'), 'second');
	await page
		.locator('input[type=file]:not([webkitdirectory])')
		.setInputFiles(path.join(local, 'dropped.txt'));
	await uploads.getByRole('button', { name: 'Keep both' }).click();
	await expect(row(page, 'dropped (1).txt')).toBeVisible({ timeout: 30_000 });
	test.info().annotations.push({
		type: 'timing',
		description: `browse, create and upload: ${Math.round((Date.now() - started) / 1000)} s`,
	});
});

test('a user edits a PHP file and .htaccess with Ctrl+S, and a second save in between is caught', async ({
	page,
	context,
}) => {
	const started = Date.now();
	await login(page, USER);
	await openFolder(page);
	await row(page, 'edit.php').getByRole('link', { name: 'edit.php' }).click();
	await expect(page).toHaveURL(/edit=.*edit\.php/);
	const editor = page.locator('.cm-content');
	await expect(editor).toContainText('arce2e-before');
	await editor.click();
	await page.keyboard.press('Control+End');
	await page.keyboard.type('\necho "arce2e-after";');
	await page.keyboard.press('Control+s');
	await expectToast(page, 'edit.php has been saved.');
	await expect
		.poll(() => runOnServer(['files-http', 'edit.php']), { timeout: 30_000 })
		.toContain('arce2e-beforearce2e-after');
	expect(stat('edit.php').mode).toBe('644');
	test.info().annotations.push({
		type: 'timing',
		description: `open, edit and save: ${Math.round((Date.now() - started) / 1000)} s`,
	});

	// .htaccess shows the Apache hint
	await page.getByRole('button', { name: 'Back to the folder' }).click();
	await row(page, '.htaccess').getByRole('link', { name: '.htaccess' }).click();
	await expect(page.getByText('Apache reads .htaccess on the next request')).toBeVisible();
	await expectAccessible(page);

	// Two tabs: the second saves first, the first is told
	const second = await context.newPage();
	await second.goto(page.url());
	await expect(second.locator('.cm-content')).toContainText('arce2e htaccess');
	await page.locator('.cm-content').click();
	await page.keyboard.type('# first tab\n');
	await second.locator('.cm-content').click();
	await second.keyboard.type('# second tab\n');
	await second.keyboard.press('Control+s');
	await expectToast(second, '.htaccess has been saved.');
	await page.keyboard.press('Control+s');
	await expect(page.getByRole('dialog', { name: 'The file changed on disk' })).toBeVisible();
	await page.getByRole('button', { name: 'Reload' }).click();
	await expect(page.locator('.cm-content')).toContainText('# second tab');
	await second.close();
});

test('a user downloads files and zips, previews media and gets a download for a large log', async ({
	page,
}) => {
	await login(page, USER);
	await openFolder(page);

	await row(page, 'my site').getByRole('link', { name: 'my site' }).click();
	await expect(
		page.getByRole('navigation', { name: 'Current folder' }).locator('[aria-current="page"]'),
	).toHaveText('my site');
	const one = page.waitForEvent('download');
	await pick(page, 'a b.txt');
	await page.getByRole('button', { name: 'Download', exact: true }).first().click();
	const file = await one;
	expect(file.suggestedFilename()).toBe('a b.txt');
	expect(readFileSync(await file.path(), 'utf8')).toBe('hello');

	await page
		.getByRole('navigation', { name: 'Current folder' })
		.getByRole('link', { name: 'e2e-files' })
		.click();
	await expect(
		page.getByRole('navigation', { name: 'Current folder' }).locator('[aria-current="page"]'),
	).toHaveText('e2e-files');
	const zip = page.waitForEvent('download');
	await pick(page, 'my site');
	await page.getByRole('button', { name: 'Download', exact: true }).first().click();
	const archive = await zip;
	expect(archive.suggestedFilename()).toBe('my site.zip');
	const bytes = readFileSync(await archive.path());
	expect(bytes.subarray(0, 2).toString()).toBe('PK');
	expect(bytes.includes(Buffer.from('my site/ảnh/x.txt'))).toBe(true);

	// Previews: image, PDF, video, all through inline tickets
	for (const [name, mime] of [
		['photo.png', 'image/png'],
		['doc.pdf', 'application/pdf'],
		['clip.mp4', 'video/mp4'],
	]) {
		await row(page, name).getByRole('link', { name, exact: true }).click();
		const dialog = page.getByRole('dialog', { name });
		await expect(dialog).toBeVisible();
		const media = dialog.locator('img, iframe, video');
		await expect(media).toHaveAttribute('src', /inline=1/);
		const response = await page.request.get(await media.getAttribute('src'));
		expect(response.status()).toBe(200);
		expect(response.headers()['content-type']).toContain(mime);
		expect(response.headers()['content-disposition']).toMatch(/^inline;/);
		await dialog.getByRole('button', { name: 'Close' }).click();
	}

	// 5 MB is above the editor's limit: opening downloads it
	const log = page.waitForEvent('download');
	await row(page, 'big.log').getByRole('link', { name: 'big.log' }).click();
	expect((await log).suggestedFilename()).toBe('big.log');

	// A symlink out of the home directory opens nothing
	await row(page, 'my site').getByRole('link', { name: 'my site' }).click();
	await expect(
		page.getByRole('navigation', { name: 'Current folder' }).locator('[aria-current="page"]'),
	).toHaveText('my site');
	const link = page.locator('.ap-fm-main tbody tr', { hasText: '→ /etc/passwd' });
	await expect(link).toContainText('outside home');
	await link.getByRole('link').click();
	await expectToast(page, 'This link points outside the home directory');
});

test('rename, copy, move, delete, permissions, compress and extract ask instead of overwriting', async ({
	page,
}) => {
	await login(page, USER);
	await openFolder(page);

	// Rename with F2
	await pick(page, 'photo.png');
	await page.keyboard.press('F2');
	await page.getByLabel('New name').fill('picture.png');
	await page.getByRole('dialog').getByRole('button', { name: 'Rename', exact: true }).click();
	await expect(row(page, 'picture.png')).toBeVisible();

	// Copy and paste into the same folder gets a free name
	await pick(page, 'picture.png');
	await page.keyboard.press('Control+c');
	await page.keyboard.press('Control+v');
	await expect(row(page, 'picture (1).png')).toBeVisible();

	// Move into "my site" through the folder picker; a second move asks
	await pick(page, 'picture (1).png');
	await row(page, 'picture (1).png').click({ button: 'right' });
	await page.getByRole('menuitem', { name: 'Move to…' }).click();
	const picker = page.getByRole('dialog', { name: 'Move to' });
	await picker.getByRole('listitem').filter({ hasText: 'my site' }).click();
	await picker.getByRole('button', { name: 'Move here' }).click();
	await expect(row(page, 'picture (1).png')).toHaveCount(0);
	await pick(page, 'picture.png');
	await page.getByRole('button', { name: 'Copy', exact: true }).click();
	await row(page, 'my site').getByRole('link', { name: 'my site' }).click();
	await expect(
		page.getByRole('navigation', { name: 'Current folder' }).locator('[aria-current="page"]'),
	).toHaveText('my site');
	await page.getByRole('button', { name: 'Paste' }).click();
	await expect(row(page, 'picture.png')).toBeVisible();
	await page
		.getByRole('navigation', { name: 'Current folder' })
		.getByRole('link', { name: 'e2e-files' })
		.click();
	await expect(
		page.getByRole('navigation', { name: 'Current folder' }).locator('[aria-current="page"]'),
	).toHaveText('e2e-files');
	await pick(page, 'picture.png');
	await page.keyboard.press('Control+x');
	await row(page, 'my site').getByRole('link', { name: 'my site' }).click();
	await expect(
		page.getByRole('navigation', { name: 'Current folder' }).locator('[aria-current="page"]'),
	).toHaveText('my site');
	await page.keyboard.press('Control+v');
	await expect(page.getByRole('dialog', { name: 'Some items already exist' })).toContainText(
		'picture.png',
	);
	await page.getByRole('button', { name: 'Keep both' }).click();
	await expect(row(page, 'picture (2).png')).toBeVisible();

	// Recursive permissions with separate folder and file modes
	await page
		.getByRole('navigation', { name: 'Current folder' })
		.getByRole('link', { name: 'e2e-files' })
		.click();
	await expect(
		page.getByRole('navigation', { name: 'Current folder' }).locator('[aria-current="page"]'),
	).toHaveText('e2e-files');
	await pick(page, 'my site');
	await page.getByRole('button', { name: 'Permissions' }).first().click();
	const perms = page.getByRole('dialog', { name: 'Permissions of my site' });
	await perms.getByLabel('Mode').fill('0750');
	await perms.getByLabel('Apply to everything inside the folder').check();
	await perms.getByLabel('File mode').fill('0640');
	await perms.getByRole('button', { name: 'Apply' }).click();
	await expect.poll(() => stat('my site/ảnh/x.txt').mode).toBe('640');
	expect(stat('my site/ảnh').mode).toBe('750');

	// Compress a selection, extract an archive into a new folder
	await pick(page, 'my site');
	await pick(page, 'edit.php', ['Control']);
	await page.getByRole('button', { name: 'Compress to zip' }).click();
	await page.getByLabel('Archive name').fill('both.zip');
	await page.getByRole('dialog').getByRole('button', { name: 'Compress', exact: true }).click();
	await expect(row(page, 'both.zip')).toBeVisible();
	await row(page, 'site.zip').click({ button: 'right' });
	await page.getByRole('menuitem', { name: 'Extract to a new folder' }).click();
	await expect(row(page, 'site')).toBeVisible();
	expect(stat('site/archive-src/one.txt').body).toBe('one');

	// Delete two items after confirming
	await pick(page, 'both.zip');
	await pick(page, 'site', ['Control']);
	await page.keyboard.press('Delete');
	await expect(page.getByRole('dialog', { name: 'Delete' })).toContainText('Delete 2 items?');
	await page
		.getByRole('dialog', { name: 'Delete' })
		.getByRole('button', { name: 'Delete' })
		.click();
	await expect(row(page, 'both.zip')).toHaveCount(0);
	await expect(row(page, 'site')).toHaveCount(0);
});

test('an administrator opens a customer document root from the web domain page', async ({
	page,
}) => {
	await login(page, ADMIN);
	await page.goto(`/web/domains/${USER}/${SITE}/edit`);
	await page.getByRole('link', { name: 'Open document root' }).click();
	await expect
		.poll(() => decodeURIComponent(page.url()))
		.toContain(`/files/${USER}?path=web/${SITE}/public_html`);
	await expect(page.getByText(`Browsing the files of ${USER}`)).toBeVisible();
	await expect(row(page, 'e2e-files')).toBeVisible();
	await expectAccessible(page);
});

test('a reseller opens its customer files, not other trees, and only reads a suspended customer', async ({
	page,
}) => {
	await login(page, RESELLER);
	await page.goto(`/users/${CUSTOMER}`);
	await page.getByRole('link', { name: 'Files', exact: true }).click();
	await expect(page).toHaveURL(new RegExp(`/files/${CUSTOMER}$`));
	await expect(page.getByText(`Browsing the files of ${CUSTOMER}`)).toBeVisible();
	// The home directory belongs to root; ~/tmp is the customer's
	await expect(page.getByRole('button', { name: 'New folder' })).toHaveCount(0);
	await page
		.getByRole('navigation', { name: 'Folders' })
		.getByRole('link', { name: 'tmp', exact: true })
		.click();
	await expect(page.getByRole('button', { name: 'New folder' })).toBeVisible();

	expect((await page.request.get(`/files/${USER}`)).status()).toBe(404);
	expect(
		(
			await page.request.get(`/files/${USER}/api/list`, {
				headers: { Accept: 'application/json' },
			})
		).status(),
	).toBe(404);

	runOnServer(['files-suspend', CUSTOMER]);
	try {
		await page.reload();
		await expect(page.getByText('The account is suspended.')).toBeVisible();
		await expect(page.getByRole('button', { name: 'New folder' })).toHaveCount(0);
		await expect(page.getByRole('button', { name: 'Upload', exact: true })).toHaveCount(0);
		const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
		const write = await page.request.post(`/files/${CUSTOMER}/api/mkdir`, {
			headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
			data: { path: 'tmp', name: 'nope' },
		});
		expect(write.status()).toBe(403);
	} finally {
		runOnServer(['files-unsuspend', CUSTOMER]);
	}
});

test('an old /fm/ bookmark opens the new page, and FILE_MANAGER switches it off', async ({
	page,
}) => {
	await login(page, USER);
	await page.goto('/fm/');
	await expect(page).toHaveURL(new RegExp(`/files/${USER}`));

	runOnServer(['files-config', 'FILE_MANAGER', 'false']);
	try {
		await page.goto('/');
		await expect(
			page
				.getByRole('navigation', { name: 'Sections' })
				.getByRole('link', { name: 'File manager' }),
		).toHaveCount(0);
		expect((await page.request.get(`/files/${USER}`)).status()).toBe(404);
	} finally {
		runOnServer(['files-config', 'FILE_MANAGER', 'true']);
	}
});

test('stale uploads and tickets are removed by the scheduler job', () => {
	expect(runOnServer(['files-gc']).trim()).toBe('0');
});
