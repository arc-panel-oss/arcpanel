// apDeploy: the Deploy tab of a web domain (E20 spec §7,
// docs/features/specs/2026-10-29-001-e20-git-deploy-design.md). A deploy is
// a deploy.* job of the Job Center (E15 spec §6.2): while it runs, GET
// /jobs/{job}/status every 2 s (the end of the log first, then the log after
// the last offset) and show its lines; once it has ended, reload the page so
// the releases and the status show the result. openLog(id, title) reads the
// end of the log of a job into the drawer "deploy-log". Failed requests are
// skipped; the next tick tries again. copy(key, value) copies a value of the
// tab and names it in `copied` for a moment; unlike apAccessKeySecret it does
// not guard leaving the page (only the one-time webhook secret needs that).
import { request } from '../http.js';
import { jobStatusUrl } from '../islands/shared/job-task.js';

export const INTERVAL = 2000;
export const RELOAD_DELAY = 1500;
export const COPIED_FOR = 1500;
export const MAX_LINES = 2000;

/** The lines of a log, without the empty line after the last break */
export function logLines(log) {
	const text = String(log ?? '').replace(/\n$/, '');
	return text === '' ? [] : text.split('\n');
}

export default function apDeploy({ job = '', running = false, lines = [] } = {}) {
	return {
		job,
		running,
		lines,
		offset: null,
		log: '',
		timer: null,
		copied: '',
		drawer: { title: '', lines: [], loading: false, error: '' },

		init() {
			if (this.running && this.job) {
				this.timer = setInterval(() => this.poll(), INTERVAL);
			}
		},

		destroy() {
			clearInterval(this.timer);
			this.timer = null;
		},

		async poll() {
			let data;
			try {
				data = await request(jobStatusUrl(this.job, this.offset));
			} catch {
				return;
			}
			const task = data?.task;
			if (!task) {
				return;
			}
			const chunk = typeof task.log === 'string' ? task.log : '';
			this.log = this.offset === null ? chunk : `${this.log}${chunk}`;
			if (Number.isInteger(task.offset)) {
				this.offset = task.offset;
			}
			const next = logLines(this.log);
			if (next.length > 0) {
				this.lines = next.slice(-MAX_LINES);
			}
			if (task.finished) {
				this.running = false;
				this.destroy();
				setTimeout(() => globalThis.location.reload(), RELOAD_DELAY);
			}
		},

		async copy(key, value) {
			const clipboard = globalThis.navigator?.clipboard;
			if (!clipboard) {
				return;
			}
			try {
				await clipboard.writeText(value);
			} catch {
				return;
			}
			this.copied = key;
			setTimeout(() => {
				if (this.copied === key) {
					this.copied = '';
				}
			}, COPIED_FOR);
		},

		async openLog(id, title) {
			this.drawer = { title, lines: [], loading: true, error: '' };
			this.$dispatch?.('ap-modal', { id: 'deploy-log' });
			try {
				const data = await request(jobStatusUrl(id));
				this.drawer.lines = logLines(data?.task?.log);
			} catch (error) {
				this.drawer.error = error?.message || 'The log could not be read.';
			} finally {
				this.drawer.loading = false;
			}
		},
	};
}
