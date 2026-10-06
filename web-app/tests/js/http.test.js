import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { csrfToken, HttpError, request } from '../../resources/js/http.js';
import { jsonResponse } from './helpers.js';

let fetchMock;

beforeEach(() => {
	document.head.innerHTML = '<meta name="csrf-token" content="token-abc">';
	fetchMock = vi.fn(async () => jsonResponse({ ok: true }));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	vi.unstubAllGlobals();
	document.head.innerHTML = '';
});

describe('csrfToken', () => {
	it('reads the meta tag', () => {
		expect(csrfToken()).toBe('token-abc');
		document.head.innerHTML = '';
		expect(csrfToken()).toBe('');
	});
});

describe('request', () => {
	it('sends GET requests as JSON without a CSRF header', async () => {
		expect(await request('/_palette?q=ab')).toEqual({ ok: true });
		const [url, options] = fetchMock.mock.calls[0];
		expect(url).toBe('/_palette?q=ab');
		expect(options.method).toBe('GET');
		expect(options.credentials).toBe('same-origin');
		expect(options.headers).toEqual({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' });
		expect(options.body).toBeUndefined();
	});

	it('sends other methods with the CSRF token and a JSON body', async () => {
		const controller = new AbortController();
		await request('/x', { method: 'POST', body: { a: 1 }, signal: controller.signal });
		const [, options] = fetchMock.mock.calls[0];
		expect(options.method).toBe('POST');
		expect(options.headers['X-CSRF-TOKEN']).toBe('token-abc');
		expect(options.headers['Content-Type']).toBe('application/json');
		expect(options.body).toBe('{"a":1}');
		expect(options.signal).toBe(controller.signal);

		await request('/x', { method: 'DELETE' });
		expect(fetchMock.mock.calls[1][1].headers['Content-Type']).toBeUndefined();
	});

	it('throws HttpError with the code and message of an error response', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ error: { code: 'E_LIMIT', message: 'Limit reached' } }, 429));
		const error = await request('/x').catch((e) => e);
		expect(error).toBeInstanceOf(HttpError);
		expect(error).toMatchObject({ status: 429, code: 'E_LIMIT', message: 'Limit reached' });
	});

	it('keeps the message and the field errors of a validation answer', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ message: 'The start field format is invalid.', errors: { start: ['bad'] } }, 422));
		const error = await request('/x', { method: 'POST' }).catch((e) => e);
		expect(error).toMatchObject({ status: 422, code: 'E_HTTP', message: 'The start field format is invalid.', errors: { start: ['bad'] } });
	});

	it('falls back to the status when the error is not JSON', async () => {
		fetchMock.mockResolvedValue({ ok: false, status: 502, json: async () => Promise.reject(new SyntaxError('html')) });
		const error = await request('/x').catch((e) => e);
		expect(error).toMatchObject({ status: 502, code: 'E_HTTP', message: 'HTTP 502' });
	});

	it('returns null for an empty successful body', async () => {
		fetchMock.mockResolvedValue({ ok: true, status: 204, json: async () => Promise.reject(new SyntaxError('empty')) });
		expect(await request('/x', { method: 'POST' })).toBeNull();
	});
});
