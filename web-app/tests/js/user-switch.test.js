import { afterEach, describe, expect, it, vi } from 'vitest';
import { matchUsers } from '../../resources/js/alpine/user-switch.js';
import { data, mount, tick, unmount } from './helpers.js';

const users = [
	{ name: 'arce2ebackup', fullName: 'Backup Tester', role: 'user', suspended: false },
	{ name: 'bob', fullName: 'Bob Builder', role: 'user', suspended: false },
	{ name: 'carol', fullName: 'Carol Shop', role: 'user', suspended: true },
	{ name: 'hostco', fullName: 'Host Company', role: 'reseller', suspended: false },
	{ name: 'shopbob', fullName: '', role: 'user', suspended: false },
];

describe('matchUsers', () => {
	it('lists everyone up to the limit when nothing is typed', () => {
		expect(matchUsers(users, '', 3).items.map((user) => user.name)).toEqual(['arce2ebackup', 'bob', 'carol']);
		expect(matchUsers(users, '', 3).more).toBe(2);
	});

	it('matches name and full name without case, names that start with the text first', () => {
		expect(matchUsers(users, 'BOB', 10).items.map((user) => user.name)).toEqual(['bob', 'shopbob']);
		expect(matchUsers(users, 'shop', 10).items.map((user) => user.name)).toEqual(['shopbob', 'carol']);
		expect(matchUsers(users, 'company', 10).items.map((user) => user.name)).toEqual(['hostco']);
		expect(matchUsers(users, '  ', 10).items).toHaveLength(5);
	});

	it('reports nothing when nobody matches', () => {
		expect(matchUsers(users, 'zed', 10)).toEqual({ items: [], more: 0 });
	});
});

const markup = `
<details id="menu" x-data="apUserSwitch({ users: ${JSON.stringify(users).replaceAll('"', "'")}, limit: 3 })" x-on:toggle="opened($event)">
	<summary>Switch user</summary>
	<form id="form" x-on:submit="submit($event)">
		<input id="input" x-ref="input" name="user" x-model="query" x-on:keydown="keydown($event)">
		<ul><template x-for="(user, index) in matches.items" x-bind:key="user.name"><li x-bind:id="'u-' + user.name" role="option" x-on:click="pick(user.name)" x-text="user.name"></li></template></ul>
		<button id="submit" type="submit">Log in as</button>
	</form>
</details>`;

afterEach(() => unmount());

const component = () => data(document.getElementById('menu'));
const key = (name) => document.getElementById('input').dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }));

describe('apUserSwitch', () => {
	it('moves through the matches with the arrows and picks the active one on Enter', async () => {
		mount(markup);
		const form = document.getElementById('form');
		form.requestSubmit = vi.fn();
		key('ArrowDown');
		key('ArrowDown');
		expect(component().active).toBe(2);
		key('ArrowDown');
		expect(component().active).toBe(0);
		key('ArrowUp');
		expect(component().active).toBe(2);
		key('Enter');
		await tick();
		expect(document.getElementById('input').value).toBe('carol');
		expect(form.requestSubmit).toHaveBeenCalledOnce();
	});

	it('starts again at the first match when the text changes', async () => {
		mount(markup);
		key('ArrowDown');
		const input = document.getElementById('input');
		input.value = 'host';
		input.dispatchEvent(new Event('input'));
		await tick();
		expect(component().active).toBe(0);
		expect(component().matches.items.map((user) => user.name)).toEqual(['hostco']);
	});

	it('never submits a name that is not listed', async () => {
		mount(markup);
		const form = document.getElementById('form');
		form.requestSubmit = vi.fn();
		const input = document.getElementById('input');
		input.value = 'dave';
		input.dispatchEvent(new Event('input'));
		await tick();
		key('Enter');
		expect(form.requestSubmit).not.toHaveBeenCalled();

		const event = new Event('submit', { cancelable: true });
		form.dispatchEvent(event);
		expect(event.defaultPrevented).toBe(true);
	});

	it('submits the highlighted match from the button, a listed name as typed', async () => {
		mount(markup);
		const form = document.getElementById('form');
		const input = document.getElementById('input');
		input.value = 'hos';
		input.dispatchEvent(new Event('input'));
		await tick();
		const partial = new Event('submit', { cancelable: true });
		form.dispatchEvent(partial);
		expect(partial.defaultPrevented).toBe(false);
		expect(input.value).toBe('hostco');

		input.value = 'carol';
		const exact = new Event('submit', { cancelable: true });
		form.dispatchEvent(exact);
		expect(exact.defaultPrevented).toBe(false);
		expect(input.value).toBe('carol');
	});

	it('starts with an empty search each time the menu opens', async () => {
		mount(markup);
		const menu = document.getElementById('menu');
		component().query = 'zzz';
		menu.open = true;
		menu.dispatchEvent(new Event('toggle'));
		await tick();
		expect(component().query).toBe('');
		expect(document.getElementById('input').value).toBe('');
	});

	it('picks a user with the mouse', async () => {
		mount(markup);
		const form = document.getElementById('form');
		form.requestSubmit = vi.fn();
		await tick();
		document.getElementById('u-bob').click();
		await tick();
		expect(document.getElementById('input').value).toBe('bob');
		expect(form.requestSubmit).toHaveBeenCalledOnce();
	});
});
