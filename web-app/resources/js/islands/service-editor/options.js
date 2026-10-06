// Basic options of the config editors (E7 batch 3 spec §9.2): read a value
// from the config text and write a new one in place, as the legacy pages do
// (toggleAdvanced.js): the first active line of the key is changed, the rest
// of the file stays as it is, and a key that is not in the file is not added.

/** Keys shown per kind, in the legacy order. */
export const OPTION_KEYS = {
	nginx: [
		'worker_processes',
		'worker_connections',
		'send_timeout',
		'proxy_connect_timeout',
		'proxy_send_timeout',
		'proxy_read_timeout',
		'client_max_body_size',
		'gzip',
		'gzip_comp_level',
		'charset',
	],
	php: [
		'max_execution_time',
		'max_input_time',
		'memory_limit',
		'error_reporting',
		'display_errors',
		'post_max_size',
		'upload_max_filesize',
	],
	mysql: ['max_connections', 'max_user_connections', 'wait_timeout', 'interactive_timeout', 'max_allowed_packet'],
};

const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** nginx: `key value;` · ini (php, mysql): `key = value` */
function pattern(kind, key) {
	const name = escapeRegExp(key);
	if (kind === 'nginx') {
		return new RegExp(`^([ \\t]*${name}[ \\t]+)([^;#\\r\\n]*?)([ \\t]*;)`, 'm');
	}
	return new RegExp(`^([ \\t]*${name}[ \\t]*=[ \\t]*)([^;#\\r\\n]*?)([ \\t]*(?:[;#][^\\r\\n]*)?)$`, 'm');
}

/**
 * The value of a key, or null when the file has no active line for it.
 * @param {'nginx'|'php'|'mysql'} kind
 */
export function readOption(kind, text, key) {
	const match = pattern(kind, key).exec(text);
	return match ? match[2] : null;
}

/** @returns {Record<string, string>} the keys of the kind that the file has */
export function readOptions(kind, text) {
	const values = {};
	for (const key of OPTION_KEYS[kind] ?? []) {
		const value = readOption(kind, text, key);
		if (value !== null) {
			values[key] = value;
		}
	}
	return values;
}

/** A value fit for one line: no line breaks, and no ";" in nginx (it ends the directive). */
export function cleanValue(kind, value) {
	const line = String(value).replace(/[\r\n]+/g, ' ').trim();
	return kind === 'nginx' ? line.replace(/;/g, '') : line;
}

/**
 * The text with the first active line of the key set to value; unchanged
 * when the key is not in the file.
 */
export function writeOption(kind, text, key, value) {
	const clean = cleanValue(kind, value);
	return text.replace(pattern(kind, key), (_, before, _old, after) => `${before}${clean}${after}`);
}
