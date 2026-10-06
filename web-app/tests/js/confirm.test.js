import { afterEach, describe, expect, it, vi } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<div>
	<div id="confirm" x-data="apConfirm()" x-on:keydown="onKeydown($event)">
		<div x-show="open" x-ref="dialog" role="alertdialog">
			<h2 x-text="title"></h2>
			<p x-text="message"></p>
			<button id="cancel" type="button" x-on:click="cancel()">Cancel</button>
			<button id="accept" type="button" x-on:click="accept()" x-text="acceptLabel"></button>
		</div>
	</div>
	<form id="bulk" action="/web/domains/bulk" method="post">
		<button id="delete" name="action" value="delete" data-confirm="Delete %d domains?" data-confirm-title="Delete" data-confirm-accept="Delete domains" data-confirm-danger>Delete</button>
		<button id="rebuild" name="action" value="rebuild">Rebuild</button>
	</form>
	<form id="single" data-confirm="Suspend example.com?"><button id="suspend">Suspend</button></form>
	<input type="checkbox" name="items[]" value="bob/a.com" form="bulk" checked>
	<input type="checkbox" name="items[]" value="bob/b.com" form="bulk" checked>
	<input type="checkbox" name="items[]" value="bob/c.com" form="bulk">
</div>`;

function submit(form, submitter) {
	const event = new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter });
	form.dispatchEvent(event);
	return event;
}

const el = (id) => document.getElementById(id);

afterEach(() => unmount());

describe('apConfirm', () => {
	it('holds a submit whose button asks for confirmation', async () => {
		mount(markup);
		const dialog = data(el('confirm'));
		el('delete').focus();

		const event = submit(el('bulk'), el('delete'));
		expect(event.defaultPrevented).toBe(true);
		expect(dialog.open).toBe(true);
		expect(dialog.message).toBe('Delete 2 domains?');
		expect(dialog.title).toBe('Delete');
		expect(dialog.acceptLabel).toBe('Delete domains');
		expect(dialog.danger).toBe(true);
		await tick();
		expect(el('accept').textContent).toBe('Delete domains');
		expect(document.activeElement.id).toBe('cancel');
	});

	it('submits with the original button once accepted', async () => {
		mount(markup);
		const form = el('bulk');
		form.requestSubmit = vi.fn();
		submit(form, el('delete'));
		await tick();

		el('accept').click();
		expect(data(el('confirm')).open).toBe(false);
		expect(form.requestSubmit).toHaveBeenCalledWith(el('delete'));
		expect(form.dataset.confirmed).toBe('yes');

		// The resubmission passes and clears the flag
		const again = submit(form, el('delete'));
		expect(again.defaultPrevented).toBe(false);
		expect(form.dataset.confirmed).toBeUndefined();
	});

	it('falls back to submit() without requestSubmit', () => {
		mount(markup);
		const form = el('bulk');
		form.requestSubmit = undefined;
		form.submit = vi.fn();
		submit(form, el('delete'));
		data(el('confirm')).accept();
		expect(form.submit).toHaveBeenCalled();
	});

	it('uses the form message and default labels', () => {
		mount(markup);
		submit(el('single'), el('suspend'));
		const dialog = data(el('confirm'));
		expect(dialog.message).toBe('Suspend example.com?');
		expect(dialog.title).toBe('Are you sure?');
		expect(dialog.acceptLabel).toBe('Confirm');
		expect(dialog.danger).toBe(false);
	});

	it('lets forms without data-confirm through', () => {
		mount(markup);
		const event = submit(el('bulk'), el('rebuild'));
		expect(event.defaultPrevented).toBe(false);
		expect(data(el('confirm')).open).toBe(false);
	});

	it('cancels with the button or Escape and returns focus', async () => {
		mount(markup);
		const form = el('bulk');
		form.requestSubmit = vi.fn();
		const dialog = data(el('confirm'));

		el('delete').focus();
		submit(form, el('delete'));
		await tick();
		el('cancel').click();
		expect(dialog.open).toBe(false);
		expect(document.activeElement.id).toBe('delete');

		submit(form, el('delete'));
		await tick();
		el('accept').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
		expect(dialog.open).toBe(false);
		expect(document.activeElement.id).toBe('delete');
		expect(form.requestSubmit).not.toHaveBeenCalled();

		dialog.accept();
		expect(form.requestSubmit).not.toHaveBeenCalled();
	});

	it('stops intercepting when destroyed', () => {
		mount(markup);
		const form = el('bulk');
		const remaining = form.outerHTML;
		unmount();
		document.body.innerHTML = remaining;
		const event = submit(document.getElementById('bulk'), document.getElementById('delete'));
		expect(event.defaultPrevented).toBe(false);
	});
});
