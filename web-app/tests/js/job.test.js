import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { data, jsonResponse, mount, unmount } from './helpers.js';

// apJob: the Job page follows the log of a job by byte offset (E15 spec §6.2, D5).

const markup = (status, offset = 8, log = 'pulling\n') => `
<div id="job" x-data="apJob({ url: '/jobs/17280000007001/status', status: '${status}', offset: ${offset}, log: ${JSON.stringify(log).replace(/"/g, "'")}, reload: false })">
	<pre x-ref="log" x-text="log"></pre>
	<span id="live" x-show="active">Live</span>
</div>`;

let fetchMock;

beforeEach(() => {
	vi.useFakeTimers();
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
	fetchMock = vi.fn(async () => jsonResponse({ task: { status: 'running', log: '', offset: 8, finished: false } }));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	unmount();
	vi.useRealTimers();
	vi.unstubAllGlobals();
	document.head.innerHTML = '';
});

const job = () => data(document.getElementById('job'));

describe('apJob', () => {
	it('asks for the log from the last offset and appends each chunk', async () => {
		mount(markup('running'));
		expect(job().active).toBe(true);

		fetchMock.mockResolvedValueOnce(jsonResponse({ task: { status: 'running', log: 'extracting\n', offset: 19, finished: false } }));
		await vi.advanceTimersByTimeAsync(2000);
		expect(fetchMock).toHaveBeenCalledWith('/jobs/17280000007001/status?offset=8', expect.objectContaining({ method: 'GET' }));
		expect(job().log).toBe('pulling\nextracting\n');
		expect(job().offset).toBe(19);

		fetchMock.mockResolvedValueOnce(jsonResponse({ task: { status: 'succeeded', log: 'done\n', offset: 24, finished: true } }));
		await vi.advanceTimersByTimeAsync(2000);
		expect(fetchMock).toHaveBeenLastCalledWith('/jobs/17280000007001/status?offset=19', expect.anything());
		expect(job().log).toBe('pulling\nextracting\ndone\n');
		expect(job().status).toBe('succeeded');
		expect(job().active).toBe(false);

		const calls = fetchMock.mock.calls.length;
		await vi.advanceTimersByTimeAsync(10000);
		expect(fetchMock).toHaveBeenCalledTimes(calls);
	});

	it('keeps polling through a failed request', async () => {
		mount(markup('queued'));
		fetchMock.mockRejectedValueOnce(new Error('offline'));
		await vi.advanceTimersByTimeAsync(2000);
		expect(job().active).toBe(true);
		fetchMock.mockResolvedValueOnce(jsonResponse({ task: { status: 'cancelled', log: '', offset: 8, finished: true } }));
		await vi.advanceTimersByTimeAsync(2000);
		expect(job().status).toBe('cancelled');
		expect(job().active).toBe(false);
	});

	it('does not poll a finished job', async () => {
		mount(markup('failed'));
		await vi.advanceTimersByTimeAsync(10000);
		expect(fetchMock).not.toHaveBeenCalled();
	});
});
