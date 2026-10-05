import { afterEach, describe, expect, it } from 'vitest';
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
