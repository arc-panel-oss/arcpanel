// Rules of the app processes pages that need no Vue (E42 spec §9): the same
// checks as func/appprocess.sh, so the forms refuse what the commands would.

export { formatUptime, isRelativePath, mergeLog } from '../node-apps/model.js';

/** Environment keys the runner sets (arc-app-run) */
export const RESERVED_ENV = ['PORT', 'HOST', 'PATH', 'HOME', 'USER', 'LOGNAME'];

/** Process names (appproc_name_valid) */
export function isProcessName(value) {
	return /^[a-z][a-z0-9-]{0,31}$/.test(value);
}

/** A command line: up to 2048 characters, no control characters */
export function isCommand(value) {
	// biome-ignore lint/suspicious/noControlCharactersInRegex: control characters are what this refuses
	return value.trim() !== '' && value.length <= 2048 && !/[\u0000-\u001f\u007f]/.test(value);
}

/** CPUs of a process, like 1 or 0.5 */
export function isCpus(value) {
	return /^\d{1,2}(\.\d{1,2})?$/.test(String(value)) && Number(value) > 0;
}

/** Badge tone and label key of a process status */
export function processStatus(process) {
	if (process.suspended || process.status === 'suspended') {
		return { tone: 'warn', label: 'Suspended' };
	}
	switch (process.status) {
		case 'running':
			return { tone: 'ok', label: 'Running' };
		case 'starting':
			return { tone: 'info', label: 'Starting' };
		case 'stopping':
			return { tone: 'info', label: 'Stopping' };
		case 'failed':
			return { tone: 'danger', label: 'Failed' };
		case 'stopped':
			return { tone: null, label: 'Stopped' };
		default:
			return { tone: 'danger', label: 'Not running' };
	}
}

/** Group of a process for the status filter: running, stopped, failed or suspended */
export function statusGroup(process) {
	if (process.suspended || process.status === 'suspended') {
		return 'suspended';
	}
	if (process.status === 'running' || process.status === 'starting') {
		return 'running';
	}
	return process.status === 'failed' ? 'failed' : 'stopped';
}

/** The processes of DOMAIN ('' for all) in the status group STATUS ('' for all) */
export function filterProcesses(processes, domain, status) {
	return processes.filter((process) => (domain === '' || process.domain === domain) && (status === '' || statusGroup(process) === status));
}

/** Path of a process below the island's base: /{domain}/{name} */
export function processPath(process) {
	return `/${encodeURIComponent(process.domain)}/${encodeURIComponent(process.name)}`;
}

/** URL of a process page from the template with __DOMAIN__ and __NAME__ */
export function processUrl(template, process) {
	return template.replace('__DOMAIN__', encodeURIComponent(process.domain)).replace('__NAME__', encodeURIComponent(process.name));
}

/**
 * Presets the wizard offers for a kind of process: those of the kind, the
 * custom one, and for a web process only those whose template the server
 * has. Detected presets come first.
 *
 * @param {{name: string, kind: string, detected: string, templateAvailable: string}[]} presets
 * @param {'web'|'worker'} kind
 */
export function presetsFor(presets, kind) {
	const fits = presets.filter((preset) => preset.kind === 'either' || (preset.kind === kind && (kind !== 'web' || preset.templateAvailable !== 'no')));
	return [...fits.filter((preset) => preset.detected === 'yes'), ...fits.filter((preset) => preset.detected !== 'yes')];
}

/** A process name proposed for a kind and a preset */
export function defaultName(kind, preset) {
	if (kind === 'web') {
		return 'web';
	}
	const names = { 'laravel-queue': 'queue', 'laravel-horizon': 'horizon', 'laravel-scheduler': 'scheduler', celery: 'celery', sidekiq: 'sidekiq' };
	return names[preset] ?? 'worker';
}

/** The memory a new process gets: the package default within the highest */
export function defaultMemory(options) {
	const max = Number(options?.maxMemory) || 0;
	const value = Number(options?.defaultMemory) || 512;
	return max > 0 ? Math.min(value, max) : value;
}
