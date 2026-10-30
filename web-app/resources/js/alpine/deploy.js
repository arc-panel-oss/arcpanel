// apDeploy: the Deploy tab of a web domain (E20 spec §7,
// docs/features/specs/2026-10-29-001-e20-git-deploy-design.md). While a
// deploy runs, GET stateUrl (JSON { running, lines }) every 2 s and show its
// log; once it has ended, reload the page so the releases and the status
// show the result. openLog(id, title) reads the log of a task (taskUrl with
// the id in place of the zeros) into the drawer "deploy-log". Failed
// requests are skipped; the next tick tries again. copy(key, value) copies
// a value of the tab and names it in `copied` for a moment; unlike
// apAccessKeySecret it does not guard leaving the page (only the one-time
// webhook secret needs that).
import { request } from '../http.js';

export const INTERVAL = 2000;
export const RELOAD_DELAY = 1500;
export const COPIED_FOR = 1500;

export default function apDeploy({ stateUrl = '', taskUrl = '', running = false, lines = [] } = {}) {
	return {
		stateUrl,
		taskUrl,
		running,
		lines,
		timer: null,
		copied: '',
		drawer: { title: '', lines: [], loading: false, error: '' },

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
				data = await request(this.stateUrl);
			} catch {
				return;
			}
			if (!data || typeof data.running !== 'boolean') {
				return;
			}
			if (Array.isArray(data.lines) && data.lines.length > 0) {
				this.lines = data.lines;
			}
			if (!data.running) {
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
				const data = await request(this.taskUrl.replace(/0{14}$/, String(id)));
				this.drawer.lines = Array.isArray(data?.lines) ? data.lines : [];
			} catch (error) {
				this.drawer.error = error?.message || 'The log could not be read.';
			} finally {
				this.drawer.loading = false;
			}
		},
	};
}
