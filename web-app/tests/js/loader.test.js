import { afterEach, describe, expect, it, vi } from 'vitest';
import { islands, loadIslands } from '../../resources/js/islands/loader.js';

afterEach(() => {
	document.body.innerHTML = '';
});

describe('loadIslands', () => {
	it('mounts known islands once with their props', async () => {
		const mount = vi.fn();
		const registry = { hello: vi.fn(async () => ({ mount })) };
		document.body.innerHTML = `
			<div id="one" data-island="hello" data-props='{"user":"bob"}'></div>
			<div id="two" data-island="hello"></div>`;

		expect(await loadIslands(document, registry)).toEqual(['hello', 'hello']);
		expect(mount).toHaveBeenCalledWith(document.getElementById('one'), { user: 'bob' });
		expect(mount).toHaveBeenCalledWith(document.getElementById('two'), {});
		expect(document.getElementById('one').dataset.islandMounted).toBe('yes');

		expect(await loadIslands(document, registry)).toEqual([]);
		expect(mount).toHaveBeenCalledTimes(2);
	});

	it('warns about unknown islands and invalid props', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
		const mount = vi.fn();
		document.body.innerHTML = `
			<div data-island="missing"></div>
			<div id="bad" data-island="hello" data-props="{bad"></div>`;

		expect(await loadIslands(document, { hello: async () => ({ mount }) })).toEqual(['hello']);
		expect(warn).toHaveBeenCalledWith('Unknown island "missing"');
		expect(warn).toHaveBeenCalledWith('Island "hello" has invalid props');
		expect(mount).toHaveBeenCalledWith(document.getElementById('bad'), {});
	});

	it('has no islands registered yet', async () => {
		expect(islands).toEqual({});
		expect(await loadIslands(document)).toEqual([]);
	});
});
