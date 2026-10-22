// The backup job form (E48 spec §8.2): the preview of the next runs follows
// the schedule fields, and the rows of the targets.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { data, jsonResponse, mount, tick, unmount } from './helpers.js';

const markup = (options = "{ previewUrl: '/server/backups/jobs/preview' }") => `
<form id="job" x-data="apBackupJob(${options})">
	<select name="preset" x-model="preset"><option value="hourly"></option><option value="daily"></option><option value="weekly"></option><option value="cron"></option></select>
	<input name="cron" x-model="cron">
	<ul id="times"><template x-for="time in preview.times" :key="time"><li x-text="time"></li></template></ul>
	<p id="message" x-text="preview.message"></p>
	<template x-for="(target, index) in targets" :key="index">
		<div class="target"><input :name="'targets[' + index + '][destination]'" x-model="target.destination"><button type="button" class="remove" x-on:click="removeTarget(index)">x</button></div>
	</template>
	<button type="button" id="add" x-on:click="addTarget()">Add</button>
</form>`;

let fetchMock;

// the preview waits 300 ms after the last change
const settle = async () => {
	await new Promise((resolve) => setTimeout(resolve, 350));
	await tick();
};

beforeEach(() => {
	fetchMock = vi.fn(async (url) =>
		url.includes('preset=cron') && url.includes('cron=bad')
			? jsonResponse({ valid: false, message: 'This schedule is not valid or never runs.' })
			: jsonResponse({ valid: true, describe: 'Daily at 05:10', times: ['2026-10-23 05:10', '2026-10-24 05:10'] }),
	);
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	unmount();
	vi.unstubAllGlobals();
});

describe('apBackupJob', () => {
	it('previews the next runs of the initial schedule', async () => {
		mount(markup());
		await tick();
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock.mock.calls[0][0]).toBe('/server/backups/jobs/preview?preset=daily&minute=10&hour=5');
		expect([...document.querySelectorAll('#times li')].map((li) => li.textContent)).toEqual(['2026-10-23 05:10', '2026-10-24 05:10']);
	});

	it('asks again once after the fields stop changing, and shows a refusal', async () => {
		mount(markup());
		await tick();
		const job = data(document.getElementById('job'));
		job.preset = 'cron';
		job.cron = 'b';
		job.cron = 'bad';
		await settle();
		expect(fetchMock).toHaveBeenCalledTimes(2);
		expect(fetchMock.mock.calls[1][0]).toContain('preset=cron&cron=bad');
		expect(job.preview.valid).toBe(false);
		expect(document.getElementById('message').textContent).toBe('This schedule is not valid or never runs.');
	});

	it('does not ask for an empty cron expression', async () => {
		mount(markup("{ previewUrl: '/p', preset: 'cron', cron: '' }"));
		await tick();
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('adds and removes target rows but keeps one', async () => {
		mount(markup("{ previewUrl: '', targets: [{ destination: 'local', keep_last: '7' }] }"));
		await tick();
		document.getElementById('add').click();
		await tick();
		expect(document.querySelectorAll('.target')).toHaveLength(2);
		document.querySelectorAll('.remove')[1].click();
		await tick();
		document.querySelector('.remove').click();
		await tick();
		expect(document.querySelectorAll('.target')).toHaveLength(1);
		expect(document.querySelector('.target input').value).toBe('local');
	});
});
