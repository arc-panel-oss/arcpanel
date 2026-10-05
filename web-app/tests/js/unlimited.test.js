import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, unmount } from './helpers.js';

const markup = (options = '') => `
<div id="quota" x-data="apUnlimited(${options})">
	<input id="input" type="number" name="disk" x-ref="input" value="10">
	<input id="flag" type="checkbox" name="disk_unlimited" value="1" x-on:click="toggle()">
</div>`;

afterEach(() => unmount());

describe('apUnlimited', () => {
	it('disables the number while unlimited and restores it after', () => {
		mount(markup());
		const input = document.getElementById('input');
		const quota = data(document.getElementById('quota'));

		document.getElementById('flag').click();
		expect(quota.unlimited).toBe(true);
		expect(input.disabled).toBe(true);
		expect(quota.previous).toBe('10');

		input.value = '';
		document.getElementById('flag').click();
		expect(quota.unlimited).toBe(false);
		expect(input.disabled).toBe(false);
		expect(input.value).toBe('10');
	});

	it('falls back to 0 when it started unlimited', () => {
		mount(markup('{ unlimited: true }'));
		const input = document.getElementById('input');
		input.value = '';
		data(document.getElementById('quota')).toggle();
		expect(input.value).toBe('0');
		expect(input.disabled).toBe(false);
	});

	it('finds the input without a ref', () => {
		mount(`<div id="quota" x-data="apUnlimited()"><input type="text" value="5"><button id="b" x-on:click="toggle()">u</button></div>`);
		document.getElementById('b').click();
		expect(document.querySelector('input').disabled).toBe(true);
	});
});
