// localStorage that may be missing or throw (private windows, blocked site
// data): reads fall back to null, writes are dropped.

/** @param {string} key */
export function readStore(key) {
	try {
		return globalThis.localStorage?.getItem(key) ?? null;
	} catch {
		return null;
	}
}

/**
 * @param {string} key
 * @param {string} value
 */
export function writeStore(key, value) {
	try {
		globalThis.localStorage?.setItem(key, value);
	} catch {
		// storage unavailable
	}
}
