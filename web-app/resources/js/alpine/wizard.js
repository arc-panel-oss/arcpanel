// apWizard: a multi-step form. Steps are <fieldset data-step>; "Next" checks
// the fields of the current step with the browser's validation first. The
// server validates every step again (FormRequest per step, docs/design/05 §5).

export default function apWizard({ start = 0 } = {}) {
	return {
		step: start,

		init() {
			this.show();
		},

		steps() {
			return [...this.$root.querySelectorAll('[data-step]')];
		},

		get count() {
			return this.steps().length;
		},

		get isFirst() {
			return this.step === 0;
		},

		get isLast() {
			return this.step >= this.count - 1;
		},

		show() {
			this.steps().forEach((fieldset, index) => {
				fieldset.hidden = index !== this.step;
			});
		},

		valid() {
			const current = this.steps()[this.step];
			if (!current) {
				return true;
			}
			return [...current.querySelectorAll('input, select, textarea')].every(
				(field) => typeof field.reportValidity !== 'function' || field.reportValidity(),
			);
		},

		next() {
			if (!this.isLast && this.valid()) {
				this.step++;
				this.show();
			}
		},

		back() {
			if (!this.isFirst) {
				this.step--;
				this.show();
			}
		},
	};
}
