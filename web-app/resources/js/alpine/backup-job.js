// The form of a backup job (E48 spec §8.2 Schedules): the schedule preset
// and its fields, the preview of the next runs from the same code as the
// dispatcher, the scope and the rows of the targets.
import { request } from '../http.js';

const blankTarget = () => ({ destination: '', keep_last: '', daily: '', weekly: '', monthly: '', yearly: '' });

/**
 * apBackupJob: preset, hour, minute, weekday, day and cron are the schedule
 * fields; previewUrl answers {valid, describe, times} or {valid: false,
 * message}. targets are the rows of the destinations table.
 */
export default function apBackupJob({
	previewUrl = '',
	preset = 'daily',
	hour = 5,
	minute = 10,
	weekday = 1,
	day = 1,
	cron = '',
	scope = 'all',
	engine = 'archive',
	targets = [],
} = {}) {
	return {
		preset,
		hour,
		minute,
		weekday,
		day,
		cron,
		scope,
		engine,
		targets: targets.length > 0 ? targets : [blankTarget()],
		preview: { valid: true, describe: '', times: [], message: '' },
		loading: false,
		timer: null,
		seq: 0,

		init() {
			for (const field of ['preset', 'hour', 'minute', 'weekday', 'day', 'cron']) {
				this.$watch(field, () => this.schedulePreview());
			}
			this.refreshPreview();
		},

		schedulePreview() {
			clearTimeout(this.timer);
			this.timer = setTimeout(() => this.refreshPreview(), 300);
		},

		params() {
			const params = new URLSearchParams({ preset: this.preset });
			if (this.preset === 'cron') {
				params.set('cron', this.cron.trim());
			} else {
				params.set('minute', String(this.minute));
				if (this.preset !== 'hourly') {
					params.set('hour', String(this.hour));
				}
				if (this.preset === 'weekly') {
					params.set('weekday', String(this.weekday));
				}
				if (this.preset === 'monthly') {
					params.set('day', String(this.day));
				}
			}
			return params;
		},

		async refreshPreview() {
			if (previewUrl === '' || (this.preset === 'cron' && this.cron.trim() === '')) {
				this.preview = { valid: true, describe: '', times: [], message: '' };
				return;
			}
			const seq = ++this.seq;
			this.loading = true;
			try {
				const answer = await request(`${previewUrl}?${this.params()}`);
				if (seq !== this.seq) {
					return;
				}
				this.preview = {
					valid: Boolean(answer.valid),
					describe: answer.describe ?? '',
					times: answer.times ?? [],
					message: answer.message ?? '',
				};
			} catch (e) {
				if (seq === this.seq) {
					this.preview = { valid: false, describe: '', times: [], message: e instanceof Error ? e.message : String(e) };
				}
			} finally {
				if (seq === this.seq) {
					this.loading = false;
				}
			}
		},

		addTarget() {
			this.targets.push(blankTarget());
		},

		removeTarget(index) {
			if (this.targets.length > 1) {
				this.targets.splice(index, 1);
			}
		},
	};
}
