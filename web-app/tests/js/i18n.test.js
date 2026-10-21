import { afterEach, describe, expect, it } from 'vitest';
import { __ } from '../../resources/js/i18n.js';

afterEach(() => {
	delete window.__ap;
	document.body.innerHTML = '';
});

describe('__', () => {
	it('returns the key without a catalog', () => {
		expect(__('Delete')).toBe('Delete');
	});

	it('translates from window.__ap.i18n', () => {
		window.__ap = { i18n: { Delete: 'Xoá', 'Delete %s?': 'Xoá %s?', Empty: '' } };
		expect(__('Delete')).toBe('Xoá');
		expect(__('Delete %s?', 'example.com')).toBe('Xoá example.com?');
		expect(__('Empty')).toBe('Empty');
		expect(__('Missing')).toBe('Missing');
	});

	it('reads the catalog from the JSON data block of the page (E39 W5 §9.2)', () => {
		document.body.innerHTML = '<script type="application/json" id="ap-i18n">{"Delete":"Xoá"}</script>';
		expect(__('Delete')).toBe('Xoá');
		expect(window.__ap.i18n).toEqual({ Delete: 'Xoá' });
	});

	it('fills placeholders in order', () => {
		expect(__('%d of %s selected', 3, 'domains')).toBe('3 of domains selected');
		expect(__('No placeholder', 'extra')).toBe('No placeholder');
	});
});
