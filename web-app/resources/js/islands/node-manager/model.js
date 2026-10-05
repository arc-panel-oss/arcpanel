// Rules of the Node.js page that need no Vue (E13 spec §5, §7.2): the same
// checks as func/node.sh, so the form refuses what the command would.

const NAME = '(@[a-z0-9][a-z0-9._~-]*/)?[a-z0-9][a-z0-9._~-]*';

/** npm package name, optionally scoped */
export function isPackageName(value) {
	return value.length <= 214 && new RegExp(`^${NAME}$`).test(value);
}

/** NAME or NAME@VERSION (version, range or dist-tag); no URL, git or path */
export function isPackageSpec(value) {
	return value.length <= 280 && new RegExp(`^${NAME}(@[A-Za-z0-9.^~<>=*+-]{1,64})?$`).test(value);
}

/** Package name of a spec: the "@" of a scope belongs to the name */
export function packageName(spec) {
	if (spec.startsWith('@')) {
		const rest = spec.slice(1);
		return `@${rest.split('@')[0]}`;
	}
	return spec.split('@')[0];
}

/** Badge tone and label key of a release status */
export function releaseStatus(status) {
	switch (status) {
		case 'active':
			return { tone: 'ok', label: 'Active LTS' };
		case 'maintenance':
			return { tone: 'info', label: 'Maintenance LTS' };
		case 'current':
			return { tone: 'accent', label: 'Current' };
		case 'eol':
			return { tone: 'danger', label: 'End of life' };
		default:
			return { tone: null, label: '' };
	}
}

/**
 * What a task did, for the task panel.
 *
 * @param {{action: string, args: string}} task
 * @param {(key: string, ...args: string[]) => string} t
 */
export function taskLabel(task, t) {
	const [first = '', second = ''] = (task.args ?? '').split(' ');
	switch (task.action) {
		case 'add-version':
			return t('Install Node.js %s', first);
		case 'add-package':
			return t('Install %s', second);
		case 'update-packages':
			return t('Update the packages of Node.js %s', first);
		case 'check-outdated':
			return t('Check the packages of Node.js %s for updates', first);
		case 'app-install':
			return t('Install the dependencies of %s', first);
		case 'app-build':
			return t('Build %s', first);
		default:
			return t('Task');
	}
}

/** Packages offered as one-click installs, without those already there */
export function quickPackages(installed) {
	const names = new Set(installed.map((item) => item.name));
	return ['pnpm', 'yarn', 'pm2'].filter((name) => !names.has(name));
}
