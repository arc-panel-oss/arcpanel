// apSidebar: the phone sidebar as an off-canvas drawer (CSS .has-js rules),
// and collapsible navigation groups remembered per browser.
import { readStore, writeStore } from './storage.js';

export default function apSidebar() {
	return {
		open: false,
		collapsed: [],

		init() {
			try {
				const value = JSON.parse(readStore('ap-sidebar:collapsed') ?? '[]');
				this.collapsed = Array.isArray(value) ? value : [];
			} catch {
				this.collapsed = [];
			}
			this.onKey = (event) => {
				if (event.key === 'Escape' && this.open) {
					this.close();
				}
			};
			window.addEventListener('keydown', this.onKey);
		},

		destroy() {
			window.removeEventListener('keydown', this.onKey);
		},

		show() {
			this.open = true;
			this.$nextTick(() => this.$refs.sidebar?.querySelector('a, button')?.focus());
		},

		close() {
			this.open = false;
			this.$refs.toggle?.focus();
		},

		isCollapsed(group) {
			return this.collapsed.includes(group);
		},

		toggleGroup(group) {
			this.collapsed = this.isCollapsed(group)
				? this.collapsed.filter((g) => g !== group)
				: [...this.collapsed, group];
			writeStore('ap-sidebar:collapsed', JSON.stringify(this.collapsed));
		},
	};
}
