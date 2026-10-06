import { afterEach, describe, expect, it, vi } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

function row(user, domain, disk) {
	return `<tr data-row data-search="${domain} ${user}">
		<td><input type="checkbox" data-select-row value="${user}/${domain}"></td>
		<td data-col="name">${domain}</td>
		<td data-col="disk" data-optional data-sort-value="${disk}">${disk} MB</td>
	</tr>`;
}

const markup = (rows = [row('bob', 'beta.com', '2.5'), row('alice', 'alpha.com', '2.10'), row('carol', 'gamma.com', '-1')]) => `
<div x-data="apTable({ id: 'domains' })">
	<input type="search" x-model="query">
	<table>
		<thead><tr>
			<th><input type="checkbox" data-select-all></th>
			<th data-col="name"><button type="button" x-on:click="sort('name')">Name</button></th>
			<th data-col="disk" data-optional data-sort-type="number"><button type="button" x-on:click="sort('disk')">Disk</button></th>
			<th><button type="button" id="chooser" x-on:click="toggleColumn('disk')">Columns</button></th>
		</tr></thead>
		<tbody>${rows.join('')}</tbody>
	</table>
	<p data-table-empty hidden>No match</p>
</div>`;

const names = (root) => [...root.querySelectorAll('tbody tr')].map((tr) => tr.querySelector('[data-col="name"]').textContent);

afterEach(() => {
	unmount();
	localStorage.clear();
});

describe('apTable', () => {
	it('sorts text columns both ways and reports aria-sort', () => {
		const root = mount(markup());
		const table = data(root);

		expect(table.ariaSort('name')).toBe('none');
		table.sort('name');
		expect(names(root)).toEqual(['alpha.com', 'beta.com', 'gamma.com']);
		expect(table.ariaSort('name')).toBe('ascending');

		table.sort('name');
		expect(names(root)).toEqual(['gamma.com', 'beta.com', 'alpha.com']);
		expect(table.ariaSort('name')).toBe('descending');
		expect(table.ariaSort('disk')).toBe('none');
	});

	it('sorts and toggles columns from buttons inside the table', () => {
		const root = mount(markup());
		root.querySelector('th[data-col="name"] button').click();
		expect(names(root)).toEqual(['alpha.com', 'beta.com', 'gamma.com']);
		root.querySelector('#chooser').click();
		expect(root.querySelector('th[data-col="disk"]').hidden).toBe(true);
	});

	it('sorts number columns by their numeric sort value', () => {
		const root = mount(markup());
		data(root).sort('disk');
		// 2.10 < 2.5 numerically (a text sort would put 2.5 first)
		expect(names(root)).toEqual(['gamma.com', 'alpha.com', 'beta.com']);
	});

	it('filters rows, unchecks hidden rows and shows the empty message', async () => {
		const root = mount(markup());
		const table = data(root);
		const beta = root.querySelector('[value="bob/beta.com"]');
		beta.click();
		expect(table.selected).toEqual(['bob/beta.com']);

		table.query = 'ALICE';
		await tick();
		const rows = [...root.querySelectorAll('tbody tr')];
		expect(rows.filter((tr) => !tr.hidden).map((tr) => tr.dataset.search)).toEqual(['alpha.com alice']);
		expect(beta.checked).toBe(false);
		expect(table.selected).toEqual([]);
		expect(table.visibleCount).toBe(1);
		expect(root.querySelector('[data-table-empty]').hidden).toBe(true);

		table.query = 'nothing-matches';
		await tick();
		expect(table.visibleCount).toBe(0);
		expect(root.querySelector('[data-table-empty]').hidden).toBe(false);

		table.query = '';
		await tick();
		expect(rows.every((tr) => !tr.hidden)).toBe(true);
	});

	it('hides optional columns and remembers the choice per table id', () => {
		let root = mount(markup());
		data(root).toggleColumn('disk');

		expect(root.querySelector('th[data-col="disk"]').hidden).toBe(true);
		expect([...root.querySelectorAll('td[data-col="disk"]')].every((td) => td.hidden)).toBe(true);
		expect(root.querySelector('th[data-col="name"]').hidden).toBe(false);
		expect(localStorage.getItem('ap-table:domains:hidden')).toBe('["disk"]');

		unmount();
		root = mount(markup());
		expect(data(root).isHidden('disk')).toBe(true);
		expect(root.querySelector('th[data-col="disk"]').hidden).toBe(true);

		data(root).toggleColumn('disk');
		expect(root.querySelector('th[data-col="disk"]').hidden).toBe(false);
		expect(localStorage.getItem('ap-table:domains:hidden')).toBe('[]');
	});

	it('hides the cells of a column whose header alone is marked optional', () => {
		const root = mount(markup().replaceAll('<td data-col="disk" data-optional', '<td data-col="disk"'));
		data(root).toggleColumn('disk');
		expect([...root.querySelectorAll('td[data-col="disk"]')].every((td) => td.hidden)).toBe(true);
		expect([...root.querySelectorAll('td[data-col="name"]')].some((td) => td.hidden)).toBe(false);
	});

	it('starts with the default-hidden columns until the viewer chooses', () => {
		const html = markup().replace('<th data-col="disk" data-optional', '<th data-col="disk" data-optional data-default-hidden');
		let root = mount(html);
		expect(data(root).hidden).toEqual(['disk']);
		expect(root.querySelector('th[data-col="disk"]').hidden).toBe(true);
		expect(root.querySelector('table').dataset.columnsReady).toBe('');

		data(root).toggleColumn('disk');
		unmount();
		root = mount(html);
		expect(data(root).hidden).toEqual([]);
		expect(root.querySelector('th[data-col="disk"]').hidden).toBe(false);
	});

	it('marks the scroll box while the table is wider than it', () => {
		const html = markup().replace('<table>', '<div class="ap-table-wrap"><table>').replace('</table>', '</table></div>');
		const root = mount(html);
		const wrap = root.querySelector('.ap-table-wrap');
		expect(wrap.classList.contains('is-scrollable')).toBe(false);
		Object.defineProperty(wrap, 'scrollWidth', { configurable: true, value: 900 });
		Object.defineProperty(wrap, 'clientWidth', { configurable: true, value: 600 });
		data(root).toggleColumn('disk');
		expect(wrap.classList.contains('is-scrollable')).toBe(true);
	});

	it('works when localStorage throws or holds garbage', () => {
		localStorage.setItem('ap-table:domains:hidden', '{not json');
		let root = mount(markup());
		expect(data(root).hidden).toEqual([]);
		unmount();

		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
			throw new Error('blocked');
		});
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
			throw new Error('blocked');
		});
		root = mount(markup());
		expect(data(root).hidden).toEqual([]);
		expect(() => data(root).toggleColumn('disk')).not.toThrow();
		expect(root.querySelector('th[data-col="disk"]').hidden).toBe(true);
	});

	it('selects all rows, marks partial selection and announces it', () => {
		const events = [];
		const listener = (event) => events.push(event.detail);
		window.addEventListener('ap-table-selection', listener);

		const root = mount(markup());
		const all = root.querySelector('[data-select-all]');
		all.click();

		expect(data(root).selected).toEqual(['bob/beta.com', 'alice/alpha.com', 'carol/gamma.com']);
		expect([...root.querySelectorAll('tbody tr')].every((tr) => tr.classList.contains('is-selected'))).toBe(true);
		expect(events.at(-1)).toEqual({ id: 'domains', items: ['bob/beta.com', 'alice/alpha.com', 'carol/gamma.com'] });

		root.querySelector('[value="alice/alpha.com"]').click();
		expect(all.checked).toBe(false);
		expect(all.indeterminate).toBe(true);
		expect(events.at(-1).items).toEqual(['bob/beta.com', 'carol/gamma.com']);

		all.click();
		expect(all.checked).toBe(true);
		expect(all.indeterminate).toBe(false);

		all.click();
		expect(data(root).selected).toEqual([]);
		expect(all.indeterminate).toBe(false);
		window.removeEventListener('ap-table-selection', listener);
	});

	it('skips disabled checkboxes when selecting all', () => {
		const root = mount(markup());
		root.querySelector('[value="carol/gamma.com"]').disabled = true;
		root.querySelector('[data-select-all]').click();
		expect(data(root).selected).toEqual(['bob/beta.com', 'alice/alpha.com']);
		expect(root.querySelector('[value="carol/gamma.com"]').checked).toBe(false);
	});
});
