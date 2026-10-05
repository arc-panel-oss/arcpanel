import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { lineLevel } from '../../resources/js/alpine/log.js';
import { data, jsonResponse, mount, tick, unmount } from './helpers.js';

const markup = `
<div id="log" x-data="apLog({ url: '/app/web/domains/bob/a.com/logs/access' })">
	<button id="refresh" x-on:click="refresh()">Refresh</button>
	<div x-ref="body" class="ap-log-body"><div class="ap-log-line">old line</div></div>
</div>`;

let fetchMock;

beforeEach(() => {
	fetchMock = vi.fn(async () =>
		jsonResponse({ lines: ['1.2.3.4 - - "GET / HTTP/1.1" 200 12', '[error] PHP Fatal error', '<b>not html</b>'] }),
	);
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	unmount();
	vi.unstubAllGlobals();
});

describe('lineLevel', () => {
	it('classifies errors and warnings', () => {
		expect(lineLevel('[error] upstream timed out')).toBe('is-error');
		expect(lineLevel('PHP Fatal error: oops')).toBe('is-error');
		expect(lineLevel('1.2.3.4 - - "GET /x HTTP/1.1" 502 0')).toBe('is-error');
		expect(lineLevel('[warn] conflicting server name')).toBe('is-warn');
		expect(lineLevel('1.2.3.4 - - "GET /x HTTP/1.1" 404 0')).toBe('is-warn');
		expect(lineLevel('1.2.3.4 - - "GET /x HTTP/1.1" 200 512')).toBe('');
	});
});

describe('apLog', () => {
	it('replaces the lines with the refreshed ones', async () => {
		mount(markup);
		document.getElementById('refresh').click();
		await tick();

		expect(fetchMock.mock.calls[0][0]).toBe('/app/web/domains/bob/a.com/logs/access');
		const lines = [...document.querySelectorAll('.ap-log-line')];
		expect(lines.map((line) => line.textContent)).toEqual([
			'1.2.3.4 - - "GET / HTTP/1.1" 200 12',
			'[error] PHP Fatal error',
			'<b>not html</b>',
		]);
		expect(lines.map((line) => line.className)).toEqual(['ap-log-line', 'ap-log-line is-error', 'ap-log-line']);
		expect(document.querySelector('.ap-log-body b')).toBeNull();
		expect(data(document.getElementById('log')).loading).toBe(false);
	});

	it('keeps the lines and reports the error when refreshing fails', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ error: { message: 'Log unavailable' } }, 500));
		mount(markup);
		await data(document.getElementById('log')).refresh();
		expect(data(document.getElementById('log')).error).toBe('Log unavailable');
		expect(document.querySelector('.ap-log-line').textContent).toBe('old line');
	});

	it('scrolls to the newest line', async () => {
		mount(markup);
		const body = document.querySelector('.ap-log-body');
		Object.defineProperty(body, 'scrollHeight', { configurable: true, value: 500 });
		await tick();
		expect(body.scrollTop).toBe(500);
	});
});
