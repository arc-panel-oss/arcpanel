import { afterEach, describe, expect, it, vi } from 'vitest';
import { PREFERENCES_COOKIE } from '../../resources/js/alpine/user-menu.js';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<div id="shell" x-data="apSidebar()">
	<button id="toggle" x-ref="toggle" x-on:click="show()">Menu</button>
	<aside x-ref="sidebar" x-bind:class="{ 'is-open': open }">
		<a id="first" href="#dashboard">Dashboard</a>
	</aside>
</div>`;

const sidebar = () => data(document.getElementById('shell'));

afterEach(() => {
	unmount();
	localStorage.clear();
	delete window.matchMedia;
	delete document.documentElement.dataset.sidebar;
	// biome-ignore lint/suspicious/noDocumentCookie: test cleanup
	document.cookie = `${PREFERENCES_COOKIE}=; path=/; max-age=0`;
});

describe('apSidebar', () => {
	it('opens as a drawer and focuses the first link', async () => {
		mount(markup);
		document.getElementById('toggle').click();
		await tick();
		expect(sidebar().open).toBe(true);
		expect(document.querySelector('aside').classList.contains('is-open')).toBe(true);
		expect(document.activeElement.id).toBe('first');
	});

	it('closes on Escape and returns focus to the toggle', async () => {
		mount(markup);
		sidebar().show();
		await tick();
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(sidebar().open).toBe(false);
		expect(document.activeElement.id).toBe('toggle');
	});

	it('ignores Escape while closed', () => {
		mount(markup);
		document.getElementById('first').focus();
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(document.activeElement.id).toBe('first');
	});

	it('remembers collapsed groups', () => {
		mount(markup);
		sidebar().toggleGroup('server');
		expect(sidebar().isCollapsed('server')).toBe(true);
		expect(localStorage.getItem('ap-sidebar:collapsed')).toBe('["server"]');

		unmount();
		mount(markup);
		expect(sidebar().isCollapsed('server')).toBe(true);
		sidebar().toggleGroup('server');
		expect(sidebar().isCollapsed('server')).toBe(false);
		expect(localStorage.getItem('ap-sidebar:collapsed')).toBe('[]');
	});

	it('opens the drawer from the menu button on phones', async () => {
		mount(markup);
		sidebar().toggle();
		await tick();
		expect(sidebar().open).toBe(true);
		expect(sidebar().expanded()).toBe(true);
	});

	it('collapses to the rail on wider screens and keeps it in arc_ui', () => {
		const listeners = [];
		window.matchMedia = vi.fn(() => ({
			matches: true,
			addEventListener: (_type, fn) => listeners.push(fn),
			removeEventListener: () => {},
		}));
		mount(markup);
		expect(sidebar().expanded()).toBe(true);

		sidebar().toggle();
		expect(sidebar().rail).toBe(true);
		expect(sidebar().open).toBe(false);
		expect(sidebar().expanded()).toBe(false);
		expect(document.documentElement.dataset.sidebar).toBe('rail');
		expect(document.cookie).toContain(encodeURIComponent('"sidebar":"rail"'));

		sidebar().toggle();
		expect(document.documentElement.dataset.sidebar).toBe('full');
		expect(document.cookie).toContain(encodeURIComponent('"sidebar":"full"'));

		// Growing from a phone with the drawer open closes the drawer
		sidebar().open = true;
		for (const fn of listeners) fn({ matches: true });
		expect(sidebar().open).toBe(false);
	});

	it('starts from the rail the server rendered', () => {
		mount(markup.replace('apSidebar()', 'apSidebar({ rail: true })'));
		expect(sidebar().rail).toBe(true);
	});

	it('ignores stored garbage', () => {
		localStorage.setItem('ap-sidebar:collapsed', 'nope');
		mount(markup);
		expect(sidebar().collapsed).toEqual([]);
		unmount();
		localStorage.setItem('ap-sidebar:collapsed', '{"a":1}');
		mount(markup);
		expect(sidebar().collapsed).toEqual([]);
	});
});
