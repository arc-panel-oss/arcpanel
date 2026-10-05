// Upload queue of the file manager (E3 spec §7.4): files one after the
// other, each in chunks with at most 3 in flight; a failed chunk is tried
// again with back-off, then the file stops and can resume. The server
// remembers received chunks (UploadSessions), so dropping the same file
// again after a reload resumes as well. No Vue here: the panel renders the
// items and calls the methods.

export const STATES = ['queued', 'uploading', 'conflict', 'done', 'error', 'cancelled'];

let nextId = 1;

/**
 * @param {object} options
 * @param {string} options.base  /files/{user}/api
 * @param {() => string} options.csrf
 * @param {number} options.maxBytes  FILE_MANAGER_UPLOAD_MAX
 * @param {(item: object) => void} [options.onChange]  after every change of an item
 * @param {(item: object) => void} [options.onDone]  after a file landed
 * @param {typeof fetch} [options.fetch]
 * @param {(ms: number) => Promise<void>} [options.sleep]
 * @param {number} [options.concurrency]
 * @param {number} [options.retries]
 */
export function createUploader(options) {
	const fetchImpl = options.fetch ?? globalThis.fetch.bind(globalThis);
	const sleep = options.sleep ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
	const concurrency = options.concurrency ?? 3;
	const retries = options.retries ?? 3;
	const onChange = options.onChange ?? (() => {});
	const onDone = options.onDone ?? (() => {});

	/** @type {object[]} */
	const items = [];
	let running = false;
	let conflictChoice = null;

	function changed(item) {
		onChange(item);
	}

	async function call(method, url, { json, body, signal } = {}) {
		const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': options.csrf() };
		if (json !== undefined) {
			headers['Content-Type'] = 'application/json';
		} else if (body !== undefined) {
			headers['Content-Type'] = 'application/octet-stream';
		}
		const response = await fetchImpl(url, {
			method,
			headers,
			body: json !== undefined ? JSON.stringify(json) : body,
			credentials: 'same-origin',
			signal,
		});
		const data = await response.json().catch(() => null);
		if (!response.ok) {
			const error = new Error(data?.error?.message ?? data?.message ?? `HTTP ${response.status}`);
			error.status = response.status;
			error.code = data?.error?.code ?? 'E_HTTP';
			error.conflicts = data?.error?.conflicts ?? [];
			throw error;
		}
		return data;
	}

	/**
	 * Queue files for the folder `dest`.
	 *
	 * @param {{file: File|Blob & {name: string, size: number, lastModified?: number}, relativePath?: string}[]} files
	 * @param {string} dest
	 */
	function add(files, dest) {
		const added = [];
		for (const { file, relativePath = '' } of files) {
			const item = {
				id: nextId++,
				file,
				name: file.name,
				relativePath,
				size: file.size,
				dest,
				state: 'queued',
				sent: 0,
				error: '',
				token: null,
				controllers: new Set(),
			};
			if (file.size > options.maxBytes) {
				item.state = 'error';
				item.error = 'too-large';
			}
			items.push(item);
			added.push(item);
			changed(item);
		}
		run();
		return added;
	}

	async function run() {
		if (running) {
			return;
		}
		running = true;
		try {
			for (;;) {
				const item = items.find((candidate) => candidate.state === 'queued');
				if (!item) {
					break;
				}
				await upload(item);
			}
		} finally {
			running = false;
		}
	}

	async function upload(item) {
		item.state = 'uploading';
		item.error = '';
		changed(item);
		try {
			const session = await call('POST', `${options.base}/upload`, {
				json: {
					path: item.dest,
					name: item.name,
					relativePath: item.relativePath,
					size: item.size,
					lastModified: item.file.lastModified ?? 0,
				},
			});
			item.token = session.token;
			const chunkSize = session.chunkSize;
			const count = Math.max(1, Math.ceil(item.size / chunkSize));
			const received = new Set(session.received ?? []);
			item.sent = Math.min(item.size, [...received].reduce((sum, index) => sum + chunkLength(index, chunkSize, item.size, count), 0));
			changed(item);

			const pending = [];
			for (let index = 0; index < count; index++) {
				if (!received.has(index)) {
					pending.push(index);
				}
			}
			await pool(pending, concurrency, async (index) => {
				await sendChunk(item, index, chunkSize, count);
			});
			if (item.state !== 'uploading') {
				return;
			}
			await finish(item, conflictChoice);
		} catch (error) {
			if (item.state === 'cancelled') {
				return;
			}
			item.state = 'error';
			item.error = error instanceof Error ? error.message : String(error);
			item.code = error?.code ?? '';
			changed(item);
		}
	}

	async function sendChunk(item, index, chunkSize, count) {
		const start = index * chunkSize;
		const blob = item.file.slice(start, start + chunkLength(index, chunkSize, item.size, count));
		for (let attempt = 0; ; attempt++) {
			if (item.state !== 'uploading') {
				return;
			}
			const controller = new AbortController();
			item.controllers.add(controller);
			try {
				await call('PUT', `${options.base}/upload/${item.token}/${index}`, { body: blob, signal: controller.signal });
				item.sent = Math.min(item.size, item.sent + blob.size);
				changed(item);
				return;
			} catch (error) {
				// A refused chunk (4xx except a timeout) will not get better
				const final = error?.status >= 400 && error.status < 500 && error.status !== 408 && error.status !== 429;
				if (item.state !== 'uploading' || final || attempt + 1 >= retries) {
					throw error;
				}
				await sleep(1000 * 2 ** attempt);
			} finally {
				item.controllers.delete(controller);
			}
		}
	}

	async function finish(item, onConflict) {
		try {
			const result = await call('POST', `${options.base}/upload/${item.token}/finish`, { json: onConflict ? { onConflict } : {} });
			item.state = 'done';
			item.sent = item.size;
			item.landed = result?.name ?? item.name;
			changed(item);
			onDone(item);
		} catch (error) {
			if (error.status === 409 && error.code === 'E_EXISTS') {
				item.state = 'conflict';
				changed(item);
				return;
			}
			throw error;
		}
	}

	/**
	 * Answer the conflict of an item: skip, overwrite or rename. With
	 * `forAll`, later conflicts get the same answer without asking.
	 */
	async function resolve(item, choice, forAll = false) {
		if (item.state !== 'conflict') {
			return;
		}
		if (forAll) {
			conflictChoice = choice;
		}
		item.state = 'uploading';
		changed(item);
		try {
			await finish(item, choice);
		} catch (error) {
			item.state = 'error';
			item.error = error instanceof Error ? error.message : String(error);
			changed(item);
		}
		if (forAll) {
			for (const other of items.filter((candidate) => candidate.state === 'conflict' && candidate !== item)) {
				await resolve(other, choice);
			}
		}
	}

	async function cancel(item) {
		if (item.state === 'done' || item.state === 'cancelled') {
			return;
		}
		const hadSession = item.token !== null;
		item.state = 'cancelled';
		for (const controller of item.controllers) {
			controller.abort();
		}
		changed(item);
		if (hadSession) {
			try {
				await call('DELETE', `${options.base}/upload/${item.token}`);
			} catch {
				// the session expires by itself
			}
		}
	}

	function retry(item) {
		if (item.state === 'error' && item.error !== 'too-large') {
			item.state = 'queued';
			item.error = '';
			changed(item);
			run();
		}
	}

	function cancelAll() {
		return Promise.all(items.map((item) => cancel(item)));
	}

	function clearFinished() {
		for (let index = items.length - 1; index >= 0; index--) {
			if (['done', 'cancelled'].includes(items[index].state)) {
				items.splice(index, 1);
			}
		}
	}

	/** Bytes sent and total of the unfinished and finished items */
	function totals() {
		const active = items.filter((item) => item.state !== 'cancelled' && item.error !== 'too-large');
		return { sent: active.reduce((sum, item) => sum + item.sent, 0), size: active.reduce((sum, item) => sum + item.size, 0) };
	}

	return { items, add, resolve, cancel, cancelAll, retry, clearFinished, totals, get busy() { return running; } };
}

function chunkLength(index, chunkSize, size, count) {
	return index < count - 1 ? chunkSize : size - chunkSize * (count - 1);
}

/** Run `task` for every value with at most `limit` running; the first error stops the rest */
async function pool(values, limit, task) {
	let next = 0;
	let failure = null;
	const workers = Array.from({ length: Math.min(limit, values.length) }, async () => {
		while (failure === null && next < values.length) {
			const value = values[next++];
			try {
				await task(value);
			} catch (error) {
				failure ??= error;
			}
		}
	});
	await Promise.all(workers);
	if (failure !== null) {
		throw failure;
	}
}

/**
 * Files of a drop: plain files, and folders read recursively through
 * webkitGetAsEntry, each with its path relative to the drop.
 *
 * @param {DataTransfer} transfer
 * @returns {Promise<{file: File, relativePath: string}[]>}
 */
export async function filesFromDrop(transfer) {
	const entries = [...(transfer.items ?? [])].map((item) => item.webkitGetAsEntry?.()).filter(Boolean);
	if (entries.length === 0) {
		return [...(transfer.files ?? [])].map((file) => ({ file, relativePath: '' }));
	}
	const out = [];
	const walk = async (entry, prefix) => {
		if (entry.isFile) {
			const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
			out.push({ file, relativePath: prefix === '' ? '' : `${prefix}/${file.name}` });
		} else if (entry.isDirectory) {
			const reader = entry.createReader();
			const folder = prefix === '' ? entry.name : `${prefix}/${entry.name}`;
			for (;;) {
				const batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
				if (batch.length === 0) {
					break;
				}
				for (const child of batch) {
					await walk(child, folder);
				}
			}
		}
	};
	for (const entry of entries) {
		await walk(entry, '');
	}
	return out;
}

/** Files of an <input type="file" webkitdirectory>: relative paths from webkitRelativePath */
export function filesFromInput(fileList) {
	return [...fileList].map((file) => ({ file, relativePath: file.webkitRelativePath ?? '' }));
}
