import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { data, jsonResponse, mount, unmount } from './helpers.js';

const markup = `
<div id="tray" x-data="apNotifications({ url: '/app/_notifications' })">
	<span id="count" x-text="unread"></span>
</div>
<div data-toast-region></div>`;

const list = () => ({
	notifications: [
		{ id: 1, title: 'Welcome', acknowledged: false },
		{ id: 5, title: 'Update', acknowledged: true },
		{ id: 7, title: 'Backup', acknowledged: false },
	],
});

let fetchMock;
let visibility;

beforeEach(() => {
	vi.useFakeTimers();
	visibility = 'visible';
	Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
	fetchMock = vi.fn(async (url, options = {}) => (options.method ?? 'GET') === 'GET' ? jsonResponse(list()) : jsonResponse({ ok: true }));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	unmount();
	vi.useRealTimers();
	vi.unstubAllGlobals();
	delete document.visibilityState;
	document.head.innerHTML = '';
});

const tray = () => data(document.getElementById('tray'));

describe('apNotifications', () => {
	it('loads the list and counts unread notifications', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		expect(fetchMock).toHaveBeenCalledWith('/app/_notifications', expect.objectContaining({ method: 'GET' }));
		expect(tray().items).toHaveLength(3);
		expect(tray().loaded).toBe(true);
		expect(tray().unread).toBe(2);
		expect(document.getElementById('count').textContent).toBe('2');
	});

	it('polls every 60 s only while the tab is visible', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		expect(fetchMock).toHaveBeenCalledTimes(1);

		await vi.advanceTimersByTimeAsync(60000);
		expect(fetchMock).toHaveBeenCalledTimes(2);

		visibility = 'hidden';
		await vi.advanceTimersByTimeAsync(120000);
		expect(fetchMock).toHaveBeenCalledTimes(2);

		visibility = 'visible';
		document.dispatchEvent(new Event('visibilitychange'));
		await vi.advanceTimersByTimeAsync(0);
		expect(fetchMock).toHaveBeenCalledTimes(3);
	});

	it('stops polling when destroyed', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		unmount();
		await vi.advanceTimersByTimeAsync(180000);
		document.dispatchEvent(new Event('visibilitychange'));
		expect(fetchMock).toHaveBeenCalledTimes(1);
	});

	it('keeps the last list when a poll fails', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		fetchMock.mockResolvedValueOnce(jsonResponse({ error: { message: 'down' } }, 500));
		await vi.advanceTimersByTimeAsync(60000);
		expect(tray().items).toHaveLength(3);
	});

	it('acknowledges with a POST that carries the CSRF token', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		const item = tray().items[0];
		await tray().acknowledge(item);

		const [url, options] = fetchMock.mock.calls.at(-1);
		expect(url).toBe('/app/_notifications/1/acknowledge');
		expect(options.method).toBe('POST');
		expect(options.headers['X-CSRF-TOKEN']).toBe('csrf-123');
		expect(tray().items[0].acknowledged).toBe(true);
		expect(tray().unread).toBe(1);
	});

	it('deletes with DELETE and drops the item', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		await tray().remove(tray().items[2]);

		const [url, options] = fetchMock.mock.calls.at(-1);
		expect(url).toBe('/app/_notifications/7');
		expect(options.method).toBe('DELETE');
		expect(options.headers['X-CSRF-TOKEN']).toBe('csrf-123');
		expect(tray().items.map((item) => item.id)).toEqual([1, 5]);
	});

	it('shows an error toast when an action fails', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		fetchMock.mockResolvedValueOnce(jsonResponse({ error: { code: 'E_NOTEXIST', message: 'Notification gone' } }, 404));
		await tray().remove(tray().items[0]);
		expect(tray().items).toHaveLength(3);
		expect(document.querySelector('[data-toast-region] .ap-toast b').textContent).toBe('Notification gone');

		fetchMock.mockResolvedValueOnce(jsonResponse({ error: { message: 'No' } }, 403));
		await tray().acknowledge(tray().items[0]);
		expect(tray().items[0].acknowledged).toBe(false);
	});

	it('marks every notification as read with one POST', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		await tray().acknowledgeAll();

		const [url, options] = fetchMock.mock.calls.at(-1);
		expect(url).toBe('/app/_notifications/acknowledge');
		expect(options.method).toBe('POST');
		expect(options.headers['X-CSRF-TOKEN']).toBe('csrf-123');
		expect(tray().unread).toBe(0);
		expect(tray().items).toHaveLength(3);
	});

	it('deletes every notification with one DELETE', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		await tray().removeAll();

		const [url, options] = fetchMock.mock.calls.at(-1);
		expect(url).toBe('/app/_notifications');
		expect(options.method).toBe('DELETE');
		expect(tray().items).toEqual([]);
	});

	it('keeps the list when "delete all" fails', async () => {
		mount(markup);
		await vi.advanceTimersByTimeAsync(0);
		fetchMock.mockResolvedValueOnce(jsonResponse({ error: { message: 'Read only' } }, 403));
		await tray().removeAll();
		expect(tray().items).toHaveLength(3);
		expect(document.querySelector('[data-toast-region] .ap-toast b').textContent).toBe('Read only');
	});

	it('toggles and closes the tray', () => {
		mount(markup);
		tray().toggle();
		expect(tray().open).toBe(true);
		tray().toggle();
		expect(tray().open).toBe(false);
		tray().toggle();
		tray().close();
		expect(tray().open).toBe(false);
	});
});
