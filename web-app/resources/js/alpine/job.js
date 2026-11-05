// apJob: the Job page of the Job Center (E15 spec §6.2, D5). While the job
// is queued or running, GET url?offset=N (JSON { task }) every 2 s, append
// the log chunk and keep the next offset; once it has finished, reload the
// page so that the actions and the times are current. Failed requests are
// skipped; the next tick tries again.
import { request } from '../http.js';

export const INTERVAL = 2000;
export const RELOAD_DELAY = 1000;
export const MAX_LOG = 1048576;

export default function apJob({ url = '', status = '', offset = 0, log = '', reload = true } = {}) {
	return {
		url,
		status,
		offset,
		log,
		timer: null,
		busy: false,

		get active() {
			return this.status === 'queued' || this.status === 'running';
		},

		init() {
			if (this.active) {
				this.timer = setInterval(() => this.poll(), INTERVAL);
			}
		},

		destroy() {
			clearInterval(this.timer);
			this.timer = null;
		},

		async poll() {
			if (this.busy) {
				return;
			}
			this.busy = true;
			let data;
			try {
				data = await request(`${this.url}?offset=${this.offset}`);
			} catch {
				return;
			} finally {
				this.busy = false;
			}
			const task = data?.task;
			if (!task) {
				return;
			}
			if (typeof task.log === 'string' && task.log !== '') {
				this.log = (this.log + task.log).slice(-MAX_LOG);
				this.$nextTick?.(() => this.scroll());
			}
			if (Number.isInteger(task.offset)) {
				this.offset = task.offset;
			}
			this.status = task.status;
			if (task.finished) {
				this.destroy();
				if (reload) {
					setTimeout(() => window.location.reload(), RELOAD_DELAY);
				}
			}
		},

		scroll() {
			const body = this.$refs?.log;
			if (body) {
				body.scrollTop = body.scrollHeight;
			}
		},
	};
}
