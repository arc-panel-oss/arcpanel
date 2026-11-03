import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<div>
	<nav id="nav" x-data="apSectionNav()">
		<a href="#one">One</a><a href="#two">Two</a><a href="#three">Three</a>
	</nav>
	<section id="one"></section><section id="two"></section><section id="three"></section>
</div>`;

/** Put the tops of the sections where a scroll would have them. */
const place = (tops) => {
	for (const [id, top] of Object.entries(tops)) {
		document.getElementById(id).getBoundingClientRect = () => ({ top });
	}
};

afterEach(() => unmount());

describe('apSectionNav', () => {
	it('marks the first section before any scroll', async () => {
		mount(markup);
		await tick();
		const nav = data(document.getElementById('nav'));
		place({ one: 200, two: 800, three: 1400 });
		nav.update();
		expect(nav.current).toBe('one');
	});

	it('marks the last section whose top has passed the line below the top bar', async () => {
		mount(markup);
		await tick();
		const nav = data(document.getElementById('nav'));
		place({ one: -400, two: 90, three: 500 });
		nav.update();
		expect(nav.current).toBe('two');
		expect(nav.isCurrent('two')).toBe(true);
		place({ one: -400, two: -100, three: 300 });
		nav.update();
		expect(nav.current).toBe('two');
	});

	it('marks the last section at the bottom of the page', async () => {
		mount(markup);
		await tick();
		const nav = data(document.getElementById('nav'));
		place({ one: -900, two: -300, three: 400 });
		Object.defineProperty(globalThis, 'scrollY', { value: 900, configurable: true });
		Object.defineProperty(document.documentElement, 'scrollHeight', { value: 900 + globalThis.innerHeight, configurable: true });
		nav.update();
		expect(nav.current).toBe('three');
		Object.defineProperty(globalThis, 'scrollY', { value: 0, configurable: true });
	});
});
