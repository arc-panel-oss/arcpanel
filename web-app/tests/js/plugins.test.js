import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { autoTrim, focusFirst } from '../../resources/js/alpine/plugins.js';

beforeAll(() => {
	autoTrim();
	focusFirst();
});

afterEach(() => {
	document.activeElement?.blur?.();
	document.body.innerHTML = '';
});

const change = (field) => field.dispatchEvent(new Event('change', { bubbles: true }));
const initialized = () => document.dispatchEvent(new Event('alpine:initialized'));

describe('autoTrim', () => {
	it('trims text, email, url and search fields on change', () => {
		document.body.innerHTML = `
			<input id="text" type="text" value="  example.com  ">
			<input id="email" type="email" value=" a@b.c ">
			<input id="pw" type="password" value=" secret ">
			<input id="keep" type="text" data-no-trim value=" keep ">
			<textarea id="area"> lines </textarea>`;
		for (const field of document.querySelectorAll('input, textarea')) {
			change(field);
		}
		expect(document.getElementById('text').value).toBe('example.com');
		expect(document.getElementById('email').value).toBe('a@b.c');
		expect(document.getElementById('pw').value).toBe(' secret ');
		expect(document.getElementById('keep').value).toBe(' keep ');
		expect(document.getElementById('area').value).toBe(' lines ');
	});
});

describe('focusFirst', () => {
	it('focuses the first field with an error', () => {
		document.body.innerHTML = `
			<form data-focus-first>
				<input type="hidden" name="_token">
				<input id="domain" name="domain">
				<input id="ip" name="ip" aria-invalid="true">
			</form>`;
		initialized();
		expect(document.activeElement.id).toBe('ip');
	});

	it('focuses the first enabled field without errors', () => {
		document.body.innerHTML = `
			<form data-focus-first>
				<input type="hidden" name="_token">
				<input id="off" disabled>
				<select id="ip"><option>1</option></select>
			</form>`;
		initialized();
		expect(document.activeElement.id).toBe('ip');
	});

	it('leaves autofocus and existing focus alone', () => {
		document.body.innerHTML = `
			<input id="search">
			<form data-focus-first><input id="domain"><input id="auto" autofocus></form>`;
		initialized();
		expect(document.activeElement).toBe(document.body);

		document.body.innerHTML = `<input id="search"><form data-focus-first><input id="domain"></form>`;
		document.getElementById('search').focus();
		initialized();
		expect(document.activeElement.id).toBe('search');
	});

	it('ignores the autofocus of a closed dialog', () => {
		document.body.innerHTML = `
			<form data-focus-first><input id="domain"></form>
			<div style="display: none"><div role="alertdialog"><button id="accept" autofocus>OK</button></div></div>`;
		initialized();
		expect(document.activeElement.id).toBe('domain');
	});

	it('does nothing without a marked form', () => {
		document.body.innerHTML = '<form><input id="domain"></form>';
		initialized();
		expect(document.activeElement).toBe(document.body);
	});
});
