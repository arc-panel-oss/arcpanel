import { describe, expect, it, vi } from 'vitest';
import {
	isPackageName,
	isPackageSpec,
	packageName,
	quickPackages,
	releaseStatus,
	taskLabel,
} from '../../resources/js/islands/node-manager/model.js';
import { translator } from '../../resources/js/islands/shared/strings.js';
import { createTaskPoller } from '../../resources/js/islands/shared/task-poller.js';

describe('package specs (as func/node.sh)', () => {
	it('accepts names, scoped names, versions, ranges and dist-tags', () => {
		for (const spec of ['pm2', 'yarn@1.22.22', '@nestjs/cli', '@nestjs/cli@^11', 'is-number@>=6', 'next@canary']) {
			expect(isPackageSpec(spec), spec).toBe(true);
		}
	});

	it('refuses URLs, git, files, paths and odd names', () => {
		for (const spec of ['https://example.invalid/x.tgz', 'git+ssh://x', 'file:x', '../x', 'a b', 'UPPER', '@scope', 'x@', '.hidden', 'x@1;id']) {
			expect(isPackageSpec(spec), spec).toBe(false);
		}
	});

	it('takes the name of a spec, keeping the scope', () => {
		expect(packageName('@nestjs/cli@11')).toBe('@nestjs/cli');
		expect(packageName('pm2@5')).toBe('pm2');
		expect(isPackageName('@nestjs/cli')).toBe(true);
		expect(isPackageName('@nestjs/cli@11')).toBe(false);
	});
});

describe('page model', () => {
	const t = translator({ 'Install Node.js %s': 'Installer Node.js %s' });

	it('labels release states', () => {
		expect(releaseStatus('active')).toEqual({ tone: 'ok', label: 'Active LTS' });
		expect(releaseStatus('eol').tone).toBe('danger');
		expect(releaseStatus('unknown').label).toBe('');
	});

	it('describes tasks with translated strings', () => {
		expect(taskLabel({ action: 'add-version', args: '24' }, t)).toBe('Installer Node.js 24');
		expect(taskLabel({ action: 'add-package', args: '24.21.0 pm2' }, t)).toBe('Install pm2');
		expect(taskLabel({ action: 'check-outdated', args: '24.21.0' }, t)).toBe('Check the packages of Node.js 24.21.0 for updates');
	});

	it('offers quick installs that are not installed yet', () => {
		expect(quickPackages([{ name: 'npm' }, { name: 'yarn' }])).toEqual(['pnpm', 'pm2']);
	});
});

describe('translator', () => {
	it('falls back to the key and fills placeholders in order', () => {
		const t = translator({ '%d of %s runtimes': '%d trên %s' });
		expect(t('%d of %s runtimes', 2, 'unlimited')).toBe('2 trên unlimited');
		expect(t('Missing %s', 'key')).toBe('Missing key');
	});
});

describe('createTaskPoller', () => {
	it('polls while the task runs and reports the end once', async () => {
		vi.useFakeTimers();
		const answers = [{ status: 'running' }, { status: 'running' }, { status: 'done' }];
		const fetchTask = vi.fn(async () => answers.shift());
		const onUpdate = vi.fn();
		const onFinish = vi.fn();
		const poller = createTaskPoller({ fetchTask, onUpdate, onFinish, interval: 1000 });

		poller.start();
		poller.start();
		await vi.advanceTimersByTimeAsync(3500);

		expect(fetchTask).toHaveBeenCalledTimes(3);
		expect(onUpdate).toHaveBeenCalledTimes(3);
		expect(onFinish).toHaveBeenCalledWith({ status: 'done' });
		expect(onFinish).toHaveBeenCalledTimes(1);
		expect(poller.running).toBe(false);
		vi.useRealTimers();
	});

	it('keeps polling after an error and stops on request', async () => {
		vi.useFakeTimers();
		const fetchTask = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue({ status: 'running' });
		const onError = vi.fn();
		const poller = createTaskPoller({ fetchTask, onError, interval: 1000 });

		poller.start();
		await vi.advanceTimersByTimeAsync(2500);
		expect(onError).toHaveBeenCalledTimes(1);
		expect(fetchTask).toHaveBeenCalledTimes(2);

		poller.stop();
		await vi.advanceTimersByTimeAsync(5000);
		expect(fetchTask).toHaveBeenCalledTimes(2);
		vi.useRealTimers();
	});
});
