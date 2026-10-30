import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { data, jsonResponse, mount, unmount } from './helpers.js';

// apDeploy: the live log of a running deploy and the log drawer of the Deploy tab (E20 §7).

const markup = (running) => `
<div id="deploy" x-data="apDeploy({ stateUrl: '/web/domains/bob/example.com/deploy/state',
	taskUrl: '/web/domains/bob/example.com/deploy/tasks/00000000000000', running: ${running}, lines: ['10:00:00 fetching main'] })">
	<pre id="live" x-text="lines.join('\\n')"></pre>
	<button id="open" x-on:click="openLog('17617400001234', 'Deployed a1b2c3d')">Log</button>
	<div id="drawer"><template x-for="(line, index) in drawer.lines" :key="index"><div class="line" x-text="line"></div></template>
	<p id="drawer-error" x-text="drawer.error"></p></div>
</div>`;

let fetchMock;
let reload;

beforeEach(() => {
	vi.useFakeTimers();
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
	reload = vi.fn();
	vi.stubGlobal('location', { ...window.location, reload });
});

afterEach(() => {
	unmount();
	vi.useRealTimers();
	vi.unstubAllGlobals();
	document.head.innerHTML = '';
});

const deploy = () => data(document.getElementById('deploy'));

describe('apDeploy', () => {
	it('follows the log of a running deploy and reloads the page when it ends', async () => {
		fetchMock = vi.fn(async () => jsonResponse({ running: true, lines: ['10:00:00 fetching main', '10:00:05 running the laravel preset'] }));
		vi.stubGlobal('fetch', fetchMock);
		mount(markup(true));
		expect(document.getElementById('live').textContent).toBe('10:00:00 fetching main');

		await vi.advanceTimersByTimeAsync(2000);
		expect(fetchMock).toHaveBeenCalledWith('/web/domains/bob/example.com/deploy/state', expect.objectContaining({ method: 'GET' }));
		expect(document.getElementById('live').textContent).toBe('10:00:00 fetching main\n10:00:05 running the laravel preset');
		expect(reload).not.toHaveBeenCalled();

		fetchMock.mockImplementation(async () => jsonResponse({ running: false, lines: [] }));
		await vi.advanceTimersByTimeAsync(2000);
		await vi.advanceTimersByTimeAsync(1500);
		expect(reload).toHaveBeenCalledTimes(1);
		expect(deploy().timer).toBeNull();
	});

	it('does not poll when no deploy runs, and skips a failed request', async () => {
		fetchMock = vi.fn(async () => {
			throw new TypeError('offline');
		});
		vi.stubGlobal('fetch', fetchMock);
		mount(markup(false));
		await vi.advanceTimersByTimeAsync(6000);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('loads the log of a task into the drawer', async () => {
		fetchMock = vi.fn(async () => jsonResponse({ task: { status: 'succeeded' }, lines: ['14:24:58 fetching main', '14:26:10 release is live'] }));
		vi.stubGlobal('fetch', fetchMock);
		mount(markup(false));

		document.getElementById('open').click();
		await vi.advanceTimersByTimeAsync(0);
		expect(fetchMock).toHaveBeenCalledWith('/web/domains/bob/example.com/deploy/tasks/17617400001234', expect.anything());
		expect([...document.querySelectorAll('#drawer .line')].map((el) => el.textContent)).toEqual(['14:24:58 fetching main', '14:26:10 release is live']);
		expect(deploy().drawer.title).toBe('Deployed a1b2c3d');
		expect(deploy().drawer.loading).toBe(false);
	});

	it('copies a value and names it as copied for a moment, without guarding the page', async () => {
		const writeText = vi.fn(async () => {});
		vi.stubGlobal('navigator', { clipboard: { writeText } });
		const listen = vi.spyOn(globalThis, 'addEventListener');
		mount(markup(false));

		await deploy().copy('deploy-hookurl', 'https://example.com:8088/hooks/abc');
		expect(writeText).toHaveBeenCalledWith('https://example.com:8088/hooks/abc');
		expect(deploy().copied).toBe('deploy-hookurl');
		await vi.advanceTimersByTimeAsync(1500);
		expect(deploy().copied).toBe('');
		expect(listen).not.toHaveBeenCalledWith('beforeunload', expect.anything());
	});

	it('says when the log cannot be read', async () => {
		fetchMock = vi.fn(async () => jsonResponse({ message: 'Not Found' }, 404));
		vi.stubGlobal('fetch', fetchMock);
		mount(markup(false));

		document.getElementById('open').click();
		await vi.advanceTimersByTimeAsync(0);
		expect(document.getElementById('drawer-error').textContent).not.toBe('');
		expect(deploy().drawer.lines).toEqual([]);
	});
});
