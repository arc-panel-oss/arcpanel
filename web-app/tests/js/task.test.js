import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { data, jsonResponse, mount, unmount } from './helpers.js';

const running = { id: '1', domain: 'example.com', app: 'DokuWiki', status: 'running', log: '$ v-add-web-domain-app' };

const markup = (task) => `
<div id="task" x-data="apTask({ url: '/web/domains/bob/example.com/install-task', task: ${JSON.stringify(task).replace(/"/g, "'")},
	doneMessage: 'DokuWiki installed successfully.', failMessage: 'Unable to install DokuWiki.' })">
	<button id="go" x-bind:disabled="running">Install</button>
</div>
<div data-toast-region></div>`;

let fetchMock;

beforeEach(() => {
	vi.useFakeTimers();
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
	fetchMock = vi.fn(async () => jsonResponse({ task: running }));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	unmount();
	vi.useRealTimers();
	vi.unstubAllGlobals();
	document.head.innerHTML = '';
});

const task = () => data(document.getElementById('task'));
const toastTitle = () => document.querySelector('[data-toast-region] .ap-toast b')?.textContent;

describe('apTask', () => {
	it('polls every 2 s while the task runs and stops when it is done', async () => {
		mount(markup(running));
		expect(task().running).toBe(true);
		expect(document.getElementById('go').disabled).toBe(true);

		await vi.advanceTimersByTimeAsync(2000);
		expect(fetchMock).toHaveBeenCalledWith('/web/domains/bob/example.com/install-task', expect.objectContaining({ method: 'GET' }));

		fetchMock.mockResolvedValueOnce(jsonResponse({ task: { ...running, status: 'done', log: 'DokuWiki installed successfully.' } }));
		await vi.advanceTimersByTimeAsync(2000);
		expect(task().running).toBe(false);
		expect(task().task.log).toBe('DokuWiki installed successfully.');
		expect(toastTitle()).toBe('DokuWiki installed successfully.');
		expect(document.getElementById('go').disabled).toBe(false);

		const calls = fetchMock.mock.calls.length;
		await vi.advanceTimersByTimeAsync(10000);
		expect(fetchMock).toHaveBeenCalledTimes(calls);
	});

	it('shows an error toast when the install failed', async () => {
		mount(markup(running));
		fetchMock.mockResolvedValueOnce(jsonResponse({ task: { ...running, status: 'failed', log: 'Missing required field: wiki_name' } }));
		await vi.advanceTimersByTimeAsync(2000);
		expect(task().task.status).toBe('failed');
		expect(toastTitle()).toBe('Unable to install DokuWiki.');
	});

	it('does not poll without a running task', async () => {
		mount(markup({ ...running, status: 'done' }));
		await vi.advanceTimersByTimeAsync(6000);
		expect(fetchMock).not.toHaveBeenCalled();

		unmount();
		mount(markup(null));
		expect(task().running).toBe(false);
		await vi.advanceTimersByTimeAsync(6000);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('keeps polling after a failed request and stops when destroyed', async () => {
		mount(markup(running));
		fetchMock.mockResolvedValueOnce(jsonResponse({ error: { message: 'down' } }, 500));
		await vi.advanceTimersByTimeAsync(2000);
		expect(task().running).toBe(true);
		await vi.advanceTimersByTimeAsync(2000);
		expect(fetchMock).toHaveBeenCalledTimes(2);

		unmount();
		await vi.advanceTimersByTimeAsync(10000);
		expect(fetchMock).toHaveBeenCalledTimes(2);
	});

	it('keeps polling through a panel restart: network errors and answers without a task', async () => {
		mount(markup(running));
		fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
		fetchMock.mockResolvedValueOnce(new Response('<html>502 Bad Gateway</html>', { status: 502 }));
		fetchMock.mockResolvedValueOnce(new Response('<html>login</html>', { status: 200 }));
		await vi.advanceTimersByTimeAsync(6000);
		expect(task().running).toBe(true);

		fetchMock.mockResolvedValueOnce(jsonResponse({ task: { ...running, status: 'done' } }));
		await vi.advanceTimersByTimeAsync(2000);
		expect(task().running).toBe(false);
		expect(fetchMock).toHaveBeenCalledTimes(4);
	});

	it('reloads the page once the task has ended when asked to', async () => {
		const reload = vi.fn();
		vi.stubGlobal('location', { ...window.location, reload });
		mount(markup(running).replace("failMessage: 'Unable to install DokuWiki.'", "failMessage: 'Unable to install DokuWiki.', reloadOnEnd: true"));
		fetchMock.mockResolvedValueOnce(jsonResponse({ task: { ...running, status: 'failed' } }));
		await vi.advanceTimersByTimeAsync(2000);
		expect(reload).not.toHaveBeenCalled();
		await vi.advanceTimersByTimeAsync(1500);
		expect(reload).toHaveBeenCalledTimes(1);
	});
});
