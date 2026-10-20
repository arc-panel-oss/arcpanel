// The update dialog of the Updates page (E33 phase 1 spec §8): the pre-flight
// loads when the dialog opens, and the button waits for a ready pre-flight
// and every attention item.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { data, jsonResponse, mount, tick, unmount } from './helpers.js';

const markup = `
<div id="page" x-data="apUpdateRelease({ url: '/server/updates/releases/VERSION/preflight' })"></div>`;

const ready = {
	ready: true,
	checks: [
		{ check: 'manifest', status: 'ok', message: '2.6.0 is in the verified manifest.' },
		{ check: 'attention', status: 'warn', message: 'Confirm the items.' },
	],
};

let fetchMock;
let opened;
const onModal = (event) => opened.push(event.detail);

beforeEach(() => {
	opened = [];
	window.addEventListener('ap-modal', onModal);
	fetchMock = vi.fn(async () => jsonResponse(ready));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	window.removeEventListener('ap-modal', onModal);
	unmount();
	vi.unstubAllGlobals();
});

describe('apUpdateRelease', () => {
	it('opens the dialog and loads the pre-flight of the release', async () => {
		mount(markup);
		const page = data(document.getElementById('page'));

		await page.review({ version: '2.5.3', attention: false, items: [] });
		await tick();

		expect(opened).toEqual([{ id: 'update-release' }]);
		expect(fetchMock.mock.calls[0][0]).toBe('/server/updates/releases/2.5.3/preflight');
		expect(page.checks).toHaveLength(2);
		expect(page.ready).toBe(true);
		expect(page.canUpdate).toBe(true);
	});

	it('waits for every attention item', async () => {
		mount(markup);
		const page = data(document.getElementById('page'));

		await page.review({ version: '2.6.0', attention: true, items: ['Mail moves to Dovecot 2.4.', 'Firewall rules move.'] });
		expect(page.confirmedCount).toBe(0);
		expect(page.canUpdate).toBe(false);

		page.confirmed[0] = true;
		expect(page.canUpdate).toBe(false);
		page.confirmed[1] = true;
		expect(page.confirmedCount).toBe(2);
		expect(page.canUpdate).toBe(true);
	});

	it('keeps the button off when a check failed or the pre-flight did not load', async () => {
		mount(markup);
		const page = data(document.getElementById('page'));

		fetchMock.mockResolvedValueOnce(jsonResponse({ ready: false, checks: [{ check: 'disk', status: 'fail', message: 'Too little space.' }] }));
		await page.review({ version: '2.5.3', attention: false, items: [] });
		expect(page.canUpdate).toBe(false);

		fetchMock.mockResolvedValueOnce(jsonResponse({ error: { code: 'E_NOTEXIST', message: 'No manifest.' } }, 404));
		await page.review({ version: '2.5.3', attention: false, items: [] });
		expect(page.error).toBe('No manifest.');
		expect(page.checks).toEqual([]);
		expect(page.canUpdate).toBe(false);
	});
});
