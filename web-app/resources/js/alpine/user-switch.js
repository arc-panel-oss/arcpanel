// apUserSwitch: the "Switch user" menu of the top bar, on its <details>.
// Filters the accounts the signed-in administrator or reseller may log in as
// (ShellComposer lists all of them), moves through them with the arrow keys
// and submits the form with a listed name only: any other name would answer
// 404. Without this script the input and its <datalist> still work.

/**
 * Accounts whose name or full name contains the text: names that start with
 * it first, then other name matches, then full-name matches.
 * @param {{name: string, fullName?: string}[]} users
 * @param {string} query
 * @param {number} limit
 * @returns {{items: object[], more: number}}
 */
export function matchUsers(users, query, limit) {
	const text = String(query ?? '')
		.trim()
		.toLowerCase();
	const ranked = [];
	for (const user of users) {
		const name = user.name.toLowerCase();
		const rank = text === '' ? 0 : name.startsWith(text) ? 0 : name.includes(text) ? 1 : (user.fullName ?? '').toLowerCase().includes(text) ? 2 : -1;
		if (rank >= 0) {
			ranked.push({ user, rank });
		}
	}
	ranked.sort((a, b) => a.rank - b.rank);
	return { items: ranked.slice(0, limit).map((entry) => entry.user), more: Math.max(0, ranked.length - limit) };
}

export default function apUserSwitch({ users = [], limit = 8 } = {}) {
	return {
		users,
		query: '',
		active: 0,

		init() {
			this.$watch('query', () => {
				this.active = 0;
			});
		},

		get matches() {
			return matchUsers(this.users, this.query, limit);
		},

		/** A fresh search each time the menu opens */
		opened(event) {
			if (event.target.open) {
				this.query = '';
				this.active = 0;
				this.$nextTick(() => this.$refs.input?.focus());
			}
		},

		move(step) {
			const count = this.matches.items.length;
			if (count > 0) {
				this.active = (this.active + step + count) % count;
				this.$nextTick(() => this.$root.querySelector('[role="option"][aria-selected="true"]')?.scrollIntoView?.({ block: 'nearest' }));
			}
		},

		keydown(event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault();
				this.move(event.key === 'ArrowDown' ? 1 : -1);
			} else if (event.key === 'Enter') {
				event.preventDefault();
				const user = this.matches.items[this.active];
				if (user) {
					this.pick(user.name);
				}
			}
		},

		/** Submit only a listed name: the typed one, else the highlighted match */
		submit(event) {
			const input = this.$refs.input;
			const typed = input.value.trim();
			if (this.users.some((user) => user.name === typed)) {
				return;
			}
			const user = this.matches.items[this.active];
			if (user) {
				input.value = user.name;
			} else {
				event.preventDefault();
			}
		},

		pick(name) {
			this.query = name;
			this.$refs.input.value = name;
			this.$refs.input.form.requestSubmit();
		},
	};
}
