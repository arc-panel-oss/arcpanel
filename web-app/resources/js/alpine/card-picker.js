// apCardPicker: a radio group drawn as cards (template and app selection,
// docs/design/09). Native radios keep the keyboard behaviour; the module
// tracks the value and filters cards by text and category.

export default function apCardPicker({ value = '' } = {}) {
	return {
		value,
		query: '',
		category: '',
		empty: false,

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
			this.$watch('category', () => this.filter());
		},

		filter() {
			const needle = this.query.trim().toLowerCase();
			let shown = 0;
			for (const card of this.$root.querySelectorAll('[data-card]')) {
				const text = (card.dataset.search ?? card.textContent).toLowerCase();
				const inCategory = this.category === '' || card.dataset.category === this.category;
				card.hidden = !inCategory || (needle !== '' && !text.includes(needle));
				if (!card.hidden) {
					shown++;
				}
			}
			this.empty = shown === 0;
		},
	};
}
