// apModal: dialog with a focus trap; Esc and the backdrop close it and focus
// returns to the element that opened it. Open with
// $dispatch('ap-modal', { id: 'name' }); close with { id, open: false }.
// { open: true } shows it once the page has loaded (a form the server sent
// back with errors).
import { focusFirst, trapFocus } from './focus.js';

export default function apModal({ id = '', open: startOpen = false } = {}) {
	return {
		id,
		open: false,
		returnFocus: null,

		init() {
			if (startOpen) {
				// After Alpine has set up the page, like an opening click
				setTimeout(() => this.show());
			}
			this.onRequest = (event) => {
				if (event.detail?.id !== this.id) {
					return;
				}
				if (event.detail.open === false) {
					this.close();
				} else {
					this.show();
				}
			};
			window.addEventListener('ap-modal', this.onRequest);
		},

		destroy() {
			window.removeEventListener('ap-modal', this.onRequest);
		},

		show() {
			this.returnFocus = document.activeElement;
			this.open = true;
			// x-show reveals the dialog on the next frame (setTimeout in a
			// hidden tab); focus it once it is visible
			const nextFrame = document.visibilityState === 'visible' ? requestAnimationFrame : setTimeout;
			this.$nextTick(() =>
				nextFrame(() => {
					if (this.open) {
						focusFirst(this.$refs.dialog ?? this.$root);
					}
				}),
			);
		},

		close() {
			if (!this.open) {
				return;
			}
			this.open = false;
			this.returnFocus?.focus?.();
			this.returnFocus = null;
		},

		onKeydown(event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				this.close();
			} else {
				trapFocus(this.$refs.dialog ?? this.$root, event);
			}
		},
	};
}
