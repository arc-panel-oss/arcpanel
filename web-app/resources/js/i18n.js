// Translations for scripts: <x-ap-i18n> prints the keys listed in
// resources/js/i18n-keys.json for the current locale as a JSON data block
// (#ap-i18n; the CSP runs no inline script, E39 W5 §9.2), read once into
// window.__ap.i18n.

/** @returns {Record<string, string>} */
function catalog() {
	const win = globalThis.window;
	if (win?.__ap?.i18n) {
		return win.__ap.i18n;
	}
	const block = globalThis.document?.getElementById('ap-i18n');
	if (!win || !block) {
		return {};
	}
	let strings = {};
	try {
		strings = JSON.parse(block.textContent ?? '{}') ?? {};
	} catch {
		strings = {};
	}
	win.__ap = Object.assign(win.__ap ?? {}, { i18n: strings });
	return strings;
}

/**
 * Translate a string; %s and %d placeholders take the extra arguments.
 *
 * @param {string} key
 * @param {...(string|number)} args
 */
export function __(key, ...args) {
	const strings = catalog();
	let text = typeof strings[key] === 'string' && strings[key] !== '' ? strings[key] : key;
	for (const arg of args) {
		text = text.replace(/%[sd]/, String(arg));
	}
	return text;
}
