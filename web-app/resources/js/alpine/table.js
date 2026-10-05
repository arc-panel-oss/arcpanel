// apTable: client-side sort, filter, column chooser and row selection for a
// server-rendered <x-ap-table>. Rows are <tr data-row>; cells carry
// data-col="key" and optionally data-sort-value. Selection checkboxes are
// [data-select-row] (value = item id) and [data-select-all]; changes are
// announced as the window event "ap-table-selection" {id, items}.
import { readStore, writeStore } from './storage.js';

export default function apTable({ id = '' } = {}) {
	return {
		id,
		query: '',
		sortKey: null,
		sortDir: 'ascending',
		hidden: [],
		selected: [],
		visibleCount: 0,

		init() {
			this.hidden = this.readHidden();
			this.applyColumns();
			this.filter();
			this.$watch('query', () => this.filter());
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
				row.hidden = !match;
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
			const empty = this.$root.querySelector('[data-table-empty]');
			if (empty) {
				empty.hidden = visible > 0 || this.rows().length === 0;
			}
			this.syncSelection();
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
			for (const cell of this.$root.querySelectorAll('th[data-col], td[data-col]')) {
				cell.hidden = this.hidden.includes(cell.dataset.col) && cell.dataset.optional !== undefined;
			}
		},

		storageKey() {
			return `ap-table:${this.id}:hidden`;
		},

		readHidden() {
			if (!this.id) {
				return [];
			}
			try {
				const value = JSON.parse(readStore(this.storageKey()) ?? '[]');
				return Array.isArray(value) ? value.filter((v) => typeof v === 'string') : [];
			} catch {
				return [];
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
				.filter((row) => !row.hidden)
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
