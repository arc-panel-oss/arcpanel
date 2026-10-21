import { afterEach, describe, expect, it, vi } from 'vitest';
import { submitSso } from '../../resources/js/sso.js';

afterEach(() => {
	document.body.innerHTML = '';
});

describe('submitSso', () => {
	it('posts the single sign-on form', () => {
		document.body.innerHTML = `<form id="ap-sso" data-ap-sso method="post" action="/x"><input type="hidden" name="t" value="1"></form>`;
		const form = document.getElementById('ap-sso');
		form.submit = vi.fn();
		submitSso(document);
		expect(form.submit).toHaveBeenCalledTimes(1);
	});

	it('does nothing on a page without the form', () => {
		document.body.innerHTML = `<form id="other"></form>`;
		const form = document.getElementById('other');
		form.submit = vi.fn();
		submitSso(document);
		expect(form.submit).not.toHaveBeenCalled();
	});
});
