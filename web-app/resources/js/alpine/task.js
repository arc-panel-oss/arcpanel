// apTask: a background task of the server (Quick Install, E7 batch 2 spec
// §10.3; the system task, E7 batch 3 spec §6.2). While the task runs, GET url
// (JSON { task }) every 2 s; when it ends, show doneMessage or failMessage as
// a toast. Failed requests and answers without a task (the panel restarting
// during a package update) are skipped, the next tick tries again. With
// reloadOnEnd the page reloads once the task has ended, so it shows the new
// server state.
import { request } from '../http.js';
import { toast } from '../toast.js';

export const INTERVAL = 2000;
export const RELOAD_DELAY = 1500;

export default function apTask({ url = '', task = null, doneMessage = '', failMessage = '', reloadOnEnd = false } = {}) {
	return {
		url,
		task,
		timer: null,

		get running() {
			return this.task?.status === 'running';
		},

		init() {
			if (this.running) {
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
				data = await request(this.url);
			} catch {
				// try again with the next tick
				return;
			}
			if (!data?.task) {
				return;
			}
			this.task = data.task;
			if (!this.running) {
				this.destroy();
				if (this.task.status === 'done') {
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
