// apLimit: a limit as an amount, a unit and "unlimited" (x-ap-limit-input).
// The three controls post nothing; one hidden field posts the whole value,
// such as 512M, 1.5 or unlimited, as the server reads it. `bare` is the
// unit a number without one means (DOCKER_MEMORY 512 is 512 MB).

const SPLIT = /^(\d+(?:\.\d+)?)([A-Za-z%]*)$/;

export default function apLimit({ value = '', units = [], bare = '' } = {}) {
	const raw = String(value ?? '').trim();
	const unlimited = raw === 'unlimited';
	const parts = unlimited ? null : SPLIT.exec(raw);

	return {
		unlimited,
		amount: unlimited ? '' : parts ? parts[1] : raw,
		unit: unlimited ? (units[0] ?? '') : parts ? parts[2] || bare : '',
		previous: '',

		/** The posted value */
		get value() {
			if (this.unlimited) {
				return 'unlimited';
			}
			const amount = String(this.amount).trim();
			return amount === '' ? '' : amount + this.unit;
		},

		toggle() {
			if (this.unlimited) {
				this.unlimited = false;
				this.amount = this.previous !== '' ? this.previous : '0';
			} else {
				this.previous = this.amount;
				this.unlimited = true;
			}
		},
	};
}
