// apCardPicker: a radio group drawn as cards (template and app selection,
// docs/design/09). Native radios keep the keyboard behaviour; the module
// tracks the value and filters cards by name.

export default function apCardPicker({ value = '' } = {}) {
	return {
		value,
		query: '',

		init() {
			const checked = this.$root.querySelector('input[type="radio"]:checked');
			if (checked) {
				this.value = checked.value;
			}
			this.$root.addEventListener('change', (event) => {
				if (event.target.matches('input[type="radio"]')) {
					this.value = event.target.value;
				}
			});
			this.$watch('query', () => this.filter());
		},

		filter() {
			const needle = this.query.trim().toLowerCase();
			for (const card of this.$root.querySelectorAll('[data-card]')) {
				card.hidden = needle !== '' && !(card.dataset.search ?? card.textContent).toLowerCase().includes(needle);
			}
		},
	};
}
