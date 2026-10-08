import { afterEach, describe, expect, it, vi } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<div id="root" x-data="apAccessKeySecret()" x-on:copy="onCopy()" x-on:submit="onSubmit($event)">
	<input id="secret" readonly value="s3cret">
	<button id="copy" type="button" x-on:click="copy('secret', 's3cret')"></button>
	<span id="label" x-text="copied === 'secret' ? 'Copied' : 'Copy'"></span>
	<form id="delete" action="about:blank"><button id="submit" type="submit"></button></form>
</div>`;

const leave = () => {
	const event = new Event('beforeunload', { cancelable: true });
	window.dispatchEvent(event);
	return event.defaultPrevented;
};

afterEach(() => {
	unmount();
	vi.unstubAllGlobals();
	vi.useRealTimers();
});

describe('apAccessKeySecret', () => {
	it('asks before leaving while nothing has been copied', async () => {
		mount(markup);
		await tick();
		expect(leave()).toBe(true);
	});

	it('copies a value, says so for a moment and stops asking', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined);
		vi.stubGlobal('navigator', { clipboard: { writeText } });
		mount(markup);
		const root = document.getElementById('root');

		await data(root).copy('secret', 's3cret');
		await tick();
		expect(writeText).toHaveBeenCalledWith('s3cret');
		expect(document.getElementById('label').textContent).toBe('Copied');
		expect(leave()).toBe(false);

		await new Promise((resolve) => setTimeout(resolve, 1600));
		await tick();
		expect(document.getElementById('label').textContent).toBe('Copy');
		expect(data(root).saved).toBe(true);
	});

	it('keeps asking when the browser refuses the copy', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
		mount(markup);

		await data(document.getElementById('root')).copy('secret', 's3cret');
		await tick();
		expect(document.getElementById('label').textContent).toBe('Copy');
		expect(leave()).toBe(true);
	});

	it('counts a keyboard copy inside the card', async () => {
		mount(markup);
		document.getElementById('secret').dispatchEvent(new Event('copy', { bubbles: true }));
		await tick();
		expect(leave()).toBe(false);
	});

	it('does not ask when a form of the page is submitted, but does after a cancelled one', async () => {
		mount(markup);
		await tick();
		const form = document.getElementById('delete');

		const cancelled = new Event('submit', { bubbles: true, cancelable: true });
		cancelled.preventDefault();
		form.dispatchEvent(cancelled);
		expect(leave()).toBe(true);

		form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		expect(leave()).toBe(false);
	});

	it('stops listening when the tree is destroyed', async () => {
		mount(markup);
		await tick();
		unmount();
		expect(leave()).toBe(false);
	});
});
