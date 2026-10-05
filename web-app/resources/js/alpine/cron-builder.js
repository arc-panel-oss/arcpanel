// apCronBuilder: fills the five cron fields of a form from presets (port of
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

export default function apCronBuilder({ prefix = '' } = {}) {
	return {
		prefix,
		values: { min: '*', hour: '*', day: '*', month: '*', wday: '*' },

		get expression() {
			return CRON_FIELDS.map((field) => this.values[field]).join(' ');
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

		apply() {
			const form = this.$root.closest('form') ?? document;
			for (const field of CRON_FIELDS) {
				const input = form.querySelector(`[name="${this.prefix}${field}"]`);
				if (input) {
					input.value = this.values[field];
					input.dispatchEvent(new Event('input', { bubbles: true }));
				}
			}
		},
	};
}
