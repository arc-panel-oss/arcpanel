// PHP Manager (E41 spec §8): the extension toggles, the profile form, the
// disable_functions list and the php.ini editor island.
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { countFindings, markerLines } from '../../resources/js/islands/php-ini-editor/findings.js';
import PhpIniEditor from '../../resources/js/islands/php-ini-editor/PhpIniEditor.vue';
import { data, jsonResponse, mount, tick, unmount } from './helpers.js';

// CodeMirror needs a real layout; the editor talks to this stand-in instead.
const editor = { doc: '', options: null, saved: null };
vi.mock('../../resources/js/islands/file-manager/editor/codemirror.js', () => ({
	createEditor: (_parent, options) => {
		editor.doc = options.doc;
		editor.options = options;
		return {
			view: { stand: 'in' },
			value: () => editor.doc,
			markSaved: (content) => {
				editor.saved = content;
				options.onUpdate({ dirty: false, line: 1, column: 1 });
			},
			destroy: () => {},
		};
	},
}));
const shown = [];
const jumps = [];
vi.mock('../../resources/js/islands/php-ini-editor/markers.js', () => ({
	markersExtension: () => ['markers'],
	showMarkers: (_view, findings) => shown.push(findings),
	goToLine: (_view, line) => jumps.push(line),
}));

let fetchMock;

beforeEach(() => {
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
	fetchMock = vi.fn(async () => jsonResponse({ fpm: true, cli: false, message: 'Changes have been saved.' }));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	unmount();
	cleanup();
	shown.length = 0;
	jumps.length = 0;
	editor.options = null;
	editor.saved = null;
	vi.unstubAllGlobals();
	vi.restoreAllMocks();
});

describe('apPhpExtension', () => {
	const markup = `<div data-toast-region></div>
<div id="ext" x-data="apPhpExtension({ url: '/server/php/8.4/extensions/redis', fpm: false, cli: true })">
	<input id="fpm" type="checkbox" x-model="fpm" x-on:change="toggle('fpm')">
	<input id="cli" type="checkbox" x-model="cli" x-on:change="toggle('cli')">
</div>`;

	it('posts the new state of one SAPI and takes the answer', async () => {
		mount(markup);
		const fpm = document.getElementById('fpm');
		fpm.click();
		await tick();
		expect(fetchMock).toHaveBeenCalledTimes(1);
		const [url, init] = fetchMock.mock.calls[0];
		expect(url).toBe('/server/php/8.4/extensions/redis');
		expect(init.method).toBe('POST');
		expect(JSON.parse(init.body)).toEqual({ state: 'on', sapi: 'fpm' });
		const state = data(document.getElementById('ext'));
		expect(state.fpm).toBe(true);
		expect(state.cli).toBe(false);
		expect(state.busy).toBe(false);
	});

	it('puts the checkbox back and shows the refusal', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ error: { code: 'E_COMMAND', message: 'Error: the extension cannot be enabled together with gmagick' } }, 422));
		mount(markup);
		document.getElementById('cli').click();
		await tick();
		const state = data(document.getElementById('ext'));
		expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ state: 'off', sapi: 'cli' });
		expect(state.cli).toBe(true);
		expect(state.error).toContain('gmagick');
	});
});

describe('apPhpExtensionFilter', () => {
	it('filters the extensions by name and by state, and counts them', () => {
		mount(`<div id="filter" x-data="apPhpExtensionFilter({ items: { redis: true, apcu: false, 'pdo_mysql': true } })"></div>`);
		const filter = data(document.getElementById('filter'));
		expect(filter.count('all')).toBe(3);
		expect(filter.count('installed')).toBe(2);
		expect(filter.count('available')).toBe(1);
		expect(filter.shows('apcu')).toBe(true);

		filter.state = 'installed';
		expect(filter.shows('apcu')).toBe(false);
		expect(filter.any(['apcu'])).toBe(false);
		expect(filter.any(['apcu', 'redis'])).toBe(true);

		filter.state = 'all';
		filter.query = ' PDO ';
		expect(filter.shows('pdo_mysql')).toBe(true);
		expect(filter.shows('redis')).toBe(false);
	});
});

describe('apPhpProfile', () => {
	it('shows the fields of the chosen process manager', async () => {
		mount(`<div id="form" x-data="apPhpProfile({ pm: 'ondemand' })">
	<select id="pm" x-model="pm"><option value="ondemand">o</option><option value="dynamic">d</option><option value="static">s</option></select>
	<div id="dynamic" x-show="pm === 'dynamic'"><input id="start" name="start_servers" x-bind:disabled="pm !== 'dynamic'"></div>
	<div id="ondemand" x-show="pm === 'ondemand'"><input id="idle" name="idle_timeout" x-bind:disabled="pm !== 'ondemand'"></div>
</div>`);
		const form = data(document.getElementById('form'));
		expect(form.uses('idle_timeout')).toBe(true);
		expect(form.uses('start_servers')).toBe(false);
		expect(document.getElementById('start').disabled).toBe(true);

		const pm = document.getElementById('pm');
		pm.value = 'dynamic';
		pm.dispatchEvent(new Event('change'));
		await tick();
		expect(form.pm).toBe('dynamic');
		expect(form.uses('min_spare')).toBe(true);
		expect(document.getElementById('start').disabled).toBe(false);
		expect(document.getElementById('idle').disabled).toBe(true);
	});
});

describe('apNameList', () => {
	it('adds valid names once and removes them', () => {
		mount(`<div id="list" x-data="apNameList({ items: ['exec'] })"></div>`);
		const list = data(document.getElementById('list'));
		list.draft = 'system, exec passthru bad-name 9lives';
		list.add();
		expect(list.items).toEqual(['exec', 'system', 'passthru']);
		expect(list.draft).toBe('');
		list.remove('exec');
		expect(list.items).toEqual(['system', 'passthru']);
	});
});

describe('php.ini findings', () => {
	it('marks the lines of the findings, an error winning over a warning', () => {
		const lines = markerLines(
			[
				{ line: 3, level: 'warning' },
				{ line: 3, level: 'error' },
				{ line: 3, level: 'warning' },
				{ line: 5, level: 'warning' },
				{ line: 0, level: 'error' },
				{ line: 99, level: 'error' },
			],
			10,
		);
		expect([...lines]).toEqual([
			[3, 'error'],
			[5, 'warning'],
		]);
		expect(countFindings([{ level: 'error' }, { level: 'warning' }, { level: 'warning' }])).toEqual({ errors: 1, warnings: 2 });
	});
});

describe('php.ini editor', () => {
	const props = (overrides = {}) => ({
		props: {
		initial: {
			version: '8.4',
			sapi: 'fpm',
			file: '/etc/php/8.4/fpm/php.ini',
			content: '[PHP]\nmemory_limit = 128M\n',
			writable: true,
			checkUrl: '/server/php/8.4/ini/fpm/check',
			saveUrl: '/server/php/8.4/ini/fpm/file',
			strings: {},
			...overrides,
		},
		},
	});

	it('validates the text and marks and lists the findings', async () => {
		const findings = [{ line: 2, level: 'error', directive: 'memory_limit', message: 'memory_limit must be a size such as 128M' }];
		fetchMock.mockResolvedValueOnce(jsonResponse({ findings }));
		render(PhpIniEditor, props());
		await waitFor(() => expect(editor.options).not.toBeNull());
		expect(editor.options.language).toBe('ini');
		expect(editor.options.extensions).toEqual(['markers']);

		editor.doc = '[PHP]\nmemory_limit = lots\n';
		await fireEvent.click(screen.getByRole('button', { name: /Validate/ }));
		await screen.findByText('memory_limit must be a size such as 128M');
		expect(fetchMock.mock.calls[0][0]).toBe('/server/php/8.4/ini/fpm/check');
		expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ content: '[PHP]\nmemory_limit = lots\n' });
		expect(shown.at(-1)).toEqual(findings);

		await fireEvent.click(screen.getByRole('button', { name: 'Line 2' }));
		expect(jumps).toEqual([2]);
	});

	it('does not save a file with errors', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ findings: [{ line: 1, level: 'error', directive: '', message: 'syntax error' }] }));
		render(PhpIniEditor, props());
		await waitFor(() => expect(editor.options).not.toBeNull());
		editor.options.onUpdate({ dirty: true, line: 1, column: 1 });
		await fireEvent.click(await screen.findByRole('button', { name: /Save/ }));
		expect((await screen.findByRole('alert')).textContent).toContain('The php.ini has errors and was not saved.');
		expect(fetchMock).toHaveBeenCalledTimes(1);
	});

	it('asks before saving a file with warnings and saves it after the confirmation', async () => {
		const warning = { line: 2, level: 'warning', directive: 'arcbats.x', message: 'PHP does not know arcbats.x and ignores it' };
		fetchMock.mockResolvedValueOnce(jsonResponse({ findings: [warning] })).mockResolvedValueOnce(jsonResponse({ message: 'Changes have been saved.', findings: [warning] }));
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
		document.body.innerHTML = '<div data-toast-region></div>';
		render(PhpIniEditor, props());
		await waitFor(() => expect(editor.options).not.toBeNull());
		editor.doc = '[PHP]\narcbats.x = 1\n';
		editor.options.onUpdate({ dirty: true, line: 1, column: 1 });
		await fireEvent.click(await screen.findByRole('button', { name: /Save/ }));
		await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
		expect(confirm).toHaveBeenCalledWith('Save with 1 warnings?');
		expect(fetchMock.mock.calls[1][0]).toBe('/server/php/8.4/ini/fpm/file');
		expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ content: '[PHP]\narcbats.x = 1\n' });
		await waitFor(() => expect(editor.saved).toBe('[PHP]\narcbats.x = 1\n'));
	});

	it('is read-only without the right to change it', async () => {
		render(PhpIniEditor, props({ writable: false }));
		await waitFor(() => expect(editor.options).not.toBeNull());
		expect(editor.options.readOnly).toBe(true);
		expect(screen.queryByRole('button', { name: /Save/ })).toBeNull();
		expect(screen.getByText('Read-only')).toBeTruthy();
	});
});
