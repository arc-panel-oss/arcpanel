// apAccessKeySecret: the one-time secret of a new access key
// (docs/features/specs/2026-10-08-008-access-keys-ux-design.md U5, U7).
// Copy buttons say "Copied" for a moment; until one value has been copied
// (button or keyboard), leaving the page asks first. A form of the page that
// really submits (Delete) leaves on purpose and does not ask.

export default function apAccessKeySecret() {
	return {
		copied: '',
		saved: false,
		leaving: false,

		init() {
			this.guard = (event) => {
				if (!this.saved && !this.leaving) {
					event.preventDefault();
					event.returnValue = '';
				}
			};
			globalThis.addEventListener('beforeunload', this.guard);
		},

		destroy() {
			globalThis.removeEventListener('beforeunload', this.guard);
		},

		/** Copy a value; `copied` names the value for a moment. */
		async copy(key, value) {
			const clipboard = globalThis.navigator?.clipboard;
			if (!clipboard) {
				return;
			}
			try {
				await clipboard.writeText(value);
			} catch {
				return;
			}
			this.saved = true;
			this.copied = key;
			setTimeout(() => {
				if (this.copied === key) {
					this.copied = '';
				}
			}, 1500);
		},

		/** A keyboard copy inside the card counts as saved. */
		onCopy() {
			this.saved = true;
		},

		/** Submit events bubble after apConfirm, which cancels the unconfirmed one. */
		onSubmit(event) {
			if (!event.defaultPrevented) {
				this.leaving = true;
			}
		},
	};
}
