// apUpdateRelease: the update dialog of the Updates page (E33 phase 1 spec
// §8). review(release) opens the dialog and loads the pre-flight of the
// release from url (VERSION is replaced): { ready, checks: [{ check, status,
// message }] }. An attention release lists its items as checkboxes; the
// update button waits for a ready pre-flight and every box ticked. The form
// posts the version and confirm_attention; the server checks both again.
import { request } from '../http.js';

export default function apUpdateRelease({ url = '', dialog = 'update-release' } = {}) {
	return {
		url,
		release: null,
		loading: false,
		error: '',
		ready: false,
		checks: [],
		confirmed: [],

		/** @param {{version: string, attention: boolean, items: string[]}} release */
		async review(release) {
			this.release = release;
			this.checks = [];
			this.ready = false;
			this.error = '';
			this.confirmed = release.items.map(() => false);
			this.$dispatch('ap-modal', { id: dialog });
			this.loading = true;
			try {
				const data = await request(this.url.replace('VERSION', encodeURIComponent(release.version)));
				this.checks = Array.isArray(data.checks) ? data.checks : [];
				this.ready = data.ready === true;
			} catch (e) {
				this.error = e.message;
			} finally {
				this.loading = false;
			}
		},

		get confirmedCount() {
			return this.confirmed.filter(Boolean).length;
		},

		get canUpdate() {
			return !this.loading && this.ready && this.confirmedCount === this.confirmed.length;
		},
	};
}
