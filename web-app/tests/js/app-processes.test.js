import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	defaultMemory,
	defaultName,
	filterProcesses,
	isCommand,
	isCpus,
	isProcessName,
	presetsFor,
	processPath,
	processStatus,
	processUrl,
	statusGroup,
} from '../../resources/js/islands/app-processes/model.js';
import { envErrors } from '../../resources/js/islands/node-apps/model.js';

describe('app processes model (E42)', () => {
	it('accepts the process names of func/appprocess.sh', () => {
		expect(isProcessName('web')).toBe(true);
		expect(isProcessName('queue-2')).toBe(true);
		expect(isProcessName('2web')).toBe(false);
		expect(isProcessName('Web')).toBe(false);
		expect(isProcessName('a'.repeat(33))).toBe(false);
	});

	it('refuses commands with control characters', () => {
		expect(isCommand('php artisan octane:start --port=%port%')).toBe(true);
		expect(isCommand('   ')).toBe(false);
		expect(isCommand('true\nreboot')).toBe(false);
		expect(isCommand('x'.repeat(2049))).toBe(false);
	});

	it('accepts CPUs like 1 or 0.5', () => {
		expect(isCpus('1')).toBe(true);
		expect(isCpus('0.5')).toBe(true);
		expect(isCpus('0')).toBe(false);
		expect(isCpus('lots')).toBe(false);
		expect(isCpus('1.234')).toBe(false);
	});

	it('gives every status a badge', () => {
		expect(processStatus({ status: 'running' })).toEqual({ tone: 'ok', label: 'Running' });
		expect(processStatus({ status: 'failed' })).toEqual({ tone: 'danger', label: 'Failed' });
		expect(processStatus({ status: 'running', suspended: true })).toEqual({ tone: 'warn', label: 'Suspended' });
		expect(processStatus({ status: 'missing' }).label).toBe('Not running');
	});

	it('filters the processes by domain and status group', () => {
		const processes = [
			{ domain: 'a.example', name: 'web', status: 'running' },
			{ domain: 'a.example', name: 'queue', status: 'failed' },
			{ domain: 'b.example', name: 'web', status: 'starting' },
			{ domain: 'b.example', name: 'queue', status: 'running', suspended: true },
			{ domain: 'b.example', name: 'cron', status: 'missing' },
		];
		expect(processes.map(statusGroup)).toEqual(['running', 'failed', 'running', 'suspended', 'stopped']);
		expect(filterProcesses(processes, '', '')).toHaveLength(5);
		expect(filterProcesses(processes, 'a.example', '').map((p) => p.name)).toEqual(['web', 'queue']);
		expect(filterProcesses(processes, '', 'running').map((p) => `${p.domain}/${p.name}`)).toEqual(['a.example/web', 'b.example/web']);
		expect(filterProcesses(processes, 'a.example', 'suspended')).toEqual([]);
	});

	it('builds paths and page URLs of a process', () => {
		const process = { domain: 'shop.example', name: 'web' };
		expect(processPath(process)).toBe('/shop.example/web');
		expect(processUrl('/app-processes/bob/__DOMAIN__/__NAME__', process)).toBe('/app-processes/bob/shop.example/web');
	});

	it('offers the presets of a kind, detected first, with an available template', () => {
		const presets = [
			{ name: 'gunicorn', kind: 'web', detected: 'no', templateAvailable: 'yes' },
			{ name: 'octane-swoole', kind: 'web', detected: 'yes', templateAvailable: 'yes' },
			{ name: 'rails-no-template', kind: 'web', detected: 'yes', templateAvailable: 'no' },
			{ name: 'laravel-queue', kind: 'worker', detected: 'yes', templateAvailable: 'yes' },
			{ name: 'custom', kind: 'either', detected: 'no', templateAvailable: 'yes' },
		];
		expect(presetsFor(presets, 'web').map((p) => p.name)).toEqual(['octane-swoole', 'gunicorn', 'custom']);
		expect(presetsFor(presets, 'worker').map((p) => p.name)).toEqual(['laravel-queue', 'custom']);
	});

	it('proposes a name and memory', () => {
		expect(defaultName('web', 'octane-swoole')).toBe('web');
		expect(defaultName('worker', 'laravel-horizon')).toBe('horizon');
		expect(defaultName('worker', 'custom')).toBe('worker');
		expect(defaultMemory({ maxMemory: 256, defaultMemory: 512 })).toBe(256);
		expect(defaultMemory({ maxMemory: 0, defaultMemory: 384 })).toBe(384);
	});

	it('checks the environment against the keys the runner sets', () => {
		const reserved = ['PORT', 'HOST', 'PATH', 'HOME', 'USER', 'LOGNAME'];
		expect(envErrors([{ key: 'HOME', value: 'x' }], reserved)).toEqual({ 0: '%s is set by the panel.' });
		expect(envErrors([{ key: 'NODE_ENV', value: 'x' }], reserved)).toEqual({});
		expect(envErrors([{ key: 'NODE_ENV', value: 'x' }])).toEqual({ 0: '%s is set by the panel.' });
	});
});

describe('create wizard', () => {
	afterEach(() => {
		vi.unstubAllGlobals();
		document.body.innerHTML = '';
	});

	it('links to a new web domain when the account has none', async () => {
		const options = { domains: [], presets: [], hasRoom: true, maxMemory: 0, defaultMemory: 512, maxCpus: '1' };
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => options })));
		const { mount } = await import('../../resources/js/islands/app-processes/index.js');
		const element = document.createElement('div');
		document.body.append(element);
		const app = mount(element, {
			view: 'create',
			user: 'bob',
			base: '/app-processes/bob/api',
			strings: {},
			pages: { list: '/app-processes/bob', create: '/app-processes/bob/create', show: '/x/__DOMAIN__/__NAME__', domains: '/web/domains/create' },
			abilities: { manage: true, remove: true },
			readOnly: false,
		});
		await new Promise((resolve) => setTimeout(resolve, 0));
		await new Promise((resolve) => setTimeout(resolve, 0));

		expect(element.textContent).toContain('Add a web domain first; a process runs for one domain.');
		const link = [...element.querySelectorAll('a')].find((a) => a.textContent.includes('Add Web Domain'));
		expect(link?.getAttribute('href')).toBe('/web/domains/create');
		app.unmount();
	});
});
