// JSON routes of NodeAppController for one account (E14 spec §6.3).
import { request } from '../../http.js';
import { toast } from '../../toast.js';

/**
 * @param {string} base  /node/{user}/api/apps
 * @param {(key: string, ...args: (string|number)[]) => string} t
 */
export function createApi(base, t) {
	return {
		/** @param {string} path  "" for the list, "/{domain}/…" */
		call(method, path = '', body = undefined) {
			return request(`${base}${path}`, { method, body });
		},
		fail(error) {
			toast('error', t('Something went wrong'), error instanceof Error ? error.message : String(error));
		},
		saved(message = t('Changes have been saved.')) {
			toast('ok', message);
		},
		toast,
	};
}

/** First message of each field of a 422 answer */
export function fieldErrors(error) {
	const errors = {};
	for (const [field, messages] of Object.entries(error?.errors ?? {})) {
		errors[field] = Array.isArray(messages) ? messages[0] : String(messages);
	}
	return errors;
}

/** URL of an app page from the template with __DOMAIN__ */
export function appUrl(template, domain) {
	return template.replace('__DOMAIN__', encodeURIComponent(domain));
}
