// PHP Manager (E41 spec 2026-10-12-001 §8): the extension toggles of a PHP
// version, the process manager fields of the profile form and the list
// editor of disable_functions.
import { request } from '../http.js';
import { toast } from '../toast.js';

/**
 * apPhpExtension: the PHP-FPM and CLI checkboxes of an installed extension.
 * A change posts {state, sapi} to the toggle route; the answer gives the new
 * state of both SAPIs, and a refusal puts the checkbox back.
 */
export function apPhpExtension({ url = '', fpm = false, cli = false } = {}) {
	return {
		fpm,
		cli,
		busy: false,
		error: '',

		async toggle(sapi) {
			const wanted = this[sapi];
			this.busy = true;
			this.error = '';
			try {
				const answer = await request(url, { method: 'POST', body: { state: wanted ? 'on' : 'off', sapi } });
				this.fpm = Boolean(answer.fpm);
				this.cli = Boolean(answer.cli);
				if (answer.message) {
					toast('ok', answer.message);
				}
			} catch (e) {
				this[sapi] = !wanted;
				this.error = e instanceof Error ? e.message : String(e);
			} finally {
				this.busy = false;
			}
		},
	};
}

/** apPhpProfile: the fields of the chosen process manager (spare servers for dynamic, idle timeout for ondemand). */
export function apPhpProfile({ pm = 'ondemand' } = {}) {
	return {
		pm,

		uses(field) {
			return { start_servers: 'dynamic', min_spare: 'dynamic', max_spare: 'dynamic', idle_timeout: 'ondemand' }[field] === this.pm;
		},
	};
}

/** apNameList: a list of function names (disable_functions) to add to and remove from. */
export function apNameList({ items = [] } = {}) {
	return {
		items: [...items],
		draft: '',

		add() {
			for (const name of this.draft.split(/[\s,]+/)) {
				if (/^[A-Za-z_][A-Za-z0-9_\\]*$/.test(name) && !this.items.includes(name)) {
					this.items.push(name);
				}
			}
			this.draft = '';
		},

		remove(name) {
			this.items = this.items.filter((item) => item !== name);
		},
	};
}
