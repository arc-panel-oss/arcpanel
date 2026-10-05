// apToast: one toast. Success toasts leave after 6 s (paused while hovered
// or focused); errors stay until dismissed (docs/design/05 §5).

export default function apToast({ sticky = false, timeout = 6000 } = {}) {
	return {
		sticky,
		timeout,
		timer: null,

		init() {
			this.start();
			this.$root.addEventListener('mouseenter', () => this.stop());
			this.$root.addEventListener('mouseleave', () => this.start());
			this.$root.addEventListener('focusin', () => this.stop());
			this.$root.addEventListener('focusout', () => this.start());
		},

		destroy() {
			this.stop();
		},

		start() {
			if (this.sticky) {
				return;
			}
			this.stop();
			this.timer = setTimeout(() => this.dismiss(), this.timeout);
		},

		stop() {
			clearTimeout(this.timer);
			this.timer = null;
		},

		dismiss() {
			this.stop();
			this.$root.remove();
		},
	};
}
