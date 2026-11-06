import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { initSubmitBusy, SUBMIT_BUSY_RESET_MS } from '../../resources/js/submit-busy.js';

beforeAll(() => {
	initSubmitBusy(document);
});

afterEach(() => {
	document.body.innerHTML = '';
	vi.useRealTimers();
});

/** Dispatch a submit event like a click on SUBMITTER would; returns the event. */
function submit(form, submitter = null) {
	const event = new Event('submit', { bubbles: true, cancelable: true });
	Object.defineProperty(event, 'submitter', { value: submitter });
	form.dispatchEvent(event);
	return event;
}

describe('initSubmitBusy', () => {
	it('marks the button that posted the form as busy, with the spinner of the loading button', () => {
		document.body.innerHTML = `<form id="f" method="post"><button id="b" class="ap-btn" type="submit"><span class="ic"></span>Save</button></form>`;
		const button = document.getElementById('b');
		const event = submit(document.getElementById('f'), button);
		expect(event.defaultPrevented).toBe(false);
		expect(button.getAttribute('aria-busy')).toBe('true');
		expect(button.getAttribute('aria-disabled')).toBe('true');
		expect(button.querySelector('.ap-spinner')).not.toBeNull();
		expect(button.textContent).toContain('Save');
	});

	it('uses the first submit button when the form was submitted without a submitter', () => {
		document.body.innerHTML = `<form id="f" method="post"><input name="x"><button id="b" type="submit">Add</button></form>`;
		submit(document.getElementById('f'));
		expect(document.getElementById('b').getAttribute('aria-busy')).toBe('true');
	});

	it('ignores a second submit of a busy form (double click)', () => {
		document.body.innerHTML = `<form id="f" method="post"><button id="b" type="submit">Delete</button></form>`;
		const form = document.getElementById('f');
		const button = document.getElementById('b');
		expect(submit(form, button).defaultPrevented).toBe(false);
		expect(submit(form, button).defaultPrevented).toBe(true);
		expect(button.querySelectorAll('.ap-spinner')).toHaveLength(1);
	});

	it('leaves a submit alone that a script or the confirmation dialog prevented', () => {
		document.body.innerHTML = `<form id="f" method="post"><button id="b" type="submit">Delete</button></form>`;
		const form = document.getElementById('f');
		form.addEventListener('submit', (event) => event.preventDefault());
		submit(form, document.getElementById('b'));
		expect(document.getElementById('b').hasAttribute('aria-busy')).toBe(false);
		expect(form.dataset.apBusy).toBeUndefined();
	});

	it('leaves GET forms, forms with another target and opted-out forms or buttons alone', () => {
		document.body.innerHTML = `
			<form id="get" method="get"><button type="submit">Filter</button></form>
			<form id="blank" method="post" target="_blank"><button type="submit">Open</button></form>
			<form id="out" method="post" data-ap-no-busy><button type="submit">Download</button></form>
			<form id="btn" method="post"><button type="submit" data-ap-no-busy>Download</button></form>`;
		for (const id of ['get', 'blank', 'out', 'btn']) {
			const form = document.getElementById(id);
			const button = form.querySelector('button');
			expect(submit(form, button).defaultPrevented).toBe(false);
			expect(button.hasAttribute('aria-busy')).toBe(false);
		}
	});

	it('restores the button when the page comes back from the history cache or after the reset delay', () => {
		vi.useFakeTimers();
		document.body.innerHTML = `<form id="f" method="post"><button id="b" type="submit"><span class="ic"></span>Save</button></form>`;
		const form = document.getElementById('f');
		const button = document.getElementById('b');
		submit(form, button);
		globalThis.dispatchEvent(new Event('pageshow'));
		expect(button.hasAttribute('aria-busy')).toBe(false);
		expect(button.hasAttribute('aria-disabled')).toBe(false);
		expect(button.querySelector('.ap-spinner')).toBeNull();
		expect(submit(form, button).defaultPrevented).toBe(false);
		vi.advanceTimersByTime(SUBMIT_BUSY_RESET_MS);
		expect(button.hasAttribute('aria-busy')).toBe(false);
		expect(form.dataset.apBusy).toBeUndefined();
	});

	it('keeps a button that was disabled before as it was', () => {
		vi.useFakeTimers();
		document.body.innerHTML = `<form id="f" method="post"><button id="b" type="submit" aria-disabled="true">Save</button></form>`;
		submit(document.getElementById('f'), document.getElementById('b'));
		vi.advanceTimersByTime(SUBMIT_BUSY_RESET_MS);
		expect(document.getElementById('b').getAttribute('aria-disabled')).toBe('true');
	});
});
