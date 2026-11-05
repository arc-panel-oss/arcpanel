// apTable: client-side sort, filter, column chooser and row selection for a
// server-rendered <x-ap-table>. Rows are <tr data-row>; cells carry
// data-col="key" and optionally data-sort-value. Optional columns whose
// header has data-default-hidden start hidden until the viewer picks them.
// Selection checkboxes are [data-select-row] (value = item id) and
// [data-select-all]; changes are announced as the window event
// "ap-table-selection" {id, items}. The scroll box gets .is-scrollable and a tab stop while
// the table is wider than it. With perPage, the rows that match the filter
// are shown one page at a time (firstShown–lastShown of visibleCount).
import { readStore, writeStore } from './storage.js';

export default function apTable({ id = '', perPage = 0 } = {}) {
	return {
		id,
		perPage,
		page: 1,
		query: '',
		sortKey: null,
		sortDir: 'ascending',
		hidden: [],
		selected: [],
		visibleCount: 0,

		init() {
			this.hidden = this.readHidden();
			this.applyColumns();
			this.watchWidth();
			this.filter();
			this.$watch('query', () => {
				this.page = 1;
				this.filter();
			});
			this.$root.addEventListener('change', (event) => {
				if (event.target.matches('[data-select-all]')) {
					this.toggleAll(event.target.checked);
				} else if (event.target.matches('[data-select-row]')) {
					this.syncSelection();
				}
			});
			this.syncSelection();
		},

		rows() {
			return [...this.$root.querySelectorAll('tbody tr[data-row]')];
		},

		cellValue(row, key) {
			const cell = row.querySelector(`[data-col="${key}"]`);
			if (!cell) {
				return '';
			}
			return cell.dataset.sortValue ?? cell.textContent.trim();
		},

		sort(key) {
			this.sortDir = this.sortKey === key && this.sortDir === 'ascending' ? 'descending' : 'ascending';
			this.sortKey = key;
			const header = this.$root.querySelector(`th[data-col="${key}"]`);
			const numeric = header?.dataset.sortType === 'number';
			const direction = this.sortDir === 'ascending' ? 1 : -1;
			const body = this.$root.querySelector('tbody');
			const sorted = this.rows().sort((a, b) => {
				const x = this.cellValue(a, key);
				const y = this.cellValue(b, key);
				const order = numeric
					? (Number.parseFloat(x) || 0) - (Number.parseFloat(y) || 0)
					: x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
				return order * direction;
			});
			for (const row of sorted) {
				body.append(row);
			}
			this.paginate();
		},

		ariaSort(key) {
			return this.sortKey === key ? this.sortDir : 'none';
		},

		filter() {
			const needle = this.query.trim().toLowerCase();
			let visible = 0;
			for (const row of this.rows()) {
				const haystack = (row.dataset.search ?? row.textContent).toLowerCase();
				const match = needle === '' || haystack.includes(needle);
				row.toggleAttribute('data-filtered', !match);
				if (match) {
					visible++;
				} else {
					const box = row.querySelector('[data-select-row]');
					if (box) {
						box.checked = false;
					}
				}
			}
			this.visibleCount = visible;
			this.paginate();
			const empty = this.$root.querySelector('[data-table-empty]');
			if (empty) {
				empty.hidden = visible > 0 || this.rows().length === 0;
			}
			this.syncSelection();
		},

		get pages() {
			return this.perPage > 0 ? Math.max(1, Math.ceil(this.visibleCount / this.perPage)) : 1;
		},

		get firstShown() {
			return this.visibleCount === 0 ? 0 : this.perPage > 0 ? (this.page - 1) * this.perPage + 1 : 1;
		},

		get lastShown() {
			return this.perPage > 0 ? Math.min(this.visibleCount, this.page * this.perPage) : this.visibleCount;
		},

		/** Hide the rows that the filter left out and those of other pages. */
		paginate() {
			this.page = Math.min(Math.max(1, this.page), this.pages);
			const start = (this.page - 1) * this.perPage;
			let index = 0;
			for (const row of this.rows()) {
				if (row.hasAttribute('data-filtered')) {
					row.hidden = true;
					continue;
				}
				row.hidden = this.perPage > 0 && (index < start || index >= start + this.perPage);
				index++;
			}
		},

		goTo(page) {
			this.page = page;
			this.paginate();
			this.syncSelection();
		},

		next() {
			this.goTo(this.page + 1);
		},

		prev() {
			this.goTo(this.page - 1);
		},

		isHidden(key) {
			return this.hidden.includes(key);
		},

		toggleColumn(key) {
			this.hidden = this.isHidden(key) ? this.hidden.filter((k) => k !== key) : [...this.hidden, key];
			writeStore(this.storageKey(), JSON.stringify(this.hidden));
			this.applyColumns();
		},

		applyColumns() {
			const optional = new Set(
				[...this.$root.querySelectorAll('th[data-col][data-optional]')].map((th) => th.dataset.col),
			);
			for (const cell of this.$root.querySelectorAll('th[data-col], td[data-col]')) {
				cell.hidden = this.hidden.includes(cell.dataset.col) && optional.has(cell.dataset.col);
			}
			const table = this.$root.querySelector('table');
			if (table) {
				table.dataset.columnsReady = '';
			}
			this.measure?.();
		},

		/** Marks the scroll box while the table overflows it (sticky row actions get an edge). */
		watchWidth() {
			const wrap = this.$root.querySelector('.ap-table-wrap');
			if (!wrap) {
				return;
			}
			// A scroll box takes the keyboard focus while it scrolls, so a table
			// without links or buttons can still be scrolled (axe scrollable-region-focusable)
			this.measure = () => {
				const scrollable = wrap.scrollWidth > wrap.clientWidth + 1;
				wrap.classList.toggle('is-scrollable', scrollable);
				if (scrollable) {
					wrap.setAttribute('tabindex', '0');
				} else {
					wrap.removeAttribute('tabindex');
				}
			};
			if (typeof ResizeObserver === 'function') {
				this.observer = new ResizeObserver(() => this.measure());
				this.observer.observe(wrap);
			}
			this.measure();
		},

		destroy() {
			this.observer?.disconnect();
		},

		storageKey() {
			return `ap-table:${this.id}:hidden`;
		},

		readHidden() {
			const defaults = [...this.$root.querySelectorAll('th[data-col][data-default-hidden]')].map((th) => th.dataset.col);
			const stored = this.id ? readStore(this.storageKey()) : null;
			if (stored === null) {
				return defaults;
			}
			try {
				const value = JSON.parse(stored);
				return Array.isArray(value) ? value.filter((v) => typeof v === 'string') : defaults;
			} catch {
				return defaults;
			}
		},

		toggleAll(checked) {
			for (const row of this.rows()) {
				const box = row.querySelector('[data-select-row]');
				if (box && !row.hidden && !box.disabled) {
					box.checked = checked;
				}
			}
			this.syncSelection();
		},

		syncSelection() {
			const boxes = this.rows()
				.filter((row) => !row.hasAttribute('data-filtered'))
				.map((row) => row.querySelector('[data-select-row]'))
				.filter((box) => box && !box.disabled);
			this.selected = boxes.filter((box) => box.checked).map((box) => box.value);
			for (const row of this.rows()) {
				row.classList.toggle('is-selected', Boolean(row.querySelector('[data-select-row]:checked')));
			}
			const all = this.$root.querySelector('[data-select-all]');
			if (all) {
				all.checked = boxes.length > 0 && this.selected.length === boxes.length;
				all.indeterminate = this.selected.length > 0 && this.selected.length < boxes.length;
			}
			window.dispatchEvent(
				new CustomEvent('ap-table-selection', { detail: { id: this.id, items: [...this.selected] } }),
			);
		},
	};
}
