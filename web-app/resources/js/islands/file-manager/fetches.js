// Upload from URL (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md
// §3.2, §6.5): the account's fetches, kept on the server and polled. Every
// second while the dialog is open and one waits or runs, every 10 seconds
// while it is open and idle, every 5 seconds while it is closed and one is
// active (the badge of the toolbar button), not at all otherwise.

export const FAST = 1000;
export const IDLE = 10000;
export const BADGE = 5000;

const FINISHED = ['done', 'skipped', 'failed', 'cancelled'];

export function isFinished(item) {
	return FINISHED.includes(item.status);
}

/**
 * @param {{
 *   api: {fetches: () => Promise<any>, startFetch: (body: object) => Promise<any>, cancelFetch: (id: string) => Promise<any>},
 *   onChange?: () => void,
 *   onDone?: (item: object) => void,
 *   now?: () => number,
 *   timers?: {set: typeof setTimeout, clear: typeof clearTimeout},
 * }} options
 */
export function createFetches({ api, onChange = () => {}, onDone = () => {}, now = () => Date.now(), timers = { set: (fn, ms) => setTimeout(fn, ms), clear: (id) => clearTimeout(id) } }) {
	const state = {
		items: [],
		active: 0,
		loaded: false,
		open: false,
		/** id => bytes per second */
		speeds: {},
	};
	/** id => {bytes, at} of the previous answer */
	const samples = {};
	let timer = null;
	let stopped = false;

	function apply(data) {
		const before = new Map(state.items.map((item) => [item.id, item]));
		const at = now();
		state.items = Array.isArray(data?.fetches) ? data.fetches : [];
		state.active = Number(data?.active ?? state.items.filter((item) => !isFinished(item)).length);
		state.loaded = true;
		const speeds = {};
		for (const item of state.items) {
			const previous = before.get(item.id);
			if (item.status === 'running') {
				const sample = samples[item.id];
				if (sample && at > sample.at && item.bytes >= sample.bytes) {
					speeds[item.id] = Math.round(((item.bytes - sample.bytes) * 1000) / (at - sample.at));
				}
				samples[item.id] = { bytes: item.bytes, at };
			} else {
				delete samples[item.id];
			}
			if (previous && !isFinished(previous) && item.status === 'done') {
				onDone(item);
			}
		}
		state.speeds = speeds;
		onChange();
	}

	function delay() {
		if (state.open) {
			return state.active > 0 ? FAST : IDLE;
		}
		return state.active > 0 ? BADGE : null;
	}

	function schedule() {
		timers.clear(timer);
		timer = null;
		const wait = delay();
		if (wait === null || stopped) {
			return;
		}
		timer = timers.set(async () => {
			timer = null;
			await refresh().catch(() => {});
			schedule();
		}, wait);
	}

	async function refresh() {
		apply(await api.fetches());
	}

	return {
		state,
		refresh,
		/** The dialog opened or closed: poll at its pace */
		async setOpen(open) {
			state.open = open;
			if (open) {
				await refresh().catch(() => {});
			}
			schedule();
		},
		/** Adds a fetch; the answer is the new list */
		async start(body) {
			const data = await api.startFetch(body);
			apply(data);
			schedule();
			return data.id;
		},
		async cancel(id) {
			apply(await api.cancelFetch(id));
			schedule();
		},
		/** Removes every finished fetch from the list */
		async clearFinished() {
			for (const item of state.items.filter(isFinished)) {
				apply(await api.cancelFetch(item.id));
			}
			schedule();
		},
		stop() {
			stopped = true;
			timers.clear(timer);
			timer = null;
		},
	};
}

/**
 * The request body of the dialog's form in MODE (simple, curl, custom): only
 * the fields of that mode, credentials only of the chosen authentication,
 * empty header rows left out, private addresses only for administrators.
 *
 * @param {string} mode
 * @param {{url: string, command: string, headers: {name: string, value: string}[], auth: {type: string, username: string, password: string, token: string}, name: string, onConflict: string, allowPrivate: boolean}} form
 * @param {{path: string, canPrivate: boolean}} context
 */
export function requestBody(mode, form, { path, canPrivate }) {
	const common = { mode, path, name: form.name.trim(), onConflict: form.onConflict, allowPrivate: Boolean(canPrivate && form.allowPrivate) };
	if (mode === 'curl') {
		return { ...common, command: form.command };
	}
	if (mode === 'custom') {
		const auth = { type: form.auth.type };
		if (form.auth.type === 'basic') {
			auth.username = form.auth.username;
			auth.password = form.auth.password;
		} else if (form.auth.type === 'bearer') {
			auth.token = form.auth.token;
		}
		const headers = form.headers.filter((h) => h.name.trim() !== '' || h.value.trim() !== '').map((h) => ({ name: h.name.trim(), value: h.value }));
		return { ...common, url: form.url.trim(), headers, auth };
	}
	return { ...common, url: form.url.trim() };
}

/**
 * The state line of a fetch.
 *
 * @param {object} item
 * @param {(key: string, ...args: (string|number)[]) => string} t
 * @param {(bytes: number) => string} size
 * @param {number} [speed] bytes per second
 */
export function describe(item, t, size, speed = undefined) {
	switch (item.status) {
		case 'queued':
			return t('Waiting');
		case 'running': {
			const received = item.total ? `${size(item.bytes)} / ${size(item.total)}` : size(item.bytes);
			return speed ? `${received} · ${size(speed)}/s` : received;
		}
		case 'done':
			return `${t('Done')} · ${size(item.bytes)}`;
		case 'skipped':
			return t('Skipped: a file with this name exists');
		case 'cancelled':
			return t('Cancelled');
		default:
			return errorText(item, t);
	}
}

/** The reason of a failed fetch (spec §7) */
export function errorText(item, t) {
	switch (item.error) {
		case 'blocked':
			return t('Blocked: private and local addresses are not allowed');
		case 'bad-url':
			return t('Not a valid http or https address');
		case 'dns':
			return t('The host name could not be found');
		case 'http':
			return t('The server answered %d', item.http ?? 0);
		case 'redirects':
			return t('Too many redirects');
		case 'too-large':
			return t('Larger than the size limit');
		case 'disk':
			return t('Not enough disk space or quota');
		case 'timeout':
			return t('Timed out');
		case 'exists':
			return t('A folder with this name exists, or the folder is not writable');
		case 'interrupted':
			return t('Interrupted');
		default:
			return t('Could not connect');
	}
}

/** Badge tone of a state */
export function tone(item) {
	return { done: 'ok', failed: 'danger', skipped: 'warn', running: 'info', queued: null, cancelled: null }[item.status] ?? null;
}

/** Chip label of a state */
export function label(item, t) {
	return (
		{
			queued: t('Waiting'),
			running: t('Downloading'),
			done: t('Done'),
			skipped: t('Skipped'),
			failed: t('Failed'),
			cancelled: t('Cancelled'),
		}[item.status] ?? item.status
	);
}

/** Percent for the bar, or null while the size is unknown */
export function percent(item) {
	if (item.status === 'done') {
		return 100;
	}
	return typeof item.percent === 'number' ? item.percent : null;
}
