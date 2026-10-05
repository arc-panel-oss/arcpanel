// fetch() wrapper for the JSON routes of the panel app: same-origin cookies,
// the CSRF token of the page, and errors as {"error": {"code", "message"}}.

export class HttpError extends Error {
	/**
	 * @param {number} status
	 * @param {string} code
	 * @param {string} message
	 * @param {Record<string, string[]>} [errors]  field errors of a 422 answer
	 */
	constructor(status, code, message, errors = {}) {
		super(message);
		this.status = status;
		this.code = code;
		this.errors = errors;
	}
}

export function csrfToken() {
	return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * @param {string} url
 * @param {{method?: string, body?: unknown, signal?: AbortSignal}} [options]
 * @returns {Promise<any>}
 */
export async function request(url, { method = 'GET', body, signal } = {}) {
	const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
	if (method !== 'GET') {
		headers['X-CSRF-TOKEN'] = csrfToken();
	}
	if (body !== undefined) {
		headers['Content-Type'] = 'application/json';
	}

	const response = await fetch(url, {
		method,
		headers,
		body: body === undefined ? undefined : JSON.stringify(body),
		credentials: 'same-origin',
		signal,
	});

	const data = await response.json().catch(() => null);
	if (!response.ok) {
		throw new HttpError(
			response.status,
			data?.error?.code ?? 'E_HTTP',
			data?.error?.message ?? data?.message ?? `HTTP ${response.status}`,
			data?.errors ?? {},
		);
	}
	return data;
}
