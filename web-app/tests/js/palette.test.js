import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { data, jsonResponse, mount, tick, unmount } from './helpers.js';

const markup = `
<div>
	<button id="trigger" type="button" x-data x-on:click="$dispatch('ap-palette')">Search</button>
	<div id="palette" x-data="apPalette({ url: '/_palette' })">
		<div x-show="open" x-ref="dialog" role="dialog" x-on:keydown="onKeydown($event)">
			<input id="q" type="text" x-model="query" x-on:input="onInput()">
			<a id="result" href="#">r</a>
		</div>
	</div>
</div>`;

const groups = [
	{ label: 'Web domains', items: [{ label: 'example-shop.com', url: '#web-1' }, { label: 'shop.example', url: '#web-2' }] },
	{ label: 'Pages', items: [{ label: 'Add web domain', url: '#add' }] },
];

const palette = () => data(document.getElementById('palette'));
const key = (target, init) => {
	const event = new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init });
	target.dispatchEvent(event);
	return event;
};

let fetchMock;

beforeEach(() => {
	fetchMock = vi.fn(async () => jsonResponse({ groups }));
	vi.stubGlobal('fetch', fetchMock);
	window.history.replaceState(null, '', window.location.pathname);
});

afterEach(() => {
	unmount();
	vi.useRealTimers();
	vi.unstubAllGlobals();
});

describe('apPalette', () => {
	it('leaves no focus in the hidden dialog when it was opened from the page', async () => {
		mount(markup);
		document.activeElement?.blur();

		key(window, { key: 'k', ctrlKey: true });
		await tick();
		expect(document.activeElement.id).toBe('q');

		palette().close();
		expect(palette().open).toBe(false);
		expect(document.activeElement).toBe(document.body);
	});

	it('opens with Ctrl+K or ⌘K, focuses the input and closes on Escape', async () => {
		mount(markup);
		document.getElementById('trigger').focus();

		const event = key(window, { key: 'k', ctrlKey: true });
		expect(event.defaultPrevented).toBe(true);
		expect(palette().open).toBe(true);
		await tick();
		expect(document.activeElement.id).toBe('q');

		key(document.getElementById('q'), { key: 'Escape' });
		expect(palette().open).toBe(false);
		expect(document.activeElement.id).toBe('trigger');

		key(window, { key: 'K', metaKey: true });
		expect(palette().open).toBe(true);
		key(window, { key: 'k', metaKey: true });
		expect(palette().open).toBe(false);

		key(window, { key: 'k' });
		expect(palette().open).toBe(false);
	});

	it('opens on the ap-palette event', () => {
		mount(markup);
		document.getElementById('trigger').click();
		expect(palette().open).toBe(true);
	});

	it('waits 150 ms and needs 2 characters before searching', async () => {
		vi.useFakeTimers();
		mount(markup);
		const p = palette();

		p.query = 'e';
		p.onInput();
		await vi.advanceTimersByTimeAsync(500);
		expect(fetchMock).not.toHaveBeenCalled();
		expect(p.groups).toEqual([]);

		p.query = ' ex ';
		p.onInput();
		await vi.advanceTimersByTimeAsync(149);
		expect(fetchMock).not.toHaveBeenCalled();
		await vi.advanceTimersByTimeAsync(1);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock.mock.calls[0][0]).toBe('/_palette?q=ex');
		expect(fetchMock.mock.calls[0][1].headers.Accept).toBe('application/json');
		await vi.advanceTimersByTimeAsync(0);
		expect(p.groups).toEqual(groups);
		expect(p.items).toHaveLength(3);
		expect(p.loading).toBe(false);

		p.query = 'x';
		p.onInput();
		expect(p.groups).toEqual([]);
	});

	it('restarts the debounce on each keystroke', async () => {
		vi.useFakeTimers();
		mount(markup);
		const p = palette();
		for (const query of ['sh', 'sho', 'shop']) {
			p.query = query;
			p.onInput();
			await vi.advanceTimersByTimeAsync(100);
		}
		await vi.advanceTimersByTimeAsync(50);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock.mock.calls[0][0]).toBe('/_palette?q=shop');
	});

	it('aborts the previous request and ignores stale answers', async () => {
		const pending = [];
		fetchMock.mockImplementation(
			(url, { signal }) =>
				new Promise((resolve) => {
					pending.push({ url, signal, resolve });
				}),
		);
		mount(markup);
		const p = palette();

		p.query = 'sh';
		const first = p.search();
		p.query = 'shop';
		const second = p.search();
		expect(pending[0].signal.aborted).toBe(true);
		expect(pending[1].signal.aborted).toBe(false);

		pending[0].resolve(jsonResponse({ groups: [{ label: 'old', items: [] }] }));
		await first;
		expect(p.groups).toEqual([]);

		pending[1].resolve(jsonResponse({ groups }));
		await second;
		expect(p.groups).toEqual(groups);
	});

	it('moves with the arrow keys and opens the active result with Enter', async () => {
		mount(markup);
		const p = palette();
		p.show();
		p.groups = groups;
		const input = document.getElementById('q');

		key(input, { key: 'ArrowDown' });
		expect(p.active).toBe(1);
		key(input, { key: 'ArrowDown' });
		key(input, { key: 'ArrowDown' });
		expect(p.active).toBe(0);
		key(input, { key: 'ArrowUp' });
		expect(p.active).toBe(2);
		expect(p.indexOf(p.groups[1].items[0])).toBe(2);

		const enter = key(input, { key: 'Enter' });
		expect(enter.defaultPrevented).toBe(true);
		expect(window.location.hash).toBe('#add');
	});

	it('lets Enter through without results and traps Tab', async () => {
		mount(markup);
		const p = palette();
		p.show();
		await tick();
		const input = document.getElementById('q');
		expect(key(input, { key: 'Enter' }).defaultPrevented).toBe(false);

		document.getElementById('result').focus();
		key(document.getElementById('result'), { key: 'Tab' });
		expect(document.activeElement.id).toBe('q');
		expect(() => p.move(1)).not.toThrow();
		expect(p.active).toBe(0);
	});

	it('opens the search page with Enter when nothing is listed (E7 batch 3 spec §12.1)', async () => {
		mount(markup.replace("apPalette({ url: '/_palette' })", "apPalette({ url: '/_palette', searchUrl: '#search' })"));
		const p = palette();
		p.show();
		await tick();
		const input = document.getElementById('q');
		p.query = 'x';
		expect(p.allResultsUrl).toBe('');
		expect(key(input, { key: 'Enter' }).defaultPrevented).toBe(false);

		p.query = 'shop & co';
		expect(p.allResultsUrl).toBe('#search?q=shop%20%26%20co');
		expect(key(input, { key: 'Enter' }).defaultPrevented).toBe(true);
		expect(window.location.hash).toBe('#search?q=shop%20%26%20co');
	});

	it('highlights the query in labels', () => {
		mount(markup);
		const p = palette();
		p.query = 'SHOP';
		expect(p.parts('example-shop.com')).toEqual([
			{ text: 'example-', mark: false },
			{ text: 'shop', mark: true },
			{ text: '.com', mark: false },
		]);
		expect(p.parts('shop')).toEqual([{ text: 'shop', mark: true }]);
		expect(p.parts('mail.example')).toEqual([{ text: 'mail.example', mark: false }]);
		p.query = '';
		expect(p.parts('abc')).toEqual([{ text: 'abc', mark: false }]);
	});

	it('shows the server error message', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ error: { code: 'E_FORBIDEN', message: 'Not allowed' } }, 403));
		mount(markup);
		const p = palette();
		p.groups = groups;
		p.query = 'shop';
		await p.search();
		expect(p.error).toBe('Not allowed');
		expect(p.groups).toEqual([]);
		expect(p.loading).toBe(false);
	});
});
