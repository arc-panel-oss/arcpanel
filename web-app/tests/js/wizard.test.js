import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, unmount } from './helpers.js';

const markup = `
<form id="wizard" x-data="apWizard()">
	<fieldset data-step><input id="name" name="name" required></fieldset>
	<fieldset data-step><input id="package" name="package"></fieldset>
	<fieldset data-step><input id="confirm" name="confirm"></fieldset>
	<button id="back" type="button" x-on:click="back()">Back</button>
	<button id="next" type="button" x-on:click="next()">Next</button>
</form>`;

const visible = () => [...document.querySelectorAll('[data-step]')].findIndex((step) => !step.hidden);
const wizard = () => data(document.getElementById('wizard'));

afterEach(() => unmount());

describe('apWizard', () => {
	it('shows only the current step', () => {
		mount(markup);
		expect(visible()).toBe(0);
		expect([...document.querySelectorAll('[data-step]')].filter((step) => !step.hidden)).toHaveLength(1);
		expect(wizard().count).toBe(3);
		expect(wizard().isFirst).toBe(true);
	});

	it('does not leave a step with an invalid field', () => {
		mount(markup);
		document.getElementById('next').click();
		expect(wizard().step).toBe(0);

		document.getElementById('name').value = 'alice';
		document.getElementById('next').click();
		expect(wizard().step).toBe(1);
		expect(visible()).toBe(1);
	});

	it('goes back and stops at both ends', () => {
		mount(markup);
		document.getElementById('name').value = 'alice';
		wizard().next();
		wizard().next();
		expect(wizard().isLast).toBe(true);
		wizard().next();
		expect(wizard().step).toBe(2);

		document.getElementById('back').click();
		expect(visible()).toBe(1);
		wizard().back();
		wizard().back();
		expect(wizard().step).toBe(0);
	});

	it('can start on a later step', () => {
		mount(markup.replace('apWizard()', 'apWizard({ start: 2 })'));
		expect(visible()).toBe(2);
	});
});
