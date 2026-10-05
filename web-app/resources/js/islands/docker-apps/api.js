// JSON routes of DockerAppController for one account, or of
// DockerEngineController (E4 spec §6.3).
import { request } from '../../http.js';
import { toast } from '../../toast.js';

/**
 * @param {string} base  /docker/{user}/api or /server/docker/api
 * @param {(key: string, ...args: (string|number)[]) => string} t
 */
export function createApi(base, t) {
	return {
		/** @param {string} path  "/apps", "/apps/{name}/…", "/catalog" … */
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

/** URL of a page from its template: __NAME__ or __SLUG__ replaced */
export function pageUrl(template, value) {
	return template.replace(/__(NAME|SLUG)__/, encodeURIComponent(value));
}

/** Path of an app in the API */
export function appPath(name, rest = '') {
	return `/apps/${encodeURIComponent(name)}${rest}`;
}
