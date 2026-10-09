// apSecretStore: the secret store pages (docs/features/specs/2026-10-09-001-e38-secret-store-design.md §7).
// Copies a reference and says "Copied" for a moment, previews the reference
// of the name being typed and checks it like the server (D3), and switches
// the value between one line and several.

const SEGMENT = /^[A-Za-z0-9_.-]{1,64}$/;

/** D3: one to five path segments, at most 128 characters, no "." or ".." */
export function validSecretName(name) {
	if (typeof name !== 'string' || name.length > 128 || !name.startsWith('/')) {
		return false;
	}
	const segments = name.slice(1).split('/');

	return segments.length <= 5 && segments.every((segment) => SEGMENT.test(segment) && segment !== '.' && segment !== '..');
}

/** Copy text with the Clipboard API, or a selected text area where it is missing (plain HTTP) */
async function writeClipboard(text) {
	const clipboard = globalThis.navigator?.clipboard;
	if (clipboard && globalThis.isSecureContext !== false) {
		try {
			await clipboard.writeText(text);
			return true;
		} catch {
			// fall through to the text area
		}
	}
	const area = document.createElement('textarea');
	area.value = text;
	area.setAttribute('readonly', '');
	area.style.position = 'fixed';
	area.style.opacity = '0';
	document.body.append(area);
	area.select();
	let copied = false;
	try {
		copied = document.execCommand('copy');
	} catch {
		copied = false;
	}
	area.remove();
	return copied;
}

export default function apSecretStore({ name = '', multiline = false } = {}) {
	return {
		name,
		multiline,
		copied: '',
		timer: null,

		destroy() {
			clearTimeout(this.timer);
		},

		/** The reference of the name being typed, or of NAME while it is empty */
		get reference() {
			return `{{secret:${this.name === '' ? 'NAME' : this.name}}}`;
		},

		/** empty, valid or invalid */
		get nameState() {
			if (this.name === '') {
				return 'empty';
			}
			return validSecretName(this.name) ? 'valid' : 'invalid';
		},

		/** Copy a value; `copied` names it for a moment. */
		async copy(key, text) {
			if (!(await writeClipboard(text))) {
				return;
			}
			this.copied = key;
			clearTimeout(this.timer);
			this.timer = setTimeout(() => {
				this.copied = '';
			}, 1500);
		},
	};
}
