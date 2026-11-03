import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const markup = (options) => `
<div id="limit" x-data="apLimit(${options})">
	<input type="hidden" name="memory" :value="value">
	<input type="text" id="amount" x-model="amount" :disabled="unlimited">
	<select id="unit" x-model="unit"><option value="M">MB</option><option value="G">GB</option></select>
	<input type="checkbox" id="inf" :checked="unlimited" x-on:change="toggle()">
</div>`;

const posted = () => document.querySelector('input[name="memory"]').value;

afterEach(() => unmount());

describe('apLimit', () => {
	it('splits a size into amount and unit and posts it whole', async () => {
		mount(markup("{ value: '20G' }"));
		await tick();
		const limit = data(document.getElementById('limit'));
		expect([limit.amount, limit.unit, limit.unlimited]).toEqual(['20', 'G', false]);
		expect(posted()).toBe('20G');
	});

	it('reads a bare number in the unit it means', async () => {
		mount(markup("{ value: '512', bare: 'M' }"));
		await tick();
		expect(data(document.getElementById('limit')).unit).toBe('M');
		expect(posted()).toBe('512M');
	});

	it('keeps a bare number bare without a unit for it', async () => {
		mount(markup("{ value: '1.5' }"));
		await tick();
		expect(posted()).toBe('1.5');
	});

	it('posts unlimited when ticked and gets the amount back after', async () => {
		mount(markup("{ value: '2G' }"));
		await tick();
		const limit = data(document.getElementById('limit'));
		limit.toggle();
		await tick();
		expect(posted()).toBe('unlimited');
		expect(document.getElementById('amount').disabled).toBe(true);
		limit.toggle();
		await tick();
		expect(posted()).toBe('2G');
	});

	it('starts unlimited with an empty amount and the first unit', async () => {
		mount(markup("{ value: 'unlimited', units: ['M', 'G'] }"));
		await tick();
		const limit = data(document.getElementById('limit'));
		expect([limit.amount, limit.unit, limit.unlimited]).toEqual(['', 'M', true]);
		limit.toggle();
		await tick();
		expect(posted()).toBe('0M');
	});

	it('posts an empty amount as empty, so the server keeps its default', async () => {
		mount(markup("{ value: '' }"));
		await tick();
		expect(posted()).toBe('');
	});

	it('keeps a value it cannot split as it is', async () => {
		mount(markup("{ value: '1 GB' }"));
		await tick();
		expect(posted()).toBe('1 GB');
	});
});
