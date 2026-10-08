import { describe, expect, it, vi } from 'vitest';
import { detectIndent, languageFor } from '../../resources/js/islands/file-manager/editor/indent.js';
import {
	baseName,
	bitsMode,
	breadcrumb,
	formatSize,
	freeName,
	isEditable,
	isExtractable,
	isInside,
	isValidMode,
	isValidName,
	joinPath,
	kindOf,
	modeBits,
	modeString,
	parentPath,
	pickRefusal,
	previewKind,
	quotaUse,
	select,
	shortcut,
	sortEntries,
	zipName,
} from '../../resources/js/islands/file-manager/model.js';
import { createUploader } from '../../resources/js/islands/file-manager/uploader.js';

const entry = (name, extra = {}) => ({ name, dir: false, type: 'f', size: 10, mtime: 1, ...extra });

describe('paths relative to the home directory', () => {
	it('joins, splits and compares paths', () => {
		expect(joinPath('web/example.com', 'public_html')).toBe('web/example.com/public_html');
		expect(joinPath('', 'web')).toBe('web');
		expect(parentPath('web/example.com')).toBe('web');
		expect(parentPath('web')).toBe('');
		expect(baseName('web/a b.txt')).toBe('a b.txt');
		expect(isInside('web', 'web/x')).toBe(true);
		expect(isInside('web', 'webx')).toBe(false);
		expect(breadcrumb('web/example.com')).toEqual([
			{ name: 'web', path: 'web' },
			{ name: 'example.com', path: 'web/example.com' },
		]);
	});

	it('refuses a destination that cannot take the entries', () => {
		const pick = { dest: 'web/example.com/private', writable: true, source: 'web/example.com/public_html', paths: ['web/example.com/public_html/index.html'], move: true };
		expect(pickRefusal(pick)).toBeNull();
		expect(pickRefusal({ ...pick, dest: 'web/example.com', writable: false })).toBe('read-only');
		expect(pickRefusal({ ...pick, writable: undefined })).toBeNull();
		expect(pickRefusal({ ...pick, dest: 'web/example.com/public_html' })).toBe('same-folder');
		expect(pickRefusal({ ...pick, dest: 'web/example.com/public_html', move: false })).toBeNull();
		const folder = { ...pick, paths: ['web/example.com/public_html/assets'] };
		expect(pickRefusal({ ...folder, dest: 'web/example.com/public_html/assets' })).toBe('inside');
		expect(pickRefusal({ ...folder, dest: 'web/example.com/public_html/assets/img', move: false })).toBe('inside');
		expect(pickRefusal({ ...folder, dest: 'web/example.com/public_html/assets2' })).toBeNull();
	});
	it('accepts the names the commands accept', () => {
		for (const name of ['index.php', '.htaccess', 'tệp tin.txt', 'a (1).zip']) {
			expect(isValidName(name), name).toBe(true);
		}
		for (const name of ['', '.', '..', 'a/b', 'a\nb', 'x'.repeat(256)]) {
			expect(isValidName(name), name).toBe(false);
		}
	});
});

describe('sizes, modes and kinds', () => {
	it('formats sizes and modes', () => {
		expect(formatSize(0)).toBe('0 B');
		expect(formatSize(1536)).toBe('1.5 KB');
		expect(formatSize(20 * 1048576)).toBe('20 MB');
		expect(modeString('0755')).toBe('rwxr-xr-x');
		expect(modeString('0640')).toBe('rw-r-----');
		expect(modeString('2775')).toBe('rwxrwsr-x');
		expect(modeString('1777')).toBe('rwxrwxrwt');
		expect(isValidMode('644')).toBe(true);
		expect(isValidMode('0644')).toBe(true);
		expect(isValidMode('0948')).toBe(false);
		expect(isValidMode('07777')).toBe(false);
	});

	it('turns the rwx grid into an octal mode and keeps special bits', () => {
		const bits = modeBits('0750');
		expect(bits.owner).toEqual({ r: true, w: true, x: true });
		expect(bits.other).toEqual({ r: false, w: false, x: false });
		bits.other.r = true;
		expect(bitsMode(bits, '0750')).toBe('0754');
		expect(bitsMode(modeBits('2775'), '2775')).toBe('2775');
	});

	it('knows what opens in the editor and what previews', () => {
		const max = 2097152;
		expect(isEditable(entry('index.php', { mime: 'text/x-php' }), max)).toBe(true);
		expect(isEditable(entry('.htaccess'), max)).toBe(true);
		expect(isEditable(entry('data.json', { mime: 'application/json' }), max)).toBe(true);
		expect(isEditable(entry('photo.jpg', { mime: 'image/jpeg' }), max)).toBe(false);
		expect(isEditable(entry('big.log', { size: max + 1 }), max)).toBe(false);
		expect(isEditable(entry('empty', { size: 0 }), max)).toBe(true);
		expect(previewKind(entry('a.PNG'))).toBe('image');
		expect(previewKind(entry('a.pdf'))).toBe('pdf');
		expect(previewKind(entry('a.mp4'))).toBe('video');
		expect(previewKind(entry('a.zip'))).toBe(null);
		expect(kindOf({ name: 'x', dir: true })).toBe('dir');
		expect(isExtractable('site.tar.gz')).toBe(true);
		expect(isExtractable('site.zip')).toBe(true);
		expect(isExtractable('site.txt')).toBe(false);
	});

	it('picks free names and zip names like the server', () => {
		expect(freeName('a.txt', ['a (1).txt'])).toBe('a (2).txt');
		expect(freeName('.htaccess', [])).toBe('.htaccess (1)');
		expect(zipName(['site'], 'web')).toBe('site.zip');
		expect(zipName(['a', 'b'], 'web/example.com')).toBe('example.com.zip');
		expect(zipName(['a', 'b'], '')).toBe('files.zip');
	});

	it('reports disk use against the quota', () => {
		expect(quotaUse({ used: 50, quota: 200 })).toEqual({ used: 50, quota: 200, percent: 25 });
		expect(quotaUse({ used: 50, quota: null })).toEqual({ used: 50, quota: null, percent: null });
	});
});

describe('sorting, selection and keys', () => {
	it('sorts folders first, names without case', () => {
		const items = [entry('b.txt', { size: 1 }), { ...entry('Zdir'), dir: true }, entry('A.txt', { size: 5 }), { ...entry('adir'), dir: true }];
		expect(sortEntries(items).map((e) => e.name)).toEqual(['adir', 'Zdir', 'A.txt', 'b.txt']);
		expect(sortEntries(items, 'size', 'desc').map((e) => e.name)).toEqual(['Zdir', 'adir', 'A.txt', 'b.txt']);
	});

	it('selects one, toggles with Ctrl and ranges with Shift', () => {
		const names = ['a', 'b', 'c', 'd'];
		let state = select(names, new Set(), null, 'b');
		expect([...state.selected]).toEqual(['b']);
		state = select(names, state.selected, state.anchor, 'd', { shift: true });
		expect([...state.selected]).toEqual(['b', 'c', 'd']);
		state = select(names, state.selected, state.anchor, 'c', { ctrl: true });
		expect([...state.selected]).toEqual(['b', 'd']);
		state = select(names, state.selected, state.anchor, 'a');
		expect([...state.selected]).toEqual(['a']);
	});

	it('maps the keyboard shortcuts', () => {
		expect(shortcut({ key: 'F2' })).toBe('rename');
		expect(shortcut({ key: 'Delete' })).toBe('delete');
		expect(shortcut({ key: 'c', ctrlKey: true })).toBe('copy');
		expect(shortcut({ key: 'V', metaKey: true })).toBe('paste');
		expect(shortcut({ key: 'Backspace' })).toBe('up');
		expect(shortcut({ key: 'x' })).toBe(null);
		expect(shortcut({ key: 'c', altKey: true })).toBe(null);
	});
});

describe('editor helpers', () => {
	it('detects tabs and space steps', () => {
		expect(detectIndent('a\n\tb\n\t\tc\n')).toBe('\t');
		expect(detectIndent('a\n  b\n    c\n  d\n')).toBe('  ');
		expect(detectIndent('a\n    b\n        c\n')).toBe('    ');
		expect(detectIndent('no indentation')).toBe('    ');
	});

	it('picks a language from the file name', () => {
		expect(languageFor('index.php')).toBe('php');
		expect(languageFor('.htaccess')).toBe('apache');
		expect(languageFor('nginx.conf_custom')).toBe('nginx');
		expect(languageFor('.env.local')).toBe('ini');
		expect(languageFor('app.tsx')).toBe('typescript');
		expect(languageFor('README')).toBe('');
	});
});

/** A fetch double for the upload routes: records calls, answers from `routes` */
function fakeFetch(routes) {
	const calls = [];
	const fetch = vi.fn(async (url, init) => {
		calls.push({ url, method: init.method, body: init.body });
		for (const [pattern, answer] of routes) {
			if (pattern.test(`${init.method} ${url}`)) {
				const { status = 200, body = {} } = typeof answer === 'function' ? answer(url, init) : answer;
				return { ok: status < 400, status, json: async () => body };
			}
		}
		return { ok: false, status: 404, json: async () => ({ error: { code: 'E_NOTEXIST', message: 'no route' } }) };
	});
	return { fetch, calls };
}

const blob = (size, name = 'f.bin') => {
	const file = new Blob([new Uint8Array(size)]);
	file.name = name;
	file.lastModified = 7;
	return file;
};

async function settle(uploader) {
	for (let i = 0; i < 200 && (uploader.busy || uploader.items.some((item) => ['queued', 'uploading'].includes(item.state))); i++) {
		await new Promise((resolve) => setTimeout(resolve, 0));
	}
}

describe('upload queue', () => {
	it('sends the missing chunks with at most 3 in flight and finishes', async () => {
		let inFlight = 0;
		let most = 0;
		const { fetch, calls } = fakeFetch([
			[/POST .*\/upload$/, { body: { token: 't1', chunkSize: 4, received: [1] } }],
			[
				/PUT .*\/upload\/t1\/\d+$/,
				() => {
					inFlight++;
					most = Math.max(most, inFlight);
					return { status: 200, body: { received: [] } };
				},
			],
			[/POST .*\/finish$/, { status: 201, body: { name: 'f.bin' } }],
		]);
		const done = vi.fn();
		const uploader = createUploader({ base: '/files/bob/api', csrf: () => 'tok', maxBytes: 1000, fetch, sleep: async () => {}, onDone: done });
		// count in-flight requests leaving again
		const original = fetch.getMockImplementation();
		fetch.mockImplementation(async (url, init) => {
			const response = await original(url, init);
			await new Promise((resolve) => setTimeout(resolve, 0));
			if (init.method === 'PUT') inFlight--;
			return response;
		});

		uploader.add([{ file: blob(18) }], 'web');
		await settle(uploader);

		const item = uploader.items[0];
		expect(item.state).toBe('done');
		expect(item.sent).toBe(18);
		expect(done).toHaveBeenCalledOnce();
		const puts = calls.filter((c) => c.method === 'PUT').map((c) => c.url.split('/').pop());
		expect(puts.sort()).toEqual(['0', '2', '3', '4']);
		expect(most).toBeLessThanOrEqual(3);
		expect(JSON.parse(calls[0].body)).toMatchObject({ path: 'web', name: 'f.bin', size: 18, lastModified: 7 });
	});

	it('tries a failed chunk again with back-off, then stops and resumes', async () => {
		let failures = 3;
		const sleep = vi.fn(async () => {});
		const { fetch } = fakeFetch([
			[/POST .*\/upload$/, { body: { token: 't2', chunkSize: 10, received: [] } }],
			[/PUT /, () => (failures-- > 0 ? { status: 502, body: {} } : { status: 200, body: {} })],
			[/POST .*\/finish$/, { status: 201, body: {} }],
		]);
		const uploader = createUploader({ base: '/b', csrf: () => '', maxBytes: 1000, fetch, sleep, retries: 3 });
		uploader.add([{ file: blob(5) }], '');
		await settle(uploader);
		expect(uploader.items[0].state).toBe('error');
		expect(sleep).toHaveBeenCalledTimes(2);
		expect(sleep.mock.calls.map((c) => c[0])).toEqual([1000, 2000]);

		uploader.retry(uploader.items[0]);
		await settle(uploader);
		expect(uploader.items[0].state).toBe('done');
	});

	it('asks about an existing name and applies the answer to later files', async () => {
		const finishes = [];
		const { fetch } = fakeFetch([
			[/POST .*\/upload$/, (_url, init) => ({ body: { token: JSON.parse(init.body).name === 'a' ? 'ta' : 'tb', chunkSize: 10, received: [] } })],
			[/PUT /, { body: {} }],
			[
				/POST .*\/finish$/,
				(_url, init) => {
					const choice = JSON.parse(init.body).onConflict;
					finishes.push(choice ?? 'none');
					return choice ? { status: 201, body: { name: 'x (1)' } } : { status: 409, body: { error: { code: 'E_EXISTS', message: 'exists', conflicts: ['x'] } } };
				},
			],
		]);
		const uploader = createUploader({ base: '/b', csrf: () => '', maxBytes: 1000, fetch, sleep: async () => {} });
		uploader.add([{ file: blob(1, 'a') }, { file: blob(1, 'b') }], '');
		await settle(uploader);
		expect(uploader.items.map((i) => i.state)).toEqual(['conflict', 'conflict']);

		await uploader.resolve(uploader.items[0], 'rename', true);
		expect(uploader.items.map((i) => i.state)).toEqual(['done', 'done']);
		expect(uploader.items[0].landed).toBe('x (1)');
		expect(finishes).toEqual(['none', 'none', 'rename', 'rename']);
	});

	it('refuses files above the limit and cancels with the session removed', async () => {
		const { fetch, calls } = fakeFetch([
			[/POST .*\/upload$/, { body: { token: 'tc', chunkSize: 2, received: [] } }],
			[/PUT /, () => new Promise(() => {})],
			[/DELETE /, { body: {} }],
		]);
		const uploader = createUploader({ base: '/b', csrf: () => '', maxBytes: 10, fetch, sleep: async () => {} });
		const [big] = uploader.add([{ file: blob(11) }], '');
		expect(big.state).toBe('error');
		expect(big.error).toBe('too-large');

		const [item] = uploader.add([{ file: blob(6) }], '');
		await new Promise((resolve) => setTimeout(resolve, 5));
		await uploader.cancel(item);
		expect(item.state).toBe('cancelled');
		expect(calls.some((c) => c.method === 'DELETE' && c.url.endsWith('/upload/tc'))).toBe(true);
		expect(uploader.totals().size).toBe(0);
	});
});
