// apNameServers: the NS1 … NS8 fields of a DNS zone (port of the legacy
// "Add Name Server" link). At least `min` fields stay visible, at most `max`
// exist; every field posts as ns[].

export default function apNameServers({ values = [], min = 2, max = 8 } = {}) {
	const servers = values
		.map((value) => String(value ?? '').trim())
		.filter((value) => value !== '')
		.slice(0, max);
	while (servers.length < min) {
		servers.push('');
	}

	return {
		servers,
		min,
		max,

		get canAdd() {
			return this.servers.length < this.max;
		},

		get canRemove() {
			return this.servers.length > this.min;
		},

		add() {
			if (!this.canAdd) {
				return;
			}
			this.servers.push('');
			const index = this.servers.length - 1;
			this.$nextTick(() => this.$root.querySelector(`[data-ns-index="${index}"]`)?.focus());
		},

		remove(index) {
			if (!this.canRemove || index < 0 || index >= this.servers.length) {
				return;
			}
			this.servers.splice(index, 1);
		},
	};
}
