// apLog: a log view. The server renders the last lines; "Refresh" fetches
// GET url (JSON {lines: [...]}) and replaces them. Live follow over
// WebSocket comes with E14.
import { request } from '../http.js';

/** Severity class of a log line, as the prototype colours them. @param {string} line */
export function lineLevel(line) {
	if (/\b(error|crit|alert|emerg|fatal)\b/i.test(line) || /"\s5\d\d\s/.test(line)) {
		return 'is-error';
	}
	if (/\b(warn|warning|notice)\b/i.test(line) || /"\s4\d\d\s/.test(line)) {
		return 'is-warn';
	}
	return '';
}

export default function apLog({ url = '' } = {}) {
	return {
		url,
		loading: false,
		error: '',
		wrap: false,

		init() {
			this.$nextTick(() => this.scrollToEnd());
		},

		scrollToEnd() {
			const body = this.$refs.body;
			if (body) {
				body.scrollTop = body.scrollHeight;
			}
		},

		async refresh() {
			this.loading = true;
			this.error = '';
			try {
				const data = await request(this.url);
				const body = this.$refs.body;
				body.replaceChildren(
					...(data?.lines ?? []).map((line) => {
						const row = document.createElement('div');
						row.className = `ap-log-line ${lineLevel(line)}`.trim();
						row.textContent = line;
						return row;
					}),
				);
				this.scrollToEnd();
			} catch (error) {
				this.error = error.message;
			} finally {
				this.loading = false;
			}
		},
	};
}
