// JSON routes of FileController and FileTransferController for one account
// (E3 spec §6.4). Errors keep the names of a 409 answer (error.conflicts),
// so the page can ask skip, overwrite or keep both.
import { csrfToken, HttpError } from '../../http.js';
import { toast } from '../../toast.js';

/** @param {Record<string, string|number|undefined|null>} params */
export function query(params) {
	const search = new URLSearchParams();
	for (const [key, value] of Object.entries(params)) {
		if (value !== undefined && value !== null) {
			search.set(key, String(value));
		}
	}
	const text = search.toString();
	return text === '' ? '' : `?${text}`;
}

/**
 * @param {string} url
 * @param {{method?: string, body?: unknown}} [options]
 */
export async function call(url, { method = 'GET', body } = {}) {
	const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
	if (method !== 'GET') {
		headers['X-CSRF-TOKEN'] = csrfToken();
	}
	if (body !== undefined) {
		headers['Content-Type'] = 'application/json';
	}
	const response = await fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'same-origin' });
	const data = await response.json().catch(() => null);
	if (!response.ok) {
		const error = new HttpError(
			response.status,
			data?.error?.code ?? 'E_HTTP',
			data?.error?.message ?? data?.message ?? `HTTP ${response.status}`,
			data?.errors ?? {},
		);
		error.conflicts = data?.error?.conflicts ?? [];
		throw error;
	}
	return data;
}

/**
 * @param {string} base  /files/{user}/api
 * @param {(key: string, ...args: (string|number)[]) => string} t
 */
export function createApi(base, t) {
	const get = (action, params = {}) => call(`${base}/${action}${query(params)}`);
	const post = (action, body = {}) => call(`${base}/${action}`, { method: 'POST', body });

	return {
		base,
		list: (path, { offset = 0, limit = 2000, sort = 'name', order = 'asc', hidden = true } = {}) =>
			get('list', { path, offset, limit, sort, order, hidden: hidden ? 'yes' : 'no' }),
		info: (path) => get('info', { path }),
		read: (path) => get('read', { path }),
		size: (path) => get('size', { path }),
		search: (path, q) => get('search', { path, q }),
		save: (path, content, hash) => call(`${base}/save`, { method: 'PUT', body: { path, content, hash } }),
		mkdir: (path, name) => post('mkdir', { path, name }),
		touch: (path, name) => post('touch', { path, name }),
		rename: (path, name) => post('rename', { path, name }),
		copy: (paths, dest, onConflict) => post('copy', { paths, dest, onConflict }),
		move: (paths, dest, onConflict) => post('move', { paths, dest, onConflict }),
		remove: (paths) => post('delete', { paths }),
		chmod: (paths, mode, recursive = false, filesMode = null) => post('chmod', { paths, mode, recursive, filesMode }),
		compress: (paths, name) => post('compress', { paths, name }),
		extract: (path, dest) => post('extract', { path, dest }),
		download: (paths) => post('download', { paths }),
		/** An error toast; TITLE names what failed ("Could not move") */
		fail(error, title = undefined) {
			toast('error', title ?? t('Something went wrong'), error instanceof Error ? error.message : String(error));
		},
		toast,
	};
}

/**
 * Snapshot mode (E7 batch 1 spec §10.3): folders of a restic snapshot and
 * "Restore" through the backup queue; every other call is refused, so the
 * read-only page cannot change anything by accident.
 *
 * @param {string} base  /files/{user}/api
 * @param {string} snapshot  restic snapshot ID
 * @param {(key: string, ...args: (string|number)[]) => string} t
 */
export function createSnapshotApi(base, snapshot, t) {
	const root = `${base}/snapshots/${encodeURIComponent(snapshot)}`;
	const refuse = () => Promise.reject(new Error(t('Read-only: changes are disabled.')));
	const api = createApi(base, t);
	for (const key of Object.keys(api)) {
		if (typeof api[key] === 'function' && !['fail', 'toast'].includes(key)) {
			api[key] = refuse;
		}
	}
	api.list = (path) => call(`${root}/list${query({ path })}`);
	api.restore = (path) => call(`${root}/restore`, { method: 'POST', body: { path } });

	return api;
}

/** First message of each field of a 422 answer */
export function fieldErrors(error) {
	const errors = {};
	for (const [field, messages] of Object.entries(error?.errors ?? {})) {
		errors[field] = Array.isArray(messages) ? messages[0] : String(messages);
	}
	return errors;
}
