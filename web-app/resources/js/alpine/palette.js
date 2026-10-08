// apPalette: command palette (Ctrl+K / ⌘K). Queries the JSON route
// GET url?q= (debounced 150 ms, from 2 characters), shows grouped results
// and is driven from the keyboard.
import { request } from '../http.js';
import { focusFirst, trapFocus } from './focus.js';

export default function apPalette({ url = '', searchUrl = '', delay = 150, minLength = 2 } = {}) {
	return {
		url,
		searchUrl,
		open: false,
		query: '',
		groups: [],
		active: 0,
		loading: false,
		error: '',
		timer: null,
		controller: null,
		returnFocus: null,

		init() {
			this.onKey = (event) => {
				if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
					event.preventDefault();
					this.open ? this.close() : this.show();
				}
			};
			this.onRequest = () => this.show();
			window.addEventListener('keydown', this.onKey);
			window.addEventListener('ap-palette', this.onRequest);
		},

		destroy() {
			window.removeEventListener('keydown', this.onKey);
			window.removeEventListener('ap-palette', this.onRequest);
			clearTimeout(this.timer);
			this.controller?.abort();
		},

		get items() {
			return this.groups.flatMap((group) => group.items);
		},

		show() {
			this.returnFocus = document.activeElement;
			this.open = true;
			this.$nextTick(() => focusFirst(this.$refs.dialog ?? this.$root));
		},

		close() {
			if (!this.open) {
				return;
			}
			this.open = false;
			if (this.returnFocus && this.returnFocus !== document.body) {
				this.returnFocus.focus?.();
			} else if (this.$refs.dialog?.contains(document.activeElement)) {
				// Opened with Ctrl K from the page: focus must not stay in the hidden dialog
				document.activeElement.blur();
			}
			this.returnFocus = null;
		},

		onInput() {
			clearTimeout(this.timer);
			this.error = '';
			if (this.query.trim().length < minLength) {
				this.controller?.abort();
				this.groups = [];
				this.loading = false;
				return;
			}
			this.timer = setTimeout(() => this.search(), delay);
		},

		async search() {
			this.controller?.abort();
			this.controller = new AbortController();
			const query = this.query.trim();
			this.loading = true;
			try {
				const data = await request(`${this.url}?q=${encodeURIComponent(query)}`, {
					signal: this.controller.signal,
				});
				if (query === this.query.trim()) {
					this.groups = Array.isArray(data?.groups) ? data.groups : [];
					this.active = 0;
				}
			} catch (error) {
				if (error?.name !== 'AbortError') {
					this.groups = [];
					this.error = error.message;
				}
			} finally {
				this.loading = false;
			}
		},

		indexOf(item) {
			return this.items.indexOf(item);
		},

		/** Split a label around the query for <mark>. */
		parts(label) {
			const needle = this.query.trim().toLowerCase();
			const at = needle === '' ? -1 : label.toLowerCase().indexOf(needle);
			if (at < 0) {
				return [{ text: label, mark: false }];
			}
			return [
				{ text: label.slice(0, at), mark: false },
				{ text: label.slice(at, at + needle.length), mark: true },
				{ text: label.slice(at + needle.length), mark: false },
			].filter((part) => part.text !== '');
		},

		move(delta) {
			const count = this.items.length;
			if (count > 0) {
				this.active = (this.active + delta + count) % count;
			}
		},

		/** The search page with every result of the query (E7 batch 3 spec §12.1). */
		get allResultsUrl() {
			const query = this.query.trim();
			return this.searchUrl && query.length >= minLength ? `${this.searchUrl}?q=${encodeURIComponent(query)}` : '';
		},

		go(item = this.items[this.active]) {
			if (item?.url) {
				window.location.assign(item.url);
			}
		},

		onKeydown(event) {
			switch (event.key) {
				case 'ArrowDown':
					event.preventDefault();
					this.move(1);
					break;
				case 'ArrowUp':
					event.preventDefault();
					this.move(-1);
					break;
				case 'Enter':
					if (this.items.length > 0) {
						event.preventDefault();
						this.go();
					} else if (this.allResultsUrl) {
						event.preventDefault();
						window.location.assign(this.allResultsUrl);
					}
					break;
				case 'Escape':
					event.preventDefault();
					this.close();
					break;
				default:
					trapFocus(this.$refs.dialog ?? this.$root, event);
			}
		},
	};
}
