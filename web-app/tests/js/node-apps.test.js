import { describe, expect, it } from 'vitest';
import {
	appStatus,
	composeStart,
	defaultName,
	envChanged,
	envErrors,
	formatUptime,
	isAppName,
	isRelativePath,
	isSecretReference,
	isStart,
	mergeLog,
	parseStart,
	secretReference,
	templateFor,
} from '../../resources/js/islands/node-apps/model.js';
import { taskLabel } from '../../resources/js/islands/node-manager/model.js';
import { translator } from '../../resources/js/islands/shared/strings.js';

describe('names, paths and start commands (as func/nodeapp.sh)', () => {
	it('accepts what the commands accept', () => {
		for (const name of ['api', 'shop.example.com', 'a-b_c']) {
			expect(isAppName(name), name).toBe(true);
		}
		for (const path of ['.', 'public_html', 'app/server', 'dist/@scope']) {
			expect(isRelativePath(path), path).toBe(true);
		}
		for (const start of ['server.js', 'dist/index.mjs', 'npm:start', 'npm:start:prod']) {
			expect(isStart(start), start).toBe(true);
		}
	});

	it('refuses paths outside the domain and odd values', () => {
		for (const name of ['Api', '-x', 'a b', 'x'.repeat(41)]) {
			expect(isAppName(name), name).toBe(false);
		}
		for (const path of ['/etc', '../x', 'a/../b', 'a//b', 'a/./b', '.hidden', 'a b']) {
			expect(isRelativePath(path), path).toBe(false);
		}
		for (const start of ['.', '../server.js', 'npm:', 'npm:start;id', 'npm:-x']) {
			expect(isStart(start), start).toBe(false);
		}
	});

	it('splits and joins a start command', () => {
		expect(parseStart('npm:start')).toEqual({ kind: 'script', value: 'start' });
		expect(parseStart('server.js')).toEqual({ kind: 'file', value: 'server.js' });
		expect(composeStart({ kind: 'script', value: 'start' })).toBe('npm:start');
		expect(composeStart({ kind: 'file', value: 'app.js' })).toBe('app.js');
	});

	it('proposes a process name like the command', () => {
		expect(defaultName('Shop.Example.com')).toBe('shop.example.com');
		expect(defaultName('xn--bcher-kva.example')).toBe('xn--bcher-kva.example');
	});
});

describe('environment table', () => {
	it('flags bad, reserved and duplicate keys and multi-line values', () => {
		const errors = envErrors([
			{ key: 'API_KEY', value: 'a' },
			{ key: '1BAD', value: 'b' },
			{ key: 'PORT', value: '3000' },
			{ key: 'API_KEY', value: 'c' },
			{ key: 'OK', value: 'x\ny' },
		]);
		expect(Object.keys(errors)).toEqual(['1', '2', '3', '4']);
		expect(errors[2]).toBe('%s is set by the panel.');
	});

	it('knows when the table changed', () => {
		const before = [{ key: 'A', value: '1' }];
		expect(envChanged(before, [{ key: 'A', value: '1' }])).toBe(false);
		expect(envChanged(before, [{ key: ' A ', value: '1' }])).toBe(false);
		expect(envChanged(before, [{ key: 'A', value: '2' }])).toBe(true);
		expect(envChanged(before, [])).toBe(true);
	});

	it('writes and recognises references to the secret store (E38)', () => {
		expect(secretReference('/shop/db')).toBe('{{secret:/shop/db}}');
		expect(isSecretReference('{{secret:/shop/db}}')).toBe(true);
		expect(isSecretReference('mysql://app:{{secret:/shop/db}}@127.0.0.1/app')).toBe(true);
		expect(isSecretReference('plain-password')).toBe(false);
		expect(isSecretReference('{{secret:}}')).toBe(false);
	});
});

describe('status, uptime and logs', () => {
	it('labels the states of an app', () => {
		expect(appStatus({ status: 'online', suspended: false })).toEqual({ tone: 'ok', label: 'Online' });
		expect(appStatus({ status: 'online', suspended: true }).label).toBe('Suspended');
		expect(appStatus({ status: 'errored', suspended: false }).tone).toBe('danger');
		expect(appStatus({ status: 'missing', suspended: false }).label).toBe('Not running');
	});

	it('formats the uptime', () => {
		expect(formatUptime(12)).toBe('12s');
		expect(formatUptime(300)).toBe('5m');
		expect(formatUptime(7500)).toBe('2h 5m');
		expect(formatUptime(3 * 86400 + 4 * 3600)).toBe('3d 4h');
		expect(formatUptime('x')).toBe('0s');
	});

	it('appends reads after an offset and starts again after a rotation', () => {
		let view = mergeLog({ lines: [], offset: null }, { lines: ['a', 'b'], offset: 4 }, null);
		expect(view).toEqual({ lines: ['a', 'b'], offset: 4 });
		view = mergeLog(view, { lines: ['c'], offset: 6 }, 4);
		expect(view.lines).toEqual(['a', 'b', 'c']);
		view = mergeLog(view, { lines: ['new'], offset: 4 }, 6);
		expect(view).toEqual({ lines: ['new'], offset: 4 });
		expect(mergeLog({ lines: ['1', '2', '3'], offset: 1 }, { lines: ['4'], offset: 2 }, 1, 2).lines).toEqual(['3', '4']);
	});
});

describe('wizard and tasks', () => {
	const templates = [{ name: 'nodejs-proxy' }, { name: 'nextjs' }];

	it('takes the proposed template when the server has it', () => {
		expect(templateFor({ template: 'nextjs' }, templates)).toBe('nextjs');
		expect(templateFor({ template: 'strapi' }, templates)).toBe('nodejs-proxy');
		expect(templateFor(null, [{ name: 'ghost' }])).toBe('ghost');
		expect(templateFor(null, [])).toBe('');
	});

	it('labels the app tasks of the shared runner', () => {
		const t = translator({});
		expect(taskLabel({ action: 'app-install', args: 'shop.example.com' }, t)).toBe('Install the dependencies of shop.example.com');
		expect(taskLabel({ action: 'app-build', args: 'shop.example.com build' }, t)).toBe('Build shop.example.com');
	});
});
