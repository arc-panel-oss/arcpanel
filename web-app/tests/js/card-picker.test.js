import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const markup = (checked = 'checked') => `
<div id="picker" x-data="apCardPicker()">
	<input type="search" x-model="query">
	<label data-card data-search="wordpress php"><input type="radio" name="tpl" value="wordpress" ${checked}> WordPress</label>
	<label data-card data-search="laravel php"><input id="laravel" type="radio" name="tpl" value="laravel"> Laravel</label>
	<label data-card><input type="radio" name="tpl" value="static"> Static site</label>
</div>`;

const picker = () => data(document.getElementById('picker'));
const shown = () => [...document.querySelectorAll('[data-card]')].filter((card) => !card.hidden).map((card) => card.querySelector('input').value);

afterEach(() => unmount());

describe('apCardPicker', () => {
	it('starts with the checked card and follows changes', () => {
		mount(markup());
		expect(picker().value).toBe('wordpress');
		document.getElementById('laravel').click();
		expect(picker().value).toBe('laravel');
	});

	it('keeps the given value without a checked card', () => {
		mount(markup('').replace('apCardPicker()', "apCardPicker({ value: 'static' })"));
		expect(picker().value).toBe('static');
	});

	it('filters cards by name', async () => {
		mount(markup());
		picker().query = 'LARA';
		await tick();
		expect(shown()).toEqual(['laravel']);

		picker().query = 'static';
		await tick();
		expect(shown()).toEqual(['static']);

		picker().query = '';
		await tick();
		expect(shown()).toEqual(['wordpress', 'laravel', 'static']);
	});

	it('filters cards by category and search together, and reports no match', async () => {
		mount(
			markup()
				.replace('data-search="wordpress php"', 'data-category="php-cms" data-search="wordpress php"')
				.replace('data-search="laravel php"', 'data-category="php-framework" data-search="laravel php"')
				.replace('<label data-card>', '<label data-card data-category="static">'),
		);
		picker().category = 'php-framework';
		await tick();
		expect(shown()).toEqual(['laravel']);
		expect(picker().empty).toBe(false);

		picker().query = 'wordpress';
		await tick();
		expect(shown()).toEqual([]);
		expect(picker().empty).toBe(true);

		picker().category = '';
		await tick();
		expect(shown()).toEqual(['wordpress']);
	});
});
