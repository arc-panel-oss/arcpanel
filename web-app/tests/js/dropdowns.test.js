import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { initDropdowns, placeMenu } from '../../resources/js/dropdowns.js';

const markup = `
<div class="ap-table-wrap">
	<details class="ap-dropdown" id="row1"><summary id="s1">Actions</summary><div class="ap-menu"><a href="#a" id="item1">Open</a></div></details>
	<details class="ap-dropdown" id="row2"><summary id="s2">Actions</summary><div class="ap-menu"><a href="#b">Open</a></div></details>
</div>
<details class="ap-dropdown" id="columns"><summary>Columns</summary><div class="ap-menu"><label><input type="checkbox" id="col"></label></div></details>
<p id="outside">Outside</p>`;

/** Open a dropdown the way the browser does: set open, then fire toggle. */
function open(id) {
	const details = document.getElementById(id);
	details.open = true;
	details.dispatchEvent(new Event('toggle'));
	return details;
}

function rect(values) {
	return () => ({ x: 0, y: 0, width: 0, height: 0, toJSON() {}, ...values });
}

beforeAll(() => {
	initDropdowns(document);
});

afterEach(() => {
	document.body.innerHTML = '';
});

describe('dropdowns', () => {
	it('keeps one dropdown open at a time', () => {
		document.body.innerHTML = markup;
		open('row1');
		open('columns');
		expect(document.getElementById('row1').open).toBe(false);
		expect(document.getElementById('columns').open).toBe(true);
	});

	it('closes on a click outside, not on a click inside', () => {
		document.body.innerHTML = markup;
		const details = open('columns');
		document.getElementById('col').click();
		expect(details.open).toBe(true);
		document.getElementById('outside').click();
		expect(details.open).toBe(false);
	});

	it('closes on Escape and returns focus to the summary', () => {
		document.body.innerHTML = markup;
		const details = open('row1');
		document.getElementById('item1').focus();
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(details.open).toBe(false);
		expect(document.activeElement.id).toBe('s1');
	});

	it('places a table menu with fixed coordinates under its button', () => {
		document.body.innerHTML = markup;
		const details = document.getElementById('row2');
		details.querySelector('summary').getBoundingClientRect = rect({ top: 100, bottom: 132, left: 900, right: 932 });
		const menu = details.querySelector('.ap-menu');
		Object.defineProperty(menu, 'offsetWidth', { value: 220 });
		Object.defineProperty(menu, 'offsetHeight', { value: 120 });
		window.innerWidth = 1440;
		window.innerHeight = 900;
		details.open = true;
		placeMenu(details);
		expect(menu.style.position).toBe('fixed');
		expect(menu.style.top).toBe('136px');
		expect(menu.style.left).toBe('712px');

		// No room below: the menu opens upwards
		details.querySelector('summary').getBoundingClientRect = rect({ top: 820, bottom: 852, left: 900, right: 932 });
		placeMenu(details);
		expect(menu.style.top).toBe(`${820 - 4 - 120}px`);
	});

	it('flips a menu outside tables upwards with a class, and clears it on close', () => {
		document.body.innerHTML = markup;
		const details = document.getElementById('columns');
		details.querySelector('summary').getBoundingClientRect = rect({ top: 860, bottom: 892, left: 10, right: 100 });
		Object.defineProperty(details.querySelector('.ap-menu'), 'offsetHeight', { value: 200 });
		window.innerHeight = 900;
		open('columns');
		expect(details.classList.contains('is-up')).toBe(true);
		details.open = false;
		details.dispatchEvent(new Event('toggle'));
		expect(details.classList.contains('is-up')).toBe(false);
	});

	it('closes a fixed menu when the page scrolls', () => {
		document.body.innerHTML = markup;
		const details = open('row1');
		expect(details.querySelector('.ap-menu').style.position).toBe('fixed');
		window.dispatchEvent(new Event('scroll'));
		expect(details.open).toBe(false);
	});
});
