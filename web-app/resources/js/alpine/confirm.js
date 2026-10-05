// apConfirm: one confirmation dialog for the page. Any form whose submit
// button or <form> carries data-confirm="message" is held until the user
// accepts. "%d" in the message becomes the number of selected items[].
// Without JavaScript the form submits directly.
import { __ } from '../i18n.js';
import { focusFirst, trapFocus } from './focus.js';

export default function apConfirm() {
	return {
		open: false,
		title: '',
		message: '',
		acceptLabel: '',
		danger: false,
		pending: null,
		returnFocus: null,

		init() {
			this.onSubmit = (event) => this.intercept(event);
			document.addEventListener('submit', this.onSubmit, true);
		},

		destroy() {
			document.removeEventListener('submit', this.onSubmit, true);
		},

		intercept(event) {
			const form = event.target;
			if (!(form instanceof HTMLFormElement) || form.dataset.confirmed === 'yes') {
				delete form.dataset?.confirmed;
				return;
			}
			const submitter = event.submitter ?? null;
			const source = submitter?.dataset.confirm !== undefined ? submitter : form;
			const message = source.dataset.confirm;
			if (!message) {
				return;
			}

			event.preventDefault();
			const count = new FormData(form).getAll('items[]').length;
			this.message = message.replace('%d', String(count));
			this.title = source.dataset.confirmTitle || __('Are you sure?');
			this.acceptLabel = source.dataset.confirmAccept || __('Confirm');
			this.danger = source.dataset.confirmDanger !== undefined;
			this.pending = { form, submitter };
			this.returnFocus = document.activeElement;
			this.open = true;
			this.$nextTick(() => focusFirst(this.$refs.dialog ?? this.$root));
		},

		accept() {
			const pending = this.pending;
			this.open = false;
			this.pending = null;
			if (!pending) {
				return;
			}
			pending.form.dataset.confirmed = 'yes';
			if (typeof pending.form.requestSubmit === 'function') {
				pending.form.requestSubmit(pending.submitter ?? undefined);
			} else {
				pending.form.submit();
			}
		},

		cancel() {
			this.open = false;
			this.pending = null;
			this.returnFocus?.focus?.();
			this.returnFocus = null;
		},

		onKeydown(event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				this.cancel();
			} else {
				trapFocus(this.$refs.dialog ?? this.$root, event);
			}
		},
	};
}
