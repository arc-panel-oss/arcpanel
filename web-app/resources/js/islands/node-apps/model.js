// Rules of the Node apps pages that need no Vue (E14 spec §5, §7): the same
// checks as func/nodeapp.sh, so the forms refuse what the commands would.

/** pm2 process name */
export function isAppName(value) {
	return /^[a-z0-9][a-z0-9._-]{0,39}$/.test(value);
}

/** A path relative to the domain directory without "..", or "." */
export function isRelativePath(value) {
	if (value === '.') {
		return true;
	}
	if (value.length > 200 || !/^[A-Za-z0-9_][A-Za-z0-9._/@+-]*$/.test(value) || value.includes('//')) {
		return false;
	}
	return !`/${value}/`.includes('/../') && !`/${value}/`.includes('/./');
}

/** A script of package.json */
export function isScript(value) {
	return /^[A-Za-z0-9][A-Za-z0-9:._-]{0,63}$/.test(value);
}

/** START of an app: a file relative to its folder, or npm:<script> */
export function isStart(value) {
	if (value.startsWith('npm:')) {
		return isScript(value.slice(4));
	}
	return value !== '.' && isRelativePath(value);
}

/** {kind: 'file'|'script', value} of a START value, and back */
export function parseStart(start) {
	return start.startsWith('npm:') ? { kind: 'script', value: start.slice(4) } : { kind: 'file', value: start };
}

export function composeStart({ kind, value }) {
	return kind === 'script' ? `npm:${value}` : value;
}

export const RESERVED_ENV = ['PORT', 'HOST', 'NODE_ENV', 'PATH'];

/**
 * Problems of an environment table: a key per row, unique, not set by the
 * panel, values on one line. Returns {index: message key} for the rows.
 *
 * @param {{key: string, value: string}[]} rows
 */
export function envErrors(rows) {
	const errors = {};
	const seen = new Set();
	rows.forEach((row, index) => {
		const key = row.key.trim();
		if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) {
			errors[index] = 'Letters, digits and _, not starting with a digit.';
		} else if (RESERVED_ENV.includes(key)) {
			errors[index] = '%s is set by the panel.';
		} else if (seen.has(key)) {
			errors[index] = 'This key is set twice.';
		} else if (/[\r\n\0]/.test(row.value)) {
			errors[index] = 'A value fits on one line.';
		}
		seen.add(key);
	});
	return errors;
}

export { isSecretReference, secretReference } from '../shared/secret-reference.js';

/** Whether two environment tables differ (order counts: it is the file) */
export function envChanged(before, after) {
	if (before.length !== after.length) {
		return true;
	}
	return before.some((row, index) => row.key !== after[index].key.trim() || row.value !== after[index].value);
}

/** Badge tone and label key of an app status */
export function appStatus(app) {
	if (app.suspended) {
		return { tone: 'warn', label: 'Suspended' };
	}
	switch (app.status) {
		case 'online':
			return { tone: 'ok', label: 'Online' };
		case 'launching':
			return { tone: 'info', label: 'Starting' };
		case 'errored':
			return { tone: 'danger', label: 'Errored' };
		case 'stopped':
			return { tone: null, label: 'Stopped' };
		default:
			return { tone: 'danger', label: 'Not running' };
	}
}

/** Uptime in seconds as "3d 4h", "2h 5m", "4m" or "12s" */
export function formatUptime(seconds) {
	const s = Math.max(0, Math.floor(Number(seconds) || 0));
	const days = Math.floor(s / 86400);
	const hours = Math.floor((s % 86400) / 3600);
	const minutes = Math.floor((s % 3600) / 60);
	if (days > 0) {
		return `${days}d ${hours}h`;
	}
	if (hours > 0) {
		return `${hours}h ${minutes}m`;
	}
	if (minutes > 0) {
		return `${minutes}m`;
	}
	return `${s}s`;
}

/**
 * Lines shown by the log view: a read from the start replaces them, a read
 * after an offset is appended; at most MAX lines are kept. An answer whose
 * offset is smaller than the one asked for means the log was rotated.
 *
 * @param {{lines: string[], offset: number|null}} view
 * @param {{lines: string[], offset: number}} answer
 * @param {number|null} asked  offset of the request, null for "last lines"
 */
export function mergeLog(view, answer, asked, max = 2000) {
	const lines = asked === null || answer.offset < asked ? answer.lines : [...view.lines, ...answer.lines];
	return { lines: lines.slice(-max), offset: answer.offset };
}

/** What the wizard proposes when package.json says which framework it is */
export function templateFor(proposal, templates) {
	const names = templates.map((template) => template.name);
	if (proposal?.template && names.includes(proposal.template)) {
		return proposal.template;
	}
	return names.includes('nodejs-proxy') ? 'nodejs-proxy' : (names[0] ?? '');
}

/** A process name proposed for a domain, as nodeapp_default_name */
export function defaultName(domain) {
	return domain
		.toLowerCase()
		.replace(/[^a-z0-9._-]/g, '-')
		.slice(0, 40)
		.replace(/-+$/, '');
}
