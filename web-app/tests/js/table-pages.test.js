import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

// Client paging of apTable (perPage): long lists such as the FTP accounts of a domain.

const rows = (count) =>
	Array.from(
		{ length: count },
		(_, index) => `<tr data-row data-search="user${index + 1}">
			<td><input type="checkbox" data-select-row value="user${index + 1}"></td>
			<td data-col="name">user${String(index + 1).padStart(2, '0')}</td>
		</tr>`,
	).join('');

const markup = (count, perPage = 10) => `
<div x-data="apTable({ id: 'ftp', perPage: ${perPage} })">
	<input type="search" x-model="query">
	<table>
		<thead><tr><th><input type="checkbox" data-select-all></th><th data-col="name"><button type="button" x-on:click="sort('name')">Name</button></th></tr></thead>
		<tbody>${rows(count)}</tbody>
	</table>
	<p data-table-empty hidden>No match</p>
</div>`;

const shown = (root) => [...root.querySelectorAll('tbody tr')].filter((tr) => !tr.hidden).map((tr) => tr.querySelector('[data-col="name"]').textContent);

afterEach(() => {
	unmount();
	localStorage.clear();
});

describe('apTable paging', () => {
	it('shows one page of rows and moves between pages', async () => {
		const root = mount(markup(23));
		await tick();
		const table = data(root);

		expect(table.pages).toBe(3);
		expect(shown(root)).toHaveLength(10);
		expect(shown(root)[0]).toBe('user01');
		expect([table.firstShown, table.lastShown, table.visibleCount]).toEqual([1, 10, 23]);

		table.next();
		expect(shown(root)[0]).toBe('user11');
		table.goTo(3);
		expect(shown(root)).toEqual(['user21', 'user22', 'user23']);
		expect([table.firstShown, table.lastShown]).toEqual([21, 23]);
		table.next();
		expect(table.page).toBe(3);
		table.prev();
		expect(table.page).toBe(2);
	});

	it('pages the rows that match the filter and goes back to the first page', async () => {
		const root = mount(markup(23));
		await tick();
		const table = data(root);
		table.goTo(3);

		table.query = 'user2';
		await tick();
		// user2 and user20 … user23
		expect(table.page).toBe(1);
		expect(table.pages).toBe(1);
		expect(shown(root)).toHaveLength(5);
	});

	it('keeps the order of a sort across the pages', async () => {
		const root = mount(markup(12));
		await tick();
		const table = data(root);

		table.sort('name');
		table.sort('name');
		expect(shown(root)[0]).toBe('user12');
		expect(shown(root)).toHaveLength(10);
	});

	it('selects all rows of the page only and keeps the ticks of other pages', async () => {
		const root = mount(markup(12));
		await tick();
		const table = data(root);

		root.querySelector('[data-select-all]').checked = true;
		table.toggleAll(true);
		expect(table.selected).toHaveLength(10);
		table.next();
		expect(root.querySelector('input[value="user1"]').checked).toBe(true);
	});

	it('shows every row without perPage', async () => {
		const root = mount(markup(23, 0));
		await tick();
		expect(shown(root)).toHaveLength(23);
		expect(data(root).pages).toBe(1);
	});
});
