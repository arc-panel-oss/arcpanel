// Snapshot mode of the file manager island (E7 batch 1 spec §10.3).
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createSnapshotApi } from '../../resources/js/islands/file-manager/api.js';

const t = (key) => key;

function stubFetch(body = {}) {
	const fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => body }));
	vi.stubGlobal('fetch', fetch);
	return fetch;
}

afterEach(() => {
	vi.unstubAllGlobals();
});

describe('snapshot api', () => {
	it('lists folders of the snapshot and restores through its own route', async () => {
		const fetch = stubFetch({ path: 'web', entries: [] });
		const api = createSnapshotApi('/files/bob/api', '4f2a91c0', t);

		await api.list('web/example.com', { sort: 'name' });
		await api.restore('web/example.com/index.php');

		expect(fetch.mock.calls[0][0]).toBe('/files/bob/api/snapshots/4f2a91c0/list?path=web%2Fexample.com');
		expect(fetch.mock.calls[0][1].method).toBe('GET');
		expect(fetch.mock.calls[1][0]).toBe('/files/bob/api/snapshots/4f2a91c0/restore');
		expect(fetch.mock.calls[1][1].method).toBe('POST');
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ path: 'web/example.com/index.php' });
	});

	it('refuses every call that would change or read files', async () => {
		const fetch = stubFetch();
		const api = createSnapshotApi('/files/bob/api', '4f2a91c0', t);

		for (const call of ['read', 'save', 'mkdir', 'rename', 'remove', 'download', 'search', 'size']) {
			await expect(api[call]('x')).rejects.toThrow('Read-only: changes are disabled.');
		}
		expect(fetch).not.toHaveBeenCalled();
		expect(typeof api.toast).toBe('function');
	});
});
