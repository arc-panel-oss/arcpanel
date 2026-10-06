// apNotifications: the notification tray. Loads GET url (JSON), polls every
// 60 s while the tab is visible, acknowledges with POST url/{id}/acknowledge
// and deletes with DELETE url/{id}; POST url/acknowledge and DELETE url act on
// every notification.
import { request } from '../http.js';
import { toast } from '../toast.js';

export default function apNotifications({ url = '', interval = 60000 } = {}) {
	return {
		url,
		open: false,
		items: [],
		loaded: false,
		timer: null,

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
		},

		toggle() {
			this.open = !this.open;
		},

		close() {
			this.open = false;
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
