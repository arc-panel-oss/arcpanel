import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

const announce = (id, items) => window.dispatchEvent(new CustomEvent('ap-table-selection', { detail: { id, items } }));

afterEach(() => unmount());

describe('apBulkBar', () => {
	it('counts the selection of its own table only', async () => {
		const root = mount(`<div x-data="apBulkBar({ table: 'domains' })"><b x-text="count"></b><span x-show="active">bar</span></div>`);
		const bar = data(root);
		expect(bar.count).toBe(0);
		expect(bar.active).toBe(false);

		announce('other', ['x/y']);
		expect(bar.count).toBe(0);

		announce('domains', ['bob/a.com', 'bob/b.com']);
		await tick();
		expect(bar.count).toBe(2);
		expect(bar.items).toEqual(['bob/a.com', 'bob/b.com']);
		expect(bar.active).toBe(true);
		expect(root.querySelector('b').textContent).toBe('2');

		announce('domains', []);
		expect(bar.active).toBe(false);
	});

	it('stops listening when destroyed', () => {
		const root = mount(`<div x-data="apBulkBar({ table: 'domains' })"></div>`);
		const bar = data(root);
		unmount();
		announce('domains', ['bob/a.com']);
		expect(bar.count).toBe(0);
	});
});
