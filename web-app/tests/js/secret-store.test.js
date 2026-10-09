import { afterEach, describe, expect, it, vi } from 'vitest';
import { validSecretName } from '../../resources/js/alpine/secret-store.js';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<div id="root" x-data="apSecretStore({ name: '/shop/db' })">
	<input id="name" x-model.trim="name">
	<code id="reference" x-text="reference"></code>
	<span id="state" x-text="nameState"></span>
	<button id="copy" type="button" x-on:click="copy('ref', reference)"></button>
	<span id="label" x-text="copied === 'ref' ? 'Copied' : 'Copy'"></span>
</div>`;

afterEach(() => {
	unmount();
	vi.unstubAllGlobals();
	vi.useRealTimers();
});

describe('validSecretName', () => {
	it('accepts path names of one to five parts', () => {
		expect(validSecretName('/shop/db_password')).toBe(true);
		expect(validSecretName('/a')).toBe(true);
		expect(validSecretName('/a/b/c/d/e')).toBe(true);
		expect(validSecretName('/A-1.x_y')).toBe(true);
	});

	it('refuses what the server refuses (D3)', () => {
		expect(validSecretName('shop')).toBe(false);
		expect(validSecretName('/')).toBe(false);
		expect(validSecretName('/shop/')).toBe(false);
		expect(validSecretName('/a/b/c/d/e/f')).toBe(false);
		expect(validSecretName('/a/../b')).toBe(false);
		expect(validSecretName('/a/./b')).toBe(false);
		expect(validSecretName('/bad name')).toBe(false);
		expect(validSecretName(`/${'a'.repeat(65)}`)).toBe(false);
		expect(validSecretName(`/${'a'.repeat(64)}/${'b'.repeat(64)}`)).toBe(false);
	});
});

describe('apSecretStore', () => {
	it('previews the reference of the name being typed', async () => {
		const root = mount(markup);
		await tick();
		expect(document.getElementById('reference').textContent).toBe('{{secret:/shop/db}}');
		expect(document.getElementById('state').textContent).toBe('valid');

		data(root).name = '';
		await tick();
		expect(document.getElementById('reference').textContent).toBe('{{secret:NAME}}');
		expect(document.getElementById('state').textContent).toBe('empty');

		data(root).name = 'no-slash';
		await tick();
		expect(document.getElementById('state').textContent).toBe('invalid');
	});

	it('copies with the Clipboard API and says Copied for a moment', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined);
		vi.stubGlobal('navigator', { clipboard: { writeText } });
		mount(markup);
		await tick();

		document.getElementById('copy').click();
		await tick();
		expect(writeText).toHaveBeenCalledWith('{{secret:/shop/db}}');
		expect(document.getElementById('label').textContent).toBe('Copied');

		await new Promise((resolve) => setTimeout(resolve, 1600));
		expect(document.getElementById('label').textContent).toBe('Copy');
	});

	it('falls back to a selected text area without the Clipboard API', async () => {
		vi.stubGlobal('navigator', {});
		const exec = vi.fn().mockReturnValue(true);
		document.execCommand = exec;
		mount(markup);
		await tick();

		document.getElementById('copy').click();
		await tick();
		expect(exec).toHaveBeenCalledWith('copy');
		expect(document.getElementById('label').textContent).toBe('Copied');
		expect(document.querySelector('textarea')).toBeNull();
	});

	it('says nothing when the copy fails', async () => {
		vi.stubGlobal('navigator', {});
		document.execCommand = vi.fn().mockReturnValue(false);
		mount(markup);
		await tick();

		document.getElementById('copy').click();
		await tick();
		expect(document.getElementById('label').textContent).toBe('Copy');
	});
});
