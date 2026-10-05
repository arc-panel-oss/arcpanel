// apUnlimited: a number or "unlimited" (port of unlimitedInput.js). The
// checkbox posts name_unlimited=1 without JavaScript as well; with it, the
// number field is disabled and remembers its previous value.

export default function apUnlimited({ unlimited = false } = {}) {
	return {
		unlimited,
		previous: '',

		input() {
			return this.$refs.input ?? this.$root.querySelector('input[type="number"], input[type="text"]');
		},

		toggle() {
			const input = this.input();
			if (this.unlimited) {
				this.unlimited = false;
				input.value = this.previous !== '' ? this.previous : '0';
				input.disabled = false;
			} else {
				this.unlimited = true;
				this.previous = input.value;
				input.disabled = true;
			}
		},
	};
}
