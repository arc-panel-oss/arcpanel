import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const markup = (value) => `
<div id="list" x-data="apChoiceList({ value: '${value}' })">
	<input type="hidden" name="reseller_packages" :value="value">
	<input type="checkbox" id="all" x-model="all">
	<input type="checkbox" value="default" x-model="picked">
	<input type="checkbox" value="starter" x-model="picked">
</div>`;

const posted = () => document.querySelector('input[name="reseller_packages"]').value;

afterEach(() => unmount());

describe('apChoiceList', () => {
	it('posts the ticked names, comma separated', async () => {
		mount(markup('starter'));
		await tick();
		const list = data(document.getElementById('list'));
		expect(list.picked).toEqual(['starter']);
		list.picked.push('default');
		await tick();
		expect(posted()).toBe('starter,default');
	});

	it('posts * for every name and remembers the ticks', async () => {
		mount(markup('default, starter'));
		await tick();
		const list = data(document.getElementById('list'));
		expect(list.picked).toEqual(['default', 'starter']);
		list.all = true;
		await tick();
		expect(posted()).toBe('*');
		list.all = false;
		await tick();
		expect(posted()).toBe('default,starter');
	});

	it('starts with every name for *', async () => {
		mount(markup('*'));
		await tick();
		expect(data(document.getElementById('list')).all).toBe(true);
		expect(posted()).toBe('*');
	});
});
