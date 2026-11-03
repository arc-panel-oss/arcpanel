// apFormState: whether a long form has unsaved changes (the sticky form bar
// of x-ap-form-bar). It compares the posted fields with those of the page
// load and asks before the page is left with changes.

export default function apFormState() {
	return {
		dirty: false,
		saving: false,
		initial: '',

		init() {
			// After the components of the form have set their hidden fields
			this.$nextTick(() => {
				this.initial = this.snapshot();
			});
			const check = () => this.$nextTick(() => this.check());
			this.$el.addEventListener('input', check);
			this.$el.addEventListener('change', check);
			this.$el.addEventListener('submit', () => {
				this.saving = true;
			});
			this.guard = (event) => {
				if (this.dirty && !this.saving) {
					event.preventDefault();
					event.returnValue = '';
				}
			};
			globalThis.addEventListener('beforeunload', this.guard);
		},

		destroy() {
			globalThis.removeEventListener('beforeunload', this.guard);
		},

		snapshot() {
			return new URLSearchParams(new FormData(this.$el)).toString();
		},

		check() {
			this.dirty = this.snapshot() !== this.initial;
		},
	};
}
