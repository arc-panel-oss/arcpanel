// apChoiceList: names ticked from a list, or * for every name, posted as
// one comma separated field (RESELLER_PACKAGES of a package).

export default function apChoiceList({ value = '' } = {}) {
	const raw = String(value ?? '').trim();

	return {
		all: raw === '*',
		picked: raw === '*' ? [] : raw.split(/\s*,\s*/).filter((name) => name !== ''),

		/** The posted value */
		get value() {
			return this.all ? '*' : this.picked.join(',');
		},
	};
}
