import { describe, expect, it } from 'vitest';
import { appPath, pageUrl } from '../../resources/js/islands/docker-apps/api.js';
import {
	appStatus,
	canOpenStore,
	categoriesOf,
	defaultAppName,
	filterCatalog,
	formatMb,
	formErrors,
	generateSecret,
	isAppName,
	mergeLogs,
	mergeTask,
	shownValue,
	varError,
} from '../../resources/js/islands/docker-apps/model.js';

describe('names (as func/docker.sh)', () => {
	it('accepts what the commands accept', () => {
		for (const name of ['kuma', 'n8n', 'my-app-2', 'ab']) {
			expect(isAppName(name), name).toBe(true);
		}
		for (const name of ['Kuma', '2app', 'a', 'app-', 'a_b', 'x'.repeat(33)]) {
			expect(isAppName(name), name).toBe(false);
		}
	});

	it('proposes the slug, then a free numbered name', () => {
		expect(defaultAppName('uptime-kuma')).toBe('uptime-kuma');
		expect(defaultAppName('redis', ['redis'])).toBe('redis-2');
		expect(defaultAppName('redis', ['redis', 'redis-2'])).toBe('redis-3');
	});

	it('builds page and API paths', () => {
		expect(pageUrl('/docker/bob/apps/__NAME__', 'kuma')).toBe('/docker/bob/apps/kuma');
		expect(pageUrl('/docker/bob/store/__SLUG__', 'uptime-kuma')).toBe('/docker/bob/store/uptime-kuma');
		expect(appPath('kuma', '/logs')).toBe('/apps/kuma/logs');
	});
});

describe('variables (as docker_var_check)', () => {
	const text = { name: 'TITLE', type: 'string' };

	it('refuses quotes, $, backticks, backslashes and line breaks', () => {
		for (const value of ["it's", 'a"b', 'a$b', 'a`b', 'a\\b', 'a\nb', 'x'.repeat(1025)]) {
			expect(varError(text, value), value).toBe('At most 1024 characters, without quotes, $, ` or \\.');
		}
		expect(varError(text, 'Europe/Berlin user@example.com')).toBeNull();
		expect(varError(text, '')).toBeNull();
	});

	it('checks types, options and patterns', () => {
		expect(varError({ type: 'int' }, '12')).toBeNull();
		expect(varError({ type: 'int' }, '1.5')).toBe('A whole number.');
		expect(varError({ type: 'bool' }, 'yes')).toBe('true or false.');
		expect(varError({ type: 'enum', options: ['a', 'b'] }, 'c')).toBe('Choose one of the values.');
		expect(varError({ type: 'password' }, 'short')).toBe('At least 8 characters.');
		expect(varError({ type: 'email' }, 'nobody')).toBe('An email address.');
		expect(varError({ type: 'url' }, 'ftp://x')).toBe('An http or https URL.');
		expect(varError({ type: 'string', pattern: '^[a-z]+$' }, 'ABC')).toBe('The value has the wrong format.');
		expect(varError({ type: 'string', pattern: '(?<bad' }, 'abc')).toBeNull();
	});

	it('asks for required values without a default', () => {
		const variables = [
			{ name: 'TZ', type: 'string', required: true, default: 'UTC' },
			{ name: 'ADMIN_PASSWORD', type: 'password', required: true, default: '' },
			{ name: 'DB_PASSWORD', type: 'password', required: true, default: '', random: true },
			{ name: 'NOTE', type: 'string', required: false, default: '' },
		];
		expect(formErrors(variables, { TZ: '', ADMIN_PASSWORD: '', DB_PASSWORD: '', NOTE: '' })).toEqual({
			ADMIN_PASSWORD: 'This value is required.',
		});
		expect(formErrors(variables, { ADMIN_PASSWORD: 'long-enough-1' })).toEqual({});
	});

	it('generates secrets from letters and digits', () => {
		const secret = generateSecret(32);
		expect(secret).toMatch(/^[A-Za-z0-9]{32}$/);
		expect(generateSecret(32)).not.toBe(secret);
	});
});

describe('values shown in the settings (E38)', () => {
	it('shows plain values and references to the secret store, never a stored secret', () => {
		expect(shownValue({ secret: false, value: 'hello' })).toBe('hello');
		expect(shownValue({ secret: true, value: '' })).toBe('');
		expect(shownValue({ secret: true, value: '{{secret:/shop/db}}' })).toBe('{{secret:/shop/db}}');
		// a password that only contains a reference stays hidden
		expect(shownValue({ secret: true, value: 'hunter2pw-{{secret:/x}}' })).toBe('');
	});
});

describe('store link', () => {
	it('offers the store only with the engine, the package and the right to install', () => {
		expect(canOpenStore({ engine: true, available: true, manage: true })).toBe(true);
		expect(canOpenStore({ engine: true, available: false, manage: true })).toBe(false);
		expect(canOpenStore({ engine: false, available: true, manage: true })).toBe(false);
		expect(canOpenStore({ engine: true, available: true, manage: false })).toBe(false);
		expect(canOpenStore(undefined)).toBe(false);
	});
});

describe('status, catalog and logs', () => {
	it('maps app states to badges', () => {
		expect(appStatus({ status: 'running', suspended: false })).toEqual({ tone: 'ok', label: 'running' });
		expect(appStatus({ status: 'running', suspended: true })).toEqual({ tone: 'warn', label: 'suspended' });
		expect(appStatus({ status: 'installing' })).toEqual({ tone: 'info', label: 'installing' });
		expect(appStatus({ status: 'stopped' })).toEqual({ tone: null, label: 'stopped' });
		expect(appStatus({ status: 'degraded' })).toEqual({ tone: 'warn', label: 'degraded' });
		expect(appStatus({ status: 'failed' })).toEqual({ tone: 'danger', label: 'failed' });
		expect(appStatus({ status: '' })).toEqual({ tone: 'danger', label: 'missing' });
	});

	it('filters the catalog by words and category', () => {
		const apps = [
			{ slug: 'uptime-kuma', name: 'Uptime Kuma', summary: 'Monitoring', category: 'monitoring' },
			{ slug: 'gitea', name: 'Gitea', summary: 'Git hosting', category: 'dev' },
			{ slug: 'redis', name: 'Redis', summary: 'Cache', category: 'database' },
		];
		expect(filterCatalog(apps, 'git').map((app) => app.slug)).toEqual(['gitea']);
		expect(filterCatalog(apps, 'UPTIME monitor').map((app) => app.slug)).toEqual(['uptime-kuma']);
		expect(filterCatalog(apps, '', 'database').map((app) => app.slug)).toEqual(['redis']);
		expect(filterCatalog(apps, 'git', 'database')).toEqual([]);
		expect(categoriesOf(apps)).toEqual(['monitoring', 'dev', 'database']);
	});

	it('replaces the log on a first read and appends after the cursor', () => {
		const first = mergeLogs({ lines: [], next: '' }, { lines: [{ text: 'a' }, { text: 'b' }], next: 't2' }, null);
		expect(first).toEqual({ lines: [{ text: 'a' }, { text: 'b' }], next: 't2' });
		const more = mergeLogs(first, { lines: [{ text: 'c' }], next: 't3' }, 't2', 2);
		expect(more).toEqual({ lines: [{ text: 'b' }, { text: 'c' }], next: 't3' });
		expect(mergeLogs(more, { lines: [], next: '' }, 't3').next).toBe('t3');
	});

	it('appends task output read after an offset', () => {
		const first = mergeTask({}, { status: 'running', log: 'one\n', offset: 4 }, 0);
		expect(first.log).toBe('one\n');
		expect(mergeTask(first, { status: 'done', log: 'two\n', offset: 8 }, 4)).toEqual({ status: 'done', log: 'one\ntwo\n', offset: 8 });
	});

	it('formats sizes', () => {
		expect(formatMb(512)).toBe('512 MB');
		expect(formatMb(2048)).toBe('2 GB');
		expect(formatMb(1536)).toBe('1.5 GB');
	});
});

describe('install form', () => {
	it('renders a field per variable and refuses a bad name before posting', async () => {
		const { mount } = await import('../../resources/js/islands/docker-apps/index.js');
		const element = document.createElement('div');
		document.body.append(element);
		const app = mount(element, {
			view: 'detail',
			base: '/docker/bob/api',
			strings: {},
			pages: { store: '/store', show: '/docker/bob/apps/__NAME__', domains: '/web' },
			abilities: { manage: true, remove: true, engine: true, available: true },
			domains: ['a.example', 'b.example'],
			names: ['kuma'],
			hasRoom: true,
			catalogApp: {
				slug: 'kuma', name: 'Kuma', summary: 'S', description: 'D', category: 'monitoring', version: '1.0.0',
				homepage: '', license: 'MIT', icon: '', requirements: { memory: '256M', disk: '1G' }, limits: { memory: '512M', cpus: '0.5' },
				web: true, ports: [], dumps: false,
				variables: [
					{ name: 'TZ', label: 'Time zone', type: 'string', default: 'UTC', required: true },
					{ name: 'MODE', label: 'Mode', type: 'enum', options: ['a', 'b'], default: 'a' },
					{ name: 'TOKEN', label: 'Token', type: 'password', default: '', random: true, required: true },
				],
			},
		});
		await Promise.resolve();
		expect(element.querySelector('#docker-name').value).toBe('kuma-2');
		expect(element.querySelector('#docker-var-TZ').value).toBe('UTC');
		expect([...element.querySelectorAll('#docker-var-MODE option')].map((o) => o.value)).toEqual(['a', 'b']);
		expect(element.querySelector('#docker-var-TOKEN').type).toBe('password');
		expect([...element.querySelectorAll('#docker-domain option')].map((o) => o.value)).toEqual(['a.example', 'b.example']);

		const input = element.querySelector('#docker-name');
		input.value = 'Bad Name';
		input.dispatchEvent(new Event('input'));
		element.querySelector('form').dispatchEvent(new Event('submit'));
		await new Promise((resolve) => setTimeout(resolve, 0));
		expect(element.querySelector('.ap-error').textContent).toContain('2 to 32 lower-case letters');
		app.unmount();
		element.remove();
	});
});
