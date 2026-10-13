// Rules of the Docker apps pages that need no Vue (E4 spec §5.3, §7): the
// same checks as func/docker.sh, so the forms refuse what the commands
// would.

import { isOnlySecretReference } from '../shared/secret-reference.js';

/** App instance name (dockerapp_name_valid) */
export function isAppName(value) {
	return /^[a-z][a-z0-9-]{0,30}[a-z0-9]$/.test(value);
}

/** The catalog slug, or slug-2, slug-3 … when the user has that name */
export function defaultAppName(slug, taken = []) {
	if (!taken.includes(slug)) {
		return slug;
	}
	for (let n = 2; n < 100; n++) {
		const name = `${slug.slice(0, 29)}-${n}`;
		if (!taken.includes(name)) {
			return name;
		}
	}
	return '';
}

/** Characters a variable value may not contain (docker_var_check) */
const FORBIDDEN = /['"$`\\\r\n\0]/;

/**
 * Problem of VALUE for a manifest variable, as a message key, or null.
 * Empty values are left to the defaults and the required check.
 *
 * @param {{name: string, type: string, options?: string[], pattern?: string, required?: boolean, default?: string}} variable
 * @param {string} value
 */
export function varError(variable, value) {
	if (value.length > 1024 || FORBIDDEN.test(value)) {
		return 'At most 1024 characters, without quotes, $, ` or \\.';
	}
	if (value === '') {
		return null;
	}
	switch (variable.type) {
		case 'int':
			if (!/^-?\d{1,18}$/.test(value)) {
				return 'A whole number.';
			}
			break;
		case 'bool':
			if (value !== 'true' && value !== 'false') {
				return 'true or false.';
			}
			break;
		case 'enum':
			if (!(variable.options ?? []).includes(value)) {
				return 'Choose one of the values.';
			}
			break;
		case 'password':
			if (value.length < 8) {
				return 'At least 8 characters.';
			}
			break;
		case 'email':
			if (!/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(value)) {
				return 'An email address.';
			}
			break;
		case 'url':
			if (!/^https?:\/\/\S+$/.test(value)) {
				return 'An http or https URL.';
			}
			break;
	}
	if (variable.pattern) {
		try {
			if (!new RegExp(variable.pattern).test(value)) {
				return 'The value has the wrong format.';
			}
		} catch {
			// a pattern JavaScript cannot read: the command checks it
		}
	}
	return null;
}

/**
 * Field errors of an install form: {NAME: message key}. A required
 * variable without a value needs one unless it has a default (a random one
 * included).
 *
 * @param {object[]} variables  of the catalog app
 * @param {Record<string, string>} values
 */
export function formErrors(variables, values) {
	const errors = {};
	for (const variable of variables) {
		const value = values[variable.name] ?? '';
		const problem = varError(variable, value);
		if (problem) {
			errors[variable.name] = problem;
		} else if (value === '' && variable.required && !variable.default && !variable.random) {
			errors[variable.name] = 'This value is required.';
		}
	}
	return errors;
}

/**
 * The value a settings field starts with: the stored value, but for a secret
 * variable only a value that is one reference to the secret store (E38),
 * never a secret, also not one that mixes text and a reference.
 */
export function shownValue(variable) {
	if (!variable.secret) {
		return variable.value;
	}
	return isOnlySecretReference(variable.value ?? '') ? variable.value : '';
}

const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

/** A random password of LENGTH letters and digits */
export function generateSecret(length = 24, random = globalThis.crypto) {
	const bytes = new Uint32Array(length);
	random.getRandomValues(bytes);
	return Array.from(bytes, (n) => ALPHABET[n % ALPHABET.length]).join('');
}

/**
 * The store link: only when the engine runs, the package includes Docker
 * apps and the account may install them.
 */
export function canOpenStore(abilities) {
	return Boolean(abilities?.engine && abilities.available && abilities.manage);
}

/** Badge tone and label key of an app status */
export function appStatus(app) {
	if (app.suspended) {
		return { tone: 'warn', label: 'suspended' };
	}
	switch (app.status) {
		case 'running':
			return { tone: 'ok', label: 'running' };
		case 'installing':
		case 'updating':
			return { tone: 'info', label: app.status };
		case 'stopped':
			return { tone: null, label: 'stopped' };
		case 'degraded':
		case 'restarting':
			return { tone: 'warn', label: app.status };
		default:
			return { tone: 'danger', label: app.status || 'missing' };
	}
}

/** Catalog apps matching QUERY (name, slug, summary) and CATEGORY ("" for all) */
export function filterCatalog(apps, query = '', category = '') {
	const words = query.toLowerCase().split(/\s+/).filter(Boolean);
	return apps.filter((app) => {
		if (category !== '' && app.category !== category) {
			return false;
		}
		const text = `${app.slug} ${app.name} ${app.summary}`.toLowerCase();
		return words.every((word) => text.includes(word));
	});
}

/** Categories that have apps, in catalog order of first use */
export function categoriesOf(apps) {
	return [...new Set(apps.map((app) => app.category))];
}

/**
 * Lines shown by the log view: a read without a cursor replaces them, a
 * read after the cursor is appended; at most MAX lines are kept.
 *
 * @param {{lines: object[], next: string}} view
 * @param {{lines: object[], next: string}} answer
 * @param {string|null} since  cursor of the request, null for "last lines"
 */
export function mergeLogs(view, answer, since, max = 2000) {
	const lines = since === null ? answer.lines : [...view.lines, ...answer.lines];
	return { lines: lines.slice(-max), next: answer.next || view.next || '' };
}

/** Task output: a read from 0 replaces it, a later read is appended */
export function mergeTask(previous, answer, offset) {
	const log = offset === 0 ? answer.log : `${previous.log ?? ''}${answer.log ?? ''}`;
	return { ...answer, log };
}

/** MB as "512 MB" or "1.5 GB" */
export function formatMb(mb) {
	const value = Number(mb) || 0;
	return value >= 1024 ? `${(value / 1024).toFixed(value % 1024 === 0 ? 0 : 1)} GB` : `${value} MB`;
}
