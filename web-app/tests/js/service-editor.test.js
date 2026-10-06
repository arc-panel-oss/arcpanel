import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readOption, readOptions, writeOption } from '../../resources/js/islands/service-editor/options.js';
import ServiceEditor from '../../resources/js/islands/service-editor/ServiceEditor.vue';
import { jsonResponse } from './helpers.js';

const NGINX = `user www-data;
worker_processes auto;
#worker_connections 512;
events {
	worker_connections  1024;
}
http {
	client_max_body_size 256m; # uploads
	gzip on;
	charset utf-8;
}
`;

const PHP_INI = `[PHP]
;memory_limit = 64M
memory_limit = 128M
max_execution_time=30 ; seconds
error_reporting = E_ALL & ~E_DEPRECATED
display_errors = Off
`;

describe('basic options', () => {
	it('reads the first active nginx directive and skips comments', () => {
		expect(readOption('nginx', NGINX, 'worker_connections')).toBe('1024');
		expect(readOption('nginx', NGINX, 'client_max_body_size')).toBe('256m');
		expect(readOption('nginx', NGINX, 'proxy_read_timeout')).toBeNull();
		expect(readOptions('nginx', NGINX)).toEqual({
			worker_processes: 'auto',
			worker_connections: '1024',
			client_max_body_size: '256m',
			gzip: 'on',
			charset: 'utf-8',
		});
	});

	it('writes nginx values in place and keeps spacing and comments', () => {
		const text = writeOption('nginx', NGINX, 'client_max_body_size', '1024m');
		expect(text).toContain('client_max_body_size 1024m; # uploads');
		expect(writeOption('nginx', NGINX, 'worker_connections', '2048')).toContain('worker_connections  2048;');
		expect(writeOption('nginx', NGINX, 'worker_connections', '2048')).toContain('#worker_connections 512;');
		// ";" would end the directive, a line break would add one
		expect(writeOption('nginx', NGINX, 'charset', 'utf-8; evil\nmore')).toContain('charset utf-8 evil more;');
		// a key that is not in the file is not added
		expect(writeOption('nginx', NGINX, 'proxy_read_timeout', '60s')).toBe(NGINX);
	});

	it('reads and writes ini values with comments after them', () => {
		expect(readOptions('php', PHP_INI)).toEqual({
			memory_limit: '128M',
			max_execution_time: '30',
			error_reporting: 'E_ALL & ~E_DEPRECATED',
			display_errors: 'Off',
		});
		expect(writeOption('php', PHP_INI, 'max_execution_time', '60')).toContain('max_execution_time=60 ; seconds');
		expect(writeOption('php', PHP_INI, 'memory_limit', '256M')).toContain(';memory_limit = 64M\nmemory_limit = 256M\n');
		expect(writeOption('mysql', 'max_connections = 200\n', 'max_connections', '300')).toBe('max_connections = 300\n');
	});
});

// CodeMirror needs a real layout; the editor talks to this stand-in instead.
const docs = [];
const hooks = [];
vi.mock('../../resources/js/islands/file-manager/editor/codemirror.js', () => ({
	createEditor: (_parent, options) => {
		const position = hooks.length;
		docs[position] = options.doc;
		hooks[position] = options;
		return {
			view: {
				dispatch: ({ changes }) => {
					docs[position] = changes.insert;
					options.onUpdate({ dirty: true, line: 1, column: 1 });
				},
			},
			value: () => docs[position],
			markSaved: () => options.onUpdate({ dirty: false, line: 1, column: 1 }),
			destroy: () => {},
		};
	},
}));

const props = (overrides = {}) => ({
	initial: {
		service: 'php',
		files: [{ index: 0, label: 'php.ini', path: '/etc/php/8.5/fpm/php.ini', arg: 'php', content: PHP_INI, writable: true }],
		options: 'php',
		language: 'ini',
		alwaysRestart: false,
		saveUrl: '/app/server/services/php/config',
		strings: {},
		...overrides,
	},
});

let fetchMock;

beforeEach(() => {
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
	document.body.innerHTML = '<div data-toast-region></div>';
	fetchMock = vi.fn(async () => jsonResponse({ message: 'Changes have been saved.' }));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	cleanup();
	docs.length = 0;
	hooks.length = 0;
	vi.unstubAllGlobals();
});

describe('service editor', () => {
	it('writes a basic option into the text and saves the changed file with restart', async () => {
		render(ServiceEditor, { props: props() });
		await waitFor(() => expect(hooks).toHaveLength(1));

		const memory = await screen.findByLabelText('memory_limit');
		expect(memory.value).toBe('128M');
		await fireEvent.update(memory, '256M');
		await fireEvent.change(memory);
		expect(docs[0]).toContain('\nmemory_limit = 256M\n');

		await fireEvent.click(screen.getByRole('button', { name: /Save/ }));
		await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
		const [url, init] = fetchMock.mock.calls[0];
		expect(url).toBe('/app/server/services/php/config');
		expect(JSON.parse(init.body)).toEqual({ index: 0, content: docs[0], restart: true });
	});

	it('saves only the changed files of a multi-file service, without restart when unchecked', async () => {
		const files = [
			{ index: 0, label: 'named.conf', path: '/etc/bind/named.conf', arg: 'bind9', content: 'a', writable: true },
			{ index: 1, label: 'named.conf.options', path: '/etc/bind/named.conf.options', arg: 'bind9-opt', content: 'b', writable: true },
		];
		render(ServiceEditor, { props: props({ service: 'bind9', files, options: null, language: '' }) });
		await waitFor(() => expect(hooks).toHaveLength(2));
		expect(screen.getAllByRole('tab')).toHaveLength(2);
		expect(screen.queryByText('Basic options')).toBeNull();

		docs[1] = 'b changed';
		hooks[1].onUpdate({ dirty: true, line: 1, column: 1 });
		await fireEvent.click(screen.getByRole('checkbox', { name: 'Restart the service after saving' }));
		await fireEvent.click(screen.getByRole('button', { name: /Save/ }));
		await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
		expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ index: 1, content: 'b changed', restart: false });
	});

	it('shows the command error and keeps the changes', async () => {
		fetchMock.mockResolvedValueOnce(
			jsonResponse({ error: { code: 'E_COMMAND', message: 'ERROR: php-fpm failed to start with new configuration.' } }, 422),
		);
		render(ServiceEditor, { props: props() });
		await waitFor(() => expect(hooks).toHaveLength(1));
		docs[0] = 'broken';
		hooks[0].onUpdate({ dirty: true, line: 1, column: 1 });
		await fireEvent.click(screen.getByRole('button', { name: /Save/ }));
		expect((await screen.findByRole('alert')).textContent).toContain('ERROR: php-fpm failed to start with new configuration.');
		expect(screen.getByRole('button', { name: /Save/ }).disabled).toBe(false);
	});

	it('has no save button for a read-only file', async () => {
		const files = [{ index: 0, label: 'crontab', path: '/etc/crontab', arg: null, content: '* * * * * root true', writable: false }];
		render(ServiceEditor, { props: props({ service: 'cron', files, options: null, language: 'shell' }) });
		await waitFor(() => expect(hooks).toHaveLength(1));
		expect(hooks[0].readOnly).toBe(true);
		expect(screen.queryByRole('button', { name: /Save/ })).toBeNull();
		expect(screen.getByText('Read-only')).toBeTruthy();
	});
});
