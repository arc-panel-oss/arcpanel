import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { initAutoSubmit } from '../../resources/js/autosubmit.js';

beforeAll(() => {
	initAutoSubmit(document);
});

afterEach(() => {
	document.body.innerHTML = '';
});

describe('initAutoSubmit', () => {
	it('submits the form of a select marked data-ap-autosubmit when it changes', () => {
		document.body.innerHTML = `<form id="f"><select name="owner" data-ap-autosubmit><option>a</option><option>b</option></select></form>`;
		const form = document.getElementById('f');
		form.requestSubmit = vi.fn();
		const select = form.querySelector('select');
		select.value = 'b';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(form.requestSubmit).toHaveBeenCalledTimes(1);
	});

	it('leaves other fields alone', () => {
		document.body.innerHTML = `<form id="f"><select name="owner"><option>a</option></select></form>`;
		const form = document.getElementById('f');
		form.requestSubmit = vi.fn();
		form.querySelector('select').dispatchEvent(new Event('change', { bubbles: true }));
		expect(form.requestSubmit).not.toHaveBeenCalled();
	});
});
