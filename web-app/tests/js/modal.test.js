import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<div>
	<button id="opener" type="button">Open</button>
	<div id="modal" x-data="apModal({ id: 'delete' })" x-on:keydown="onKeydown($event)">
		<div x-show="open" x-ref="dialog" role="dialog">
			<button id="first" type="button">Cancel</button>
			<button id="last" type="button">Delete</button>
		</div>
	</div>
</div>`;

const request = (detail) => window.dispatchEvent(new CustomEvent('ap-modal', { detail }));
const key = (target, init) => {
	const event = new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init });
	target.dispatchEvent(event);
	return event;
};

afterEach(() => unmount());

describe('apModal', () => {
	it('opens on its ap-modal event and focuses the dialog', async () => {
		mount(markup);
		const modal = data(document.getElementById('modal'));
		document.getElementById('opener').focus();

		request({ id: 'other' });
		expect(modal.open).toBe(false);

		request({ id: 'delete' });
		await tick();
		expect(modal.open).toBe(true);
		expect(document.activeElement.id).toBe('first');
	});

	it('closes on Escape and returns focus to the opener', async () => {
		mount(markup);
		const modal = data(document.getElementById('modal'));
		document.getElementById('opener').focus();
		request({ id: 'delete' });
		await tick();

		const event = key(document.getElementById('first'), { key: 'Escape' });
		expect(event.defaultPrevented).toBe(true);
		expect(modal.open).toBe(false);
		expect(document.activeElement.id).toBe('opener');
	});

	it('closes on request with open: false and ignores a second close', async () => {
		mount(markup);
		const modal = data(document.getElementById('modal'));
		request({ id: 'delete' });
		await tick();
		request({ id: 'delete', open: false });
		expect(modal.open).toBe(false);
		expect(() => modal.close()).not.toThrow();
	});

	it('keeps Tab inside the dialog', async () => {
		mount(markup);
		request({ id: 'delete' });
		await tick();

		document.getElementById('last').focus();
		const forward = key(document.getElementById('last'), { key: 'Tab' });
		expect(forward.defaultPrevented).toBe(true);
		expect(document.activeElement.id).toBe('first');

		const backward = key(document.getElementById('first'), { key: 'Tab', shiftKey: true });
		expect(backward.defaultPrevented).toBe(true);
		expect(document.activeElement.id).toBe('last');

		document.getElementById('first').focus();
		const inside = key(document.getElementById('first'), { key: 'Tab' });
		expect(inside.defaultPrevented).toBe(false);
	});
});
