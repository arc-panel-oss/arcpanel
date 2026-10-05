// Translations for scripts: <x-ap-i18n> puts the keys listed in
// resources/js/i18n-keys.json into window.__ap.i18n for the current locale.

/**
 * Translate a string; %s and %d placeholders take the extra arguments.
 *
 * @param {string} key
 * @param {...(string|number)} args
 */
export function __(key, ...args) {
	const catalog = globalThis.window?.__ap?.i18n ?? {};
	let text = typeof catalog[key] === 'string' && catalog[key] !== '' ? catalog[key] : key;
	for (const arg of args) {
		text = text.replace(/%[sd]/, String(arg));
	}
	return text;
}
