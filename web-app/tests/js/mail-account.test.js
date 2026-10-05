import { afterEach, describe, expect, it, vi } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

// x-show applies a change on the next animation frame
const settle = async () => {
	await new Promise((resolve) => setTimeout(resolve, 50));
	await tick();
};

const markup = (options = "{ domain: 'example.com' }") => `
<div id="root" x-data="apMailAccount(${options})" x-on:input="sync($event)">
	<form>
		<input id="account" name="account">
		<div x-data="apPassword()">
			<input id="password" name="password" type="password" x-ref="input">
			<button id="generate" type="button" x-on:click="generate()">Generate</button>
		</div>
		<input id="other" name="email">
		<textarea id="forward" name="forward" x-bind:disabled="blackhole"></textarea>
		<input id="blackhole" name="blackhole" type="checkbox" x-model="blackhole">
		<div id="fwd-only-field" x-show="! blackhole">
			<input id="fwd-only" name="fwd_only" type="checkbox" x-model="fwdOnly">
		</div>
		<input id="required" x-bind:required="! fwdOnly">
	</form>
	<input id="username" readonly x-bind:value="username">
	<input id="mirror" readonly x-bind:value="password">
	<button id="copy" type="button" x-on:click="copy('username', 'info@example.com')"></button>
	<span id="copied" x-text="copied"></span>
</div>`;

const type = async (id, value) => {
	const input = document.getElementById(id);
	input.value = value;
	input.dispatchEvent(new Event('input', { bubbles: true }));
	await tick();
};

const click = async (id) => {
	document.getElementById(id).click();
	await settle();
};

afterEach(() => {
	unmount();
	vi.unstubAllGlobals();
});

describe('apMailAccount', () => {
	it('mirrors the account name, lower-cased, into the user name', async () => {
		mount(markup());
		await tick();
		expect(document.getElementById('username').value).toBe('@example.com');

		await type('account', ' Info ');
		expect(document.getElementById('username').value).toBe('info@example.com');
	});

	it('starts from the account of an edit page', async () => {
		mount(markup("{ account: 'sales', domain: 'example.com' }"));
		await tick();
		expect(data(document.getElementById('root')).username).toBe('sales@example.com');
	});

	it('mirrors the typed and the generated password, and ignores other fields', async () => {
		mount(markup());
		await type('password', 'Secret-123');
		expect(document.getElementById('mirror').value).toBe('Secret-123');

		await type('other', 'me@example.net');
		expect(document.getElementById('mirror').value).toBe('Secret-123');

		await click('generate');
		const generated = document.getElementById('password').value;
		expect(generated).toHaveLength(16);
		expect(document.getElementById('mirror').value).toBe(generated);
	});

	it('disables the forwards and hides "Do not store forwarded mail" while discarding all mail', async () => {
		mount(markup());
		await settle();
		expect(document.getElementById('forward').disabled).toBe(false);
		expect(document.getElementById('fwd-only-field').style.display).toBe('');

		await click('blackhole');
		expect(document.getElementById('forward').disabled).toBe(true);
		expect(document.getElementById('fwd-only-field').style.display).toBe('none');
	});

	it('starts with discarding on for an account that forwards to :blackhole:', async () => {
		mount(markup("{ domain: 'example.com', blackhole: true }"));
		await settle();
		expect(document.getElementById('blackhole').checked).toBe(true);
		expect(document.getElementById('forward').disabled).toBe(true);
	});

	it('needs no password for an account that does not store forwarded mail', async () => {
		mount(markup());
		await tick();
		expect(document.getElementById('required').required).toBe(true);

		await click('fwd-only');
		expect(document.getElementById('required').required).toBe(false);
	});

	it('copies a value and says so for a moment', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined);
		vi.stubGlobal('navigator', { clipboard: { writeText } });
		mount(markup());

		await click('copy');
		expect(writeText).toHaveBeenCalledWith('info@example.com');
		expect(document.getElementById('copied').textContent).toBe('username');
	});

	it('does not claim a copy the browser refused', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
		mount(markup());

		await click('copy');
		expect(document.getElementById('copied').textContent).toBe('');
	});
});
