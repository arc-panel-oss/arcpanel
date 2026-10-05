// apBulkBar: shows the bulk actions of a table while rows are selected. The
// row checkboxes belong to the bulk form through their form="" attribute, so
// the form also works without JavaScript; confirmation is done by apConfirm.

export default function apBulkBar({ table = '' } = {}) {
	return {
		table,
		count: 0,
		items: [],

		init() {
			this.onSelection = (event) => {
				if (event.detail?.id === this.table) {
					this.items = event.detail.items;
					this.count = this.items.length;
				}
			};
			window.addEventListener('ap-table-selection', this.onSelection);
		},

		destroy() {
			window.removeEventListener('ap-table-selection', this.onSelection);
		},

		get active() {
			return this.count > 0;
		},
	};
}
