import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { data, mount, unmount } from './helpers.js';

const markup = (attrs = '') => `
<div id="tabs" x-data="apTabs({ initial: 'ssl' })" x-on:click="onClick($event)" x-on:keydown="onKeydown($event)" ${attrs}>
	<div role="tablist">
		<a role="tab" data-tab="general" href="#general">General</a>
		<a role="tab" data-tab="ssl" href="#ssl">SSL</a>
		<a role="tab" data-tab="ftp" href="#ftp">FTP</a>
	</div>
	<section role="tabpanel" id="general">G</section>
	<section role="tabpanel" id="ssl">S</section>
	<section role="tabpanel" id="ftp">F</section>
</div>`;

const visible = () => [...document.querySelectorAll('[role="tabpanel"]')].filter((p) => !p.hidden).map((p) => p.id);
const selected = () => document.querySelector('[role="tab"][aria-selected="true"]').dataset.tab;
const tab = (name) => document.querySelector(`[data-tab="${name}"]`);
const press = (name, key) => tab(name).dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));

beforeEach(() => {
	window.history.replaceState(null, '', window.location.pathname);
});

afterEach(() => unmount());

describe('apTabs', () => {
	it('starts on the initial tab and hides the other panels', () => {
		mount(markup());
		expect(visible()).toEqual(['ssl']);
		expect(selected()).toBe('ssl');
		expect(tab('ssl').getAttribute('tabindex')).toBe('0');
		expect(tab('general').getAttribute('tabindex')).toBe('-1');
		expect(window.location.hash).toBe('');
	});

	it('starts on the tab named in the URL hash', () => {
		window.history.replaceState(null, '', '#ftp');
		mount(markup());
		expect(visible()).toEqual(['ftp']);
	});

	it('ignores an unknown hash and falls back to the first tab without an initial one', () => {
		window.history.replaceState(null, '', '#nope');
		mount(markup().replace("{ initial: 'ssl' }", '{}'));
		expect(visible()).toEqual(['general']);
	});

	it('selects a clicked tab and writes the hash', () => {
		mount(markup());
		tab('general').click();
		expect(visible()).toEqual(['general']);
		expect(window.location.hash).toBe('#general');
	});

	it('follows hash changes', () => {
		mount(markup());
		window.history.replaceState(null, '', '#ftp');
		window.dispatchEvent(new HashChangeEvent('hashchange'));
		expect(selected()).toBe('ftp');

		window.history.replaceState(null, '', '#unknown');
		window.dispatchEvent(new HashChangeEvent('hashchange'));
		expect(selected()).toBe('ftp');
	});

	it('moves with arrow keys, Home and End, wrapping around', () => {
		mount(markup());
		press('ssl', 'ArrowRight');
		expect(selected()).toBe('ftp');
		expect(document.activeElement).toBe(tab('ftp'));

		press('ftp', 'ArrowRight');
		expect(selected()).toBe('general');

		press('general', 'ArrowLeft');
		expect(selected()).toBe('ftp');

		press('ftp', 'Home');
		expect(selected()).toBe('general');

		press('general', 'End');
		expect(selected()).toBe('ftp');

		press('ftp', 'a');
		expect(selected()).toBe('ftp');
	});

	it('reverses the arrows in right-to-left layouts', () => {
		mount(markup('style="direction: rtl"'));
		press('ssl', 'ArrowRight');
		expect(selected()).toBe('general');
		press('general', 'ArrowLeft');
		expect(selected()).toBe('ssl');
	});

	it('ignores names that are not tabs', () => {
		mount(markup());
		data(document.getElementById('tabs')).select('nope');
		expect(selected()).toBe('ssl');
	});
});
