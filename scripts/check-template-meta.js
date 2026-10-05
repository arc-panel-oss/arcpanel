// Checks the web template metadata of E6 (docs/features/specs/
// 2026-10-05-005-e6-templates-batch1-design.md §4): every metadata file follows
// the format, every shipped template of the NGINX stacks has metadata, and
// every metadata file names a template. Exits non-zero on problems.
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../install/deb/templates/web/', import.meta.url));
const metaDir = path.join(root, 'meta');

// Template folders and the kind their templates are listed as
const folders = [
	{ dir: 'nginx/php-fpm', kind: 'web', ext: '.tpl' },
	{ dir: 'apache2/php-fpm', kind: 'web', ext: '.tpl' },
	{ dir: 'nginx', kind: 'proxy', ext: '.tpl', skip: ['proxy_ip'] },
	{ dir: 'php-fpm', kind: 'backend', ext: '.tpl' },
];
const kinds = ['web', 'proxy', 'backend'];
const categories = [
	'php-cms',
	'php-framework',
	'ecommerce',
	'nodejs',
	'python',
	'static',
	'proxy',
	'general',
	'other',
];
const runtimes = ['php', 'node', 'python', 'docker', 'none'];
const patternFiles = ['backend/PHP-x_y.json'];

const isString = (value) => typeof value === 'string';

/**
 * Format problems of one parsed metadata file.
 *
 * @param {unknown} meta
 * @returns {string[]}
 */
export function validateMeta(meta) {
	const problems = [];
	if (meta === null || typeof meta !== 'object' || Array.isArray(meta)) {
		return ['not a JSON object'];
	}
	const allowed = ['title', 'description', 'category', 'runtime', 'internal', 'variables', 'docs'];
	for (const key of Object.keys(meta)) {
		if (!allowed.includes(key)) problems.push(`unknown field "${key}"`);
	}
	if (!isString(meta.title) || meta.title.length === 0 || meta.title.length > 60) {
		problems.push('title must be a string of 1 to 60 characters');
	}
	if (
		!isString(meta.description) ||
		meta.description.length === 0 ||
		meta.description.length > 160
	) {
		problems.push('description must be a string of 1 to 160 characters');
	}
	if (!categories.includes(meta.category))
		problems.push(`category must be one of ${categories.join(', ')}`);
	if (meta.runtime !== undefined && !runtimes.includes(meta.runtime)) {
		problems.push(`runtime must be one of ${runtimes.join(', ')}`);
	}
	if (meta.internal !== undefined && typeof meta.internal !== 'boolean')
		problems.push('internal must be a boolean');
	if (meta.docs !== undefined && (!isString(meta.docs) || !meta.docs.startsWith('https://'))) {
		problems.push('docs must be an https:// URL');
	}
	if (meta.variables !== undefined) {
		if (!Array.isArray(meta.variables)) {
			problems.push('variables must be a list');
		} else {
			meta.variables.forEach((variable, index) => {
				const where = `variables[${index}]`;
				if (!isString(variable?.key) || !/^[A-Z][A-Z0-9_]*$/.test(variable.key)) {
					problems.push(`${where}.key must match [A-Z][A-Z0-9_]*`);
				}
				if (variable?.type !== 'port') problems.push(`${where}.type must be "port"`);
				if (variable?.required !== undefined && typeof variable.required !== 'boolean') {
					problems.push(`${where}.required must be a boolean`);
				}
				if (
					!isString(variable?.label) ||
					variable.label.length === 0 ||
					variable.label.length > 40
				) {
					problems.push(`${where}.label must be a string of 1 to 40 characters`);
				}
			});
		}
	}
	return problems;
}

async function listNames(dir, ext, skip = []) {
	let files;
	try {
		files = await fs.readdir(path.join(root, dir), { withFileTypes: true });
	} catch {
		return [];
	}
	return files
		.filter((file) => file.isFile() && file.name.endsWith(ext))
		.map((file) => file.name.slice(0, -ext.length))
		.filter((name) => !skip.includes(name));
}

async function listMetaFiles() {
	const files = [];
	for (const entry of await fs.readdir(metaDir, { withFileTypes: true })) {
		if (entry.isFile() && entry.name.endsWith('.json')) files.push(entry.name);
		if (entry.isDirectory()) {
			for (const sub of await fs.readdir(path.join(metaDir, entry.name), { withFileTypes: true })) {
				if (sub.isFile() && sub.name.endsWith('.json')) files.push(`${entry.name}/${sub.name}`);
			}
		}
	}
	return files.sort();
}

async function main() {
	const problems = [];
	const metaFiles = await listMetaFiles();
	const metaSet = new Set(metaFiles);

	for (const file of metaFiles) {
		const parts = file.split('/');
		if (parts.length === 2 && !kinds.includes(parts[0])) {
			problems.push(`meta/${file}: folder must be one of ${kinds.join(', ')}`);
			continue;
		}
		try {
			const meta = JSON.parse(await fs.readFile(path.join(metaDir, file), 'utf8'));
			for (const problem of validateMeta(meta)) problems.push(`meta/${file}: ${problem}`);
		} catch (error) {
			problems.push(`meta/${file}: invalid JSON (${error.message})`);
		}
	}

	// Every template has metadata; record which metadata files are used
	const used = new Set(patternFiles);
	for (const folder of folders) {
		for (const name of await listNames(folder.dir, folder.ext, folder.skip)) {
			const candidates = [`${folder.kind}/${name}.json`, `${name}.json`];
			const found = candidates.find((candidate) => metaSet.has(candidate));
			if (found) {
				used.add(found);
			} else if (!(folder.kind === 'backend' && /^PHP-\d+_\d+$/.test(name))) {
				problems.push(
					`${folder.dir}/${name}${folder.ext}: no metadata (meta/${name}.json or meta/${folder.kind}/${name}.json)`,
				);
			}
		}
	}
	for (const file of metaFiles) {
		if (!used.has(file)) problems.push(`meta/${file}: names no shipped template`);
	}

	if (problems.length > 0) {
		console.error(problems.join('\n'));
		console.error(`\n${problems.length} template metadata problem(s).`);
		process.exit(1);
	}
	console.log(`Template metadata OK (${metaFiles.length} files).`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	await main();
}
