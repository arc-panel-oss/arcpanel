import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<form id="form" x-data="apFormState()" action="#" x-on:submit.prevent>
	<input type="text" id="name" name="name" value="basic">
	<input type="checkbox" id="node" name="node" value="1">
</form>`;

const change = async (el, event = 'input') => {
	el.dispatchEvent(new Event(event, { bubbles: true }));
	await tick();
};

afterEach(() => unmount());

describe('apFormState', () => {
	it('is dirty after a change and clean again when it is undone', async () => {
		mount(markup);
		await tick();
		const form = data(document.getElementById('form'));
		expect(form.dirty).toBe(false);

		const name = document.getElementById('name');
		name.value = 'business';
		await change(name);
		expect(form.dirty).toBe(true);

		name.value = 'basic';
		await change(name);
		expect(form.dirty).toBe(false);

		const node = document.getElementById('node');
		node.checked = true;
		await change(node, 'change');
		expect(form.dirty).toBe(true);
	});

	it('asks before leaving with changes, but not when they are being saved', async () => {
		mount(markup);
		await tick();
		const name = document.getElementById('name');
		name.value = 'business';
		await change(name);

		const leave = new Event('beforeunload', { cancelable: true });
		window.dispatchEvent(leave);
		expect(leave.defaultPrevented).toBe(true);

		document.getElementById('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await tick();
		const save = new Event('beforeunload', { cancelable: true });
		window.dispatchEvent(save);
		expect(save.defaultPrevented).toBe(false);
	});

	it('lets the page go without changes', async () => {
		mount(markup);
		await tick();
		const leave = new Event('beforeunload', { cancelable: true });
		window.dispatchEvent(leave);
		expect(leave.defaultPrevented).toBe(false);
	});
});
