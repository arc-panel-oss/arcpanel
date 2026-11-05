// apTask: the task panel of a page (Quick Install, E7 batch 2 spec §10.3; the
// system task, E7 batch 3 spec §6.2; malware scans), since E15 a job of the
// Job Center. While the job is queued or running, GET url (the job's
// /jobs/{id}/status, JSON { task }) every 2 s: the first call answers the end
// of the log, the next ones the log from the offset of the previous one. When
// the job ends, show doneMessage or failMessage as a toast. Failed requests
// and answers without a task (the panel restarting during a package update)
// are skipped, the next tick tries again. With reloadOnEnd the page reloads
// once the job has ended, so it shows the new server state. The page's first
// state may use the status words of the old task records (done).
import { request } from '../http.js';
import { toast } from '../toast.js';

export const INTERVAL = 2000;
export const RELOAD_DELAY = 1500;
export const MAX_LOG = 262144;

const ACTIVE = ['queued', 'running'];
const DONE = ['done', 'succeeded'];

export default function apTask({ url = '', task = null, doneMessage = '', failMessage = '', reloadOnEnd = false } = {}) {
	return {
		url,
		task,
		offset: null,
		timer: null,

		get running() {
			return ACTIVE.includes(this.task?.status);
		},

		get succeeded() {
			return DONE.includes(this.task?.status);
		},

		init() {
			if (this.running && this.url) {
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
				data = await request(this.offset === null ? this.url : `${this.url}?offset=${this.offset}`);
			} catch {
				// try again with the next tick
				return;
			}
			const next = data?.task;
			if (!next) {
				return;
			}
			// The panel keeps the words it was rendered with (kind, args)
			const { kind: _kind, args: _args, ...state } = next;
			const chunk = typeof next.log === 'string' ? next.log : '';
			const log = this.offset === null ? chunk : `${this.task?.log ?? ''}${chunk}`;
			this.offset = Number.isInteger(next.offset) ? next.offset : this.offset;
			this.task = {
				...(this.task ?? {}),
				...state,
				log: log.slice(-MAX_LOG),
				end: next.finished_at ? String(next.finished_at).replace('T', ' ') : (this.task?.end ?? ''),
			};
			if (!this.running) {
				this.destroy();
				if (this.succeeded) {
					toast('ok', doneMessage);
				} else if (this.task.status === 'failed') {
					toast('error', failMessage);
				}
				if (reloadOnEnd) {
					setTimeout(() => window.location.reload(), RELOAD_DELAY);
				}
			}
		},
	};
}
