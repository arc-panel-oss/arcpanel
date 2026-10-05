// apTabs: tab panels synced with the URL hash (port of tabPanels.js).
// Tabs are links <a role="tab" data-tab="name" href="#name">, panels are
// <section role="tabpanel" id="name">. Without JavaScript the links jump to
// the stacked panels.

export default function apTabs({ initial = '' } = {}) {
	return {
		active: '',

		init() {
			const fromHash = decodeURIComponent(window.location.hash.slice(1));
			this.select(this.has(fromHash) ? fromHash : this.has(initial) ? initial : this.names()[0], false);
			this.onHash = () => {
				const name = decodeURIComponent(window.location.hash.slice(1));
				if (this.has(name)) {
					this.select(name, false);
				}
			};
			window.addEventListener('hashchange', this.onHash);
		},

		destroy() {
			window.removeEventListener('hashchange', this.onHash);
		},

		tabs() {
			return [...this.$root.querySelectorAll('[role="tab"][data-tab]')];
		},

		names() {
			return this.tabs().map((tab) => tab.dataset.tab);
		},

		has(name) {
			return Boolean(name) && this.names().includes(name);
		},

		select(name, updateHash = true) {
			if (!this.has(name)) {
				return;
			}
			this.active = name;
			for (const tab of this.tabs()) {
				const selected = tab.dataset.tab === name;
				tab.setAttribute('aria-selected', String(selected));
				tab.setAttribute('tabindex', selected ? '0' : '-1');
			}
			for (const panel of this.$root.querySelectorAll('[role="tabpanel"]')) {
				panel.hidden = panel.id !== name;
			}
			if (updateHash && window.location.hash !== `#${name}`) {
				window.history.replaceState(null, '', `#${name}`);
			}
		},

		onClick(event) {
			const tab = event.target.closest('[role="tab"][data-tab]');
			if (tab) {
				event.preventDefault();
				this.select(tab.dataset.tab);
			}
		},

		onKeydown(event) {
			const names = this.names();
			const index = names.indexOf(this.active);
			const rtl = getComputedStyle(this.$root).direction === 'rtl';
			const keys = {
				ArrowRight: rtl ? -1 : 1,
				ArrowLeft: rtl ? 1 : -1,
				Home: -index,
				End: names.length - 1 - index,
			};
			if (!(event.key in keys) || index < 0) {
				return;
			}
			event.preventDefault();
			const next = names[(index + keys[event.key] + names.length) % names.length];
			this.select(next);
			this.tabs()
				.find((tab) => tab.dataset.tab === next)
				?.focus();
		},
	};
}
