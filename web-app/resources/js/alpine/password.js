// apPassword: show/hide, strength meter (check-password-strength, as the
// legacy passwordInput.js) and a generator.
import { passwordStrength } from 'check-password-strength';
import { __ } from '../i18n.js';

const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*-_=+';

/** Random password from the Web Crypto API. */
export function generatePassword(length = 16) {
	const bytes = new Uint32Array(length);
	globalThis.crypto.getRandomValues(bytes);
	return [...bytes].map((n) => ALPHABET[n % ALPHABET.length]).join('');
}

/** 0 = empty, 1 = too weak … 4 = strong. @param {string} value */
export function strengthScore(value) {
	return value === '' ? 0 : passwordStrength(value).id + 1;
}

const HINTS = ['', 'Too weak', 'Weak', 'Medium', 'Strong'];

export default function apPassword() {
	return {
		visible: false,
		score: 0,

		get hint() {
			return this.score === 0 ? '' : __(HINTS[this.score]);
		},

		input() {
			return this.$refs.input ?? this.$root.querySelector('input');
		},

		toggle() {
			this.visible = !this.visible;
			this.input().type = this.visible ? 'text' : 'password';
		},

		evaluate() {
			this.score = strengthScore(this.input().value);
		},

		generate() {
			const input = this.input();
			input.value = generatePassword();
			this.visible = true;
			input.type = 'text';
			input.dispatchEvent(new Event('input', { bubbles: true }));
			this.evaluate();
		},
	};
}
