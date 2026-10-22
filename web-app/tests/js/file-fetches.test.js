// Upload from URL in the file manager island
// (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §3.2, §6.5).
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApi } from '../../resources/js/islands/file-manager/api.js';
import { BADGE, createFetches, describe as describeFetch, errorText, FAST, IDLE, label, percent, requestBody, tone } from '../../resources/js/islands/file-manager/fetches.js';
import { formatSize } from '../../resources/js/islands/file-manager/model.js';

const t = (key, ...args) => args.reduce((text, arg) => text.replace(/%[sd]/, String(arg)), key);

const item = (id, status, extra = {}) => ({ id, status, url: `https://a.example/${id}`, name: `${id}.zip`, path: 'tmp', bytes: 0, total: null, percent: null, error: '', http: null, ...extra });

/** Manual timers: run() fires the pending callback */
function manualTimers() {
	const timers = { pending: null, delay: null };
	timers.set = vi.fn((fn, delay) => {
		timers.pending = fn;
		timers.delay = delay;
		return 1;
	});
	timers.clear = vi.fn(() => {
		timers.pending = null;
		timers.delay = null;
	});
	timers.run = async () => {
		const fn = timers.pending;
		timers.pending = null;
		await fn?.();
	};
	return timers;
}

afterEach(() => {
	vi.unstubAllGlobals();
});

describe('fetch api', () => {
	it('lists, starts and cancels through the account routes', async () => {
		const fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ fetches: [], active: 0 }) }));
		vi.stubGlobal('fetch', fetch);
		document.head.innerHTML = '<meta name="csrf-token" content="tok">';
		const api = createApi('/files/bob/api', t);

		await api.fetches();
		await api.startFetch({ mode: 'simple', url: 'https://a.example/' });
		await api.cancelFetch('17611234560042');

		expect(fetch.mock.calls.map(([url, options]) => [url, options.method])).toEqual([
			['/files/bob/api/fetches', 'GET'],
			['/files/bob/api/fetches', 'POST'],
			['/files/bob/api/fetches/17611234560042', 'DELETE'],
		]);
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ mode: 'simple', url: 'https://a.example/' });
	});
});

describe('fetch store', () => {
	it('polls fast while the dialog is open and a fetch runs, slower when idle or closed, never when closed and idle', async () => {
		const timers = manualTimers();
		const answers = [
			{ fetches: [item('a', 'running', { bytes: 100, total: 1000 })], active: 1 },
			{ fetches: [item('a', 'running', { bytes: 600, total: 1000 })], active: 1 },
			{ fetches: [item('a', 'done', { bytes: 1000, total: 1000 })], active: 0 },
		];
		const api = { fetches: vi.fn(async () => answers.shift() ?? { fetches: [item('a', 'done')], active: 0 }) };
		let clock = 0;
		const onDone = vi.fn();
		const store = createFetches({ api, onDone, timers, now: () => clock });

		await store.setOpen(true);
		expect(timers.delay).toBe(FAST);
		clock = 1000;
		await timers.run();
		expect(store.state.speeds.a).toBe(500);
		expect(timers.delay).toBe(FAST);
		clock = 2000;
		await timers.run();
		expect(onDone).toHaveBeenCalledWith(expect.objectContaining({ id: 'a', status: 'done' }));
		expect(timers.delay).toBe(IDLE);

		await store.setOpen(false);
		expect(timers.pending).toBeNull();

		store.state.active = 2;
		await store.setOpen(false);
		expect(timers.delay).toBe(BADGE);
		store.stop();
		expect(timers.pending).toBeNull();
	});

	it('schedules with the global timers when none are given', async () => {
		vi.useFakeTimers();
		const api = { fetches: vi.fn(async () => ({ fetches: [item('a', 'running')], active: 1 })) };
		const store = createFetches({ api });
		await store.setOpen(true);
		await vi.advanceTimersByTimeAsync(FAST);
		expect(api.fetches).toHaveBeenCalledTimes(2);
		store.stop();
		vi.useRealTimers();
	});

	it('takes the list from every answer and clears the finished fetches one by one', async () => {
		const timers = manualTimers();
		const list = [item('a', 'done'), item('b', 'failed', { error: 'blocked' }), item('c', 'running')];
		const api = {
			fetches: vi.fn(async () => ({ fetches: list, active: 1 })),
			startFetch: vi.fn(async () => ({ id: 'd', fetches: [item('d', 'queued'), ...list], active: 2 })),
			cancelFetch: vi.fn(async (id) => {
				list.splice(
					list.findIndex((x) => x.id === id),
					1,
				);
				return { fetches: [...list], active: 1 };
			}),
		};
		const onChange = vi.fn();
		const store = createFetches({ api, onChange, timers });

		expect(await store.start({ mode: 'simple' })).toBe('d');
		expect(store.state.items.map((x) => x.id)).toEqual(['d', 'a', 'b', 'c']);
		expect(store.state.active).toBe(2);
		await store.refresh();
		await store.clearFinished();
		expect(api.cancelFetch.mock.calls.map(([id]) => id)).toEqual(['a', 'b']);
		expect(store.state.items.map((x) => x.id)).toEqual(['c']);
		expect(onChange).toHaveBeenCalled();
	});
});

describe('the form and the list', () => {
	const form = (extra = {}) => ({
		url: ' https://a.example/f ',
		command: "curl 'https://a.example/f'",
		headers: [
			{ name: ' X-Key ', value: 'k' },
			{ name: '', value: '' },
		],
		auth: { type: 'bearer', username: 'u', password: 'p', token: 'tok' },
		name: ' f.zip ',
		onConflict: 'rename',
		allowPrivate: true,
		...extra,
	});

	it('sends only the fields of the mode and the chosen authentication', () => {
		expect(requestBody('simple', form(), { path: 'tmp', canPrivate: false })).toEqual({
			mode: 'simple',
			path: 'tmp',
			name: 'f.zip',
			onConflict: 'rename',
			allowPrivate: false,
			url: 'https://a.example/f',
		});
		expect(requestBody('curl', form(), { path: '', canPrivate: true })).toEqual({
			mode: 'curl',
			path: '',
			name: 'f.zip',
			onConflict: 'rename',
			allowPrivate: true,
			command: "curl 'https://a.example/f'",
		});
		const custom = requestBody('custom', form(), { path: '', canPrivate: false });
		expect(custom.headers).toEqual([{ name: 'X-Key', value: 'k' }]);
		expect(custom.auth).toEqual({ type: 'bearer', token: 'tok' });
		expect(requestBody('custom', form({ auth: { type: 'basic', username: 'u', password: 'p', token: 't' } }), { path: '', canPrivate: false }).auth).toEqual({
			type: 'basic',
			username: 'u',
			password: 'p',
		});
		expect(requestBody('custom', form({ auth: { type: 'none', username: 'u', password: 'p', token: 't' } }), { path: '', canPrivate: false }).auth).toEqual({ type: 'none' });
	});

	it('describes states, sizes, speeds and reasons', () => {
		expect(describeFetch(item('a', 'queued'), t, formatSize)).toBe('Waiting');
		expect(describeFetch(item('a', 'running', { bytes: 1536, total: 3072 }), t, formatSize, 2048)).toBe('1.5 KB / 3 KB · 2 KB/s');
		expect(describeFetch(item('a', 'running', { bytes: 1536 }), t, formatSize)).toBe('1.5 KB');
		expect(describeFetch(item('a', 'done', { bytes: 10 }), t, formatSize)).toBe('Done · 10 B');
		expect(describeFetch(item('a', 'failed', { error: 'http', http: 404 }), t, formatSize)).toBe('The server answered 404');
		for (const error of ['blocked', 'bad-url', 'dns', 'redirects', 'too-large', 'disk', 'timeout', 'exists', 'interrupted', 'network']) {
			expect(errorText({ error }, t)).not.toBe('');
		}
		expect(errorText({ error: 'blocked' }, t)).toContain('private');
		expect([tone(item('a', 'done')), tone(item('a', 'failed')), tone(item('a', 'queued'))]).toEqual(['ok', 'danger', null]);
		expect(label(item('a', 'running'), t)).toBe('Downloading');
		expect([percent(item('a', 'done')), percent(item('a', 'running', { percent: 40 })), percent(item('a', 'running'))]).toEqual([100, 40, null]);
	});
});
