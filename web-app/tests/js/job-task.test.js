import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchJobTask, jobAsTask, jobFetcher, jobStatusUrl } from '../../resources/js/islands/shared/job-task.js';
import { jsonResponse } from './helpers.js';

// The task panels of the islands follow their job on /jobs/{id}/status (E15 spec §6.2).

let fetchMock;

beforeEach(() => {
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
	fetchMock = vi.fn();
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	vi.unstubAllGlobals();
	document.head.innerHTML = '';
});

const job = (values) => ({
	id: '17280000007001',
	status: 'running',
	finished: false,
	exit_code: null,
	error: null,
	started_at: '2026-11-06T10:00:00',
	finished_at: null,
	log: '',
	offset: 0,
	url: '/jobs/17280000007001',
	...values,
});

describe('jobAsTask', () => {
	it('keeps the task shape of the panels', () => {
		const task = jobAsTask({ id: '17280000007001', action: 'install', log: 'old' }, job({ status: 'queued', log: 'a\n', offset: 2 }), null);
		expect(task).toMatchObject({ action: 'install', status: 'running', running: true, log: 'a\n', offset: 2, start: '2026-11-06 10:00:00', exit: '' });

		const done = jobAsTask(task, job({ status: 'succeeded', finished: true, exit_code: 0, log: 'b\n', offset: 4, finished_at: '2026-11-06T10:01:00' }), 2);
		expect(done).toMatchObject({ status: 'done', running: false, exit: '0', log: 'a\nb\n', end: '2026-11-06 10:01:00' });

		expect(jobAsTask(done, job({ status: 'cancelled', finished: true }), 4).status).toBe('failed');
		expect(jobAsTask(done, job({ status: 'failed', finished: true, exit_code: 1, error: 'pull failed' }), 4)).toMatchObject({ status: 'failed', exit: '1', error: 'pull failed' });
	});

	it('builds the status URL with and without an offset', () => {
		expect(jobStatusUrl('17280000007001')).toBe('/jobs/17280000007001/status');
		expect(jobStatusUrl('17280000007001', 0)).toBe('/jobs/17280000007001/status?offset=0');
		expect(jobStatusUrl('17280000007001', 42)).toBe('/jobs/17280000007001/status?offset=42');
	});
});

describe('jobFetcher', () => {
	it('reads the end of the log first, then from the last offset, and starts over for a new job', async () => {
		let current = { id: '17280000007001', log: 'rendered' };
		const fetchTask = jobFetcher(() => current);

		fetchMock.mockResolvedValueOnce(jsonResponse({ task: job({ log: 'tail\n', offset: 100 }) }));
		current = await fetchTask();
		expect(fetchMock).toHaveBeenLastCalledWith('/jobs/17280000007001/status', expect.objectContaining({ method: 'GET' }));
		expect(current.log).toBe('tail\n');

		fetchMock.mockResolvedValueOnce(jsonResponse({ task: job({ log: 'more\n', offset: 105 }) }));
		current = await fetchTask();
		expect(fetchMock).toHaveBeenLastCalledWith('/jobs/17280000007001/status?offset=100', expect.anything());
		expect(current.log).toBe('tail\nmore\n');

		current = { id: '17280000007002', log: '' };
		fetchMock.mockResolvedValueOnce(jsonResponse({ task: job({ id: '17280000007002', log: 'new\n', offset: 4 }) }));
		current = await fetchTask();
		expect(fetchMock).toHaveBeenLastCalledWith('/jobs/17280000007002/status', expect.anything());
		expect(current.log).toBe('new\n');
	});

	it('reads a whole log from the start', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ task: job({ status: 'failed', finished: true, log: 'all\n', offset: 4 }) }));
		const task = await fetchJobTask({ id: '17280000007001', log: 'x' });
		expect(fetchMock).toHaveBeenLastCalledWith('/jobs/17280000007001/status?offset=0', expect.anything());
		expect(task).toMatchObject({ log: 'all\n', status: 'failed', running: false });
	});
});
