// Strings of an island: the page passes them translated in its props
// (strings: {key: text}); a missing key shows the English key. %s and %d
// take the extra arguments in order, as __() in i18n.js.

/**
 * @param {Record<string, string>} [strings]
 * @returns {(key: string, ...args: (string|number)[]) => string}
 */
export function translator(strings = {}) {
	return (key, ...args) => {
		let text = typeof strings[key] === 'string' && strings[key] !== '' ? strings[key] : key;
		for (const arg of args) {
			text = text.replace(/%[sd]/, String(arg));
		}
		return text;
	};
}
