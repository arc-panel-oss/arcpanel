// Shared helpers of the Alpine module tests: real Alpine in jsdom, every
// module registered, one tree mounted per test.
import Alpine from 'alpinejs';
import { modules } from '../../resources/js/alpine/index.js';

let registered = false;

export function setupAlpine() {
	if (!registered) {
		for (const [name, factory] of Object.entries(modules)) {
			Alpine.data(name, factory);
		}
		window.Alpine = Alpine;
		registered = true;
	}
	return Alpine;
}

/** Render markup into <body> and initialise it. Returns the first element. */
export function mount(html) {
	setupAlpine();
	document.body.innerHTML = html;
	Alpine.initTree(document.body);
	return document.body.firstElementChild;
}

export function unmount() {
	Alpine.destroyTree(document.body);
	document.body.innerHTML = '';
}

/** Component data of an x-data element. */
export function data(el) {
	return Alpine.$data(el);
}

/** Let reactive effects, $nextTick and resolved promises run (real timers). */
export async function tick() {
	// $nextTick schedules its timeout from a microtask, so wait two rounds
	await new Promise((resolve) => setTimeout(resolve, 0));
	await new Promise((resolve) => setTimeout(resolve, 0));
}

/** A fetch() Response stand-in. */
export function jsonResponse(body, status = 200) {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => body,
	};
}
