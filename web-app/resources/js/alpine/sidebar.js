// apSidebar: the phone sidebar as an off-canvas drawer (CSS .has-js rules),
// the icon rail of wider screens (data-sidebar="rail" on <html>, kept in
// the cookie arc_ui) and collapsible navigation groups remembered per browser.
import { readStore, writeStore } from './storage.js';
import { writePreferences } from './user-menu.js';

/** Width from which the menu button collapses the sidebar instead of opening the drawer. */
export const WIDE_QUERY = '(min-width: 768px)';

export default function apSidebar({ rail = false } = {}) {
	return {
		open: false,
		rail,
		wide: false,
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
			this.media = typeof window.matchMedia === 'function' ? window.matchMedia(WIDE_QUERY) : null;
			this.wide = Boolean(this.media?.matches);
			this.onMedia = (event) => {
				this.wide = event.matches;
				if (event.matches) {
					this.open = false;
				}
			};
			this.media?.addEventListener?.('change', this.onMedia);
		},

		destroy() {
			window.removeEventListener('keydown', this.onKey);
			this.media?.removeEventListener?.('change', this.onMedia);
		},

		/** The menu button: the rail on wide screens, the drawer on phones. */
		toggle() {
			if (this.wide) {
				this.toggleRail();
			} else {
				this.show();
			}
		},

		/** aria-expanded of the menu button. */
		expanded() {
			return this.wide ? !this.rail : this.open;
		},

		toggleRail() {
			this.rail = !this.rail;
			const value = this.rail ? 'rail' : 'full';
			document.documentElement.dataset.sidebar = value;
			writePreferences({ sidebar: value });
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
