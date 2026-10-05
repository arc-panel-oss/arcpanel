// apCronBuilder: fills the five cron fields of a form from presets and from
// the generator tabs of the legacy add and edit pages (port of
// cronGenerator.js). Fields are inputs named min, hour, day, month and wday
// (with an optional prefix) inside the same form.

export const CRON_FIELDS = ['min', 'hour', 'day', 'month', 'wday'];

export const CRON_PRESETS = {
	minute: ['*', '*', '*', '*', '*'],
	hourly: ['0', '*', '*', '*', '*'],
	daily: ['0', '0', '*', '*', '*'],
	weekly: ['0', '0', '*', '*', '0'],
	monthly: ['0', '0', '1', '*', '*'],
};

// Initial choices of each generator tab (the selected options of the legacy
// templates/pages/add_cron.php); fields a tab has no select for stay '*'.
export const CRON_GENERATOR = {
	minutes: { min: '*', hour: '*', day: '*', month: '*', wday: '*' },
	hourly: { min: '0', hour: '*', day: '*', month: '*', wday: '*' },
	daily: { min: '0', hour: '12', day: '*', month: '*', wday: '*' },
	weekly: { min: '0', hour: '12', day: '*', month: '*', wday: '*' },
	monthly: { min: '0', hour: '12', day: '1', month: '*', wday: '*' },
};

export default function apCronBuilder({ prefix = '' } = {}) {
	return {
		prefix,
		values: { min: '*', hour: '*', day: '*', month: '*', wday: '*' },
		tab: 'minutes',
		gen: structuredClone(CRON_GENERATOR),

		init() {
			this.read();
			this.$root.closest('form')?.addEventListener('input', () => this.read());
		},

		get expression() {
			return CRON_FIELDS.map((field) => this.values[field] || '?').join(' ');
		},

		input(field) {
			const form = this.$root.closest('form') ?? document;
			return form.querySelector(`[name="${this.prefix}${field}"]`);
		},

		// Take the current values of the form fields (typed by hand or old input).
		read() {
			for (const field of CRON_FIELDS) {
				const input = this.input(field);
				if (input) {
					this.values[field] = input.value;
				}
			}
		},

		preset(name) {
			const preset = CRON_PRESETS[name];
			if (!preset) {
				return;
			}
			CRON_FIELDS.forEach((field, index) => {
				this.values[field] = preset[index];
			});
		},

		// "Generate" of the legacy page: the choices of the open tab go into the fields.
		generate() {
			const choice = this.gen[this.tab];
			if (!choice) {
				return;
			}
			for (const field of CRON_FIELDS) {
				this.values[field] = choice[field];
			}
			this.apply();
		},

		// Every field is written before any input event, so the form listener
		// (read) never sees a half-updated schedule.
		apply() {
			const inputs = CRON_FIELDS.map((field) => [field, this.input(field)]).filter(([, input]) => input);
			for (const [field, input] of inputs) {
				input.value = this.values[field];
			}
			for (const [, input] of inputs) {
				input.dispatchEvent(new Event('input', { bubbles: true }));
			}
		},
	};
}
