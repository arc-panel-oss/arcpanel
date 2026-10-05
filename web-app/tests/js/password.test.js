import { afterEach, describe, expect, it } from 'vitest';
import { generatePassword, strengthScore } from '../../resources/js/alpine/password.js';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<div id="pw" x-data="apPassword()">
	<input id="input" type="password" x-ref="input" x-on:input="evaluate()">
	<button id="reveal" type="button" x-on:click="toggle()">Show</button>
	<button id="generate" type="button" x-on:click="generate()">Generate</button>
	<span id="hint" x-text="hint"></span>
	<div class="ap-strength" x-bind:data-score="score"></div>
</div>`;

const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*-_=+';

afterEach(() => {
	unmount();
	delete window.__ap;
});

describe('strengthScore', () => {
	it('scores from 0 (empty) to 4 (strong)', () => {
		expect(strengthScore('')).toBe(0);
		expect(strengthScore('abc')).toBe(1);
		expect(strengthScore('abcdefgh1')).toBe(2);
		expect(strengthScore('Abcdefgh1!')).toBe(3);
		expect(strengthScore('Abcdefgh1!xyzQ')).toBe(4);
	});
});

describe('generatePassword', () => {
	it('returns random passwords from the alphabet', () => {
		const one = generatePassword();
		expect(one).toHaveLength(16);
		expect([...one].every((c) => ALPHABET.includes(c))).toBe(true);
		expect(generatePassword(24)).toHaveLength(24);
		expect(generatePassword()).not.toBe(one);
	});
});

describe('apPassword', () => {
	it('shows and hides the password', () => {
		mount(markup);
		const input = document.getElementById('input');
		document.getElementById('reveal').click();
		expect(input.type).toBe('text');
		expect(data(document.getElementById('pw')).visible).toBe(true);
		document.getElementById('reveal').click();
		expect(input.type).toBe('password');
	});

	it('rates the password as it is typed', async () => {
		window.__ap = { i18n: { 'Too weak': 'Quá yếu' } };
		mount(markup);
		const input = document.getElementById('input');
		input.value = 'abc';
		input.dispatchEvent(new Event('input'));
		await tick();
		expect(document.getElementById('hint').textContent).toBe('Quá yếu');
		expect(document.querySelector('.ap-strength').dataset.score).toBe('1');

		input.value = '';
		input.dispatchEvent(new Event('input'));
		await tick();
		expect(document.getElementById('hint').textContent).toBe('');
	});

	it('generates a strong password and shows it', async () => {
		mount(markup);
		document.getElementById('generate').click();
		const input = document.getElementById('input');
		expect(input.value).toHaveLength(16);
		expect(input.type).toBe('text');
		expect(data(document.getElementById('pw')).score).toBeGreaterThan(0);
	});
});
