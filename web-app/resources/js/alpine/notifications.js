// apNotifications: the notification tray. Loads GET url (JSON), polls every
// 60 s while the tab is visible, acknowledges with POST url/{id}/acknowledge
// and deletes with DELETE url/{id}; POST url/acknowledge and DELETE url act on
// every notification. With jobsUrl, the Jobs tab (E15 spec §6.2) lists the
// running jobs and the newest finished ones from GET jobsUrl, with the number
// of running jobs as a badge; it polls every 5 s while the tab is open.
import { request } from '../http.js';
import { toast } from '../toast.js';

export const JOBS_INTERVAL = 5000;

export default function apNotifications({ url = '', jobsUrl = '', interval = 60000 } = {}) {
	return {
		url,
		jobsUrl,
		open: false,
		tab: 'notifications',
		items: [],
		jobs: [],
		running: 0,
		loaded: false,
		jobsLoaded: false,
		timer: null,
		jobsTimer: null,

		init() {
			this.load();
			this.timer = setInterval(() => {
				if (document.visibilityState === 'visible') {
					this.load();
				}
			}, interval);
			this.onVisible = () => {
				if (document.visibilityState === 'visible') {
					this.load();
				}
			};
			document.addEventListener('visibilitychange', this.onVisible);
		},

		destroy() {
			clearInterval(this.timer);
			clearInterval(this.jobsTimer);
			document.removeEventListener('visibilitychange', this.onVisible);
		},

		get unread() {
			return this.items.filter((item) => !item.acknowledged).length;
		},

		async load() {
			try {
				const data = await request(this.url);
				this.items = Array.isArray(data?.notifications) ? data.notifications : [];
				this.loaded = true;
			} catch {
				// keep the last list; the next poll tries again
			}
			await this.loadJobs();
		},

		async loadJobs() {
			if (!this.jobsUrl) {
				return;
			}
			try {
				const data = await request(this.jobsUrl);
				this.jobs = Array.isArray(data?.items) ? data.items : [];
				this.running = Number.isInteger(data?.running) ? data.running : 0;
				this.jobsLoaded = true;
			} catch {
				// keep the last list; the next poll tries again
			}
		},

		show(tab) {
			this.tab = tab;
			clearInterval(this.jobsTimer);
			this.jobsTimer = null;
			if (tab === 'jobs' && this.jobsUrl) {
				this.loadJobs();
				this.jobsTimer = setInterval(() => {
					if (this.open && this.tab === 'jobs') {
						this.loadJobs();
					}
				}, JOBS_INTERVAL);
			}
		},

		toggle() {
			this.open = !this.open;
			if (!this.open) {
				clearInterval(this.jobsTimer);
				this.jobsTimer = null;
			} else if (this.tab === 'jobs') {
				this.show('jobs');
			}
		},

		close() {
			this.open = false;
			clearInterval(this.jobsTimer);
			this.jobsTimer = null;
		},

		async acknowledge(item) {
			try {
				await request(`${this.url}/${encodeURIComponent(item.id)}/acknowledge`, { method: 'POST' });
				item.acknowledged = true;
			} catch (error) {
				toast('error', error.message);
			}
		},

		async acknowledgeAll() {
			try {
				await request(`${this.url}/acknowledge`, { method: 'POST' });
				for (const item of this.items) {
					item.acknowledged = true;
				}
			} catch (error) {
				toast('error', error.message);
			}
		},

		async removeAll() {
			try {
				await request(this.url, { method: 'DELETE' });
				this.items = [];
			} catch (error) {
				toast('error', error.message);
			}
		},

		async remove(item) {
			try {
				await request(`${this.url}/${encodeURIComponent(item.id)}`, { method: 'DELETE' });
				this.items = this.items.filter((other) => other.id !== item.id);
			} catch (error) {
				toast('error', error.message);
			}
		},
	};
}
