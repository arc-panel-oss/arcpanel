#!/usr/bin/env node
// Versions and changelog of a release line (docs/features/specs/2026-10-21-001-release-standards-design.md §7).
//
// Usage: npm run release -- <command>
//   check [--tag TAG]   the checks of spec §7.2; exit 1 with one line per failure
//   version VERSION     writes the version into the control file, both installers and src/release.json
//   open VERSION        opens the next version of a line: VERSION~alpha, src/release.json reset
//
// The tool never commits, tags or pushes. No dependencies, Node.js 22.
// RELEASE_ROOT replaces the repository root and RELEASE_BRANCH the current branch (tests).

import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const FILES = {
	control: 'src/deb/arcpanel/control',
	debian: 'install/arcpanel-install-debian.sh',
	ubuntu: 'install/arcpanel-install-ubuntu.sh',
	metadata: 'src/release.json',
	changelog: 'changelog',
};

// X.Y.Z, X.Y.Z~alpha, X.Y.Z~beta.N, X.Y.Z~rc.N (spec §4.3)
const VERSION_RE = /^(\d+)\.(\d+)\.(\d+)(?:~(alpha|beta\.[1-9]\d*|rc\.[1-9]\d*))?$/;
const CONTROL_RE = /^Version: ([^\r\n]*)/m;
const INSTALLER_RE = /^ARCPANEL_INSTALL_VER='([^'\r\n]*)'/m;
const METADATA_KEYS = ['version', 'security', 'attention', 'attention_items', 'min_from'];

export class ReleaseError extends Error {}

export function parseVersion(version) {
	const match = VERSION_RE.exec(version ?? '');
	if (!match) {
		return null;
	}
	return {
		major: Number(match[1]),
		minor: Number(match[2]),
		patch: Number(match[3]),
		base: `${match[1]}.${match[2]}.${match[3]}`,
		pre: match[4] ?? null,
	};
}

// Debian version order (dpkg's verrevcmp): "~" sorts before everything, even the end of the string
function order(char) {
	if (char === undefined) {
		return 0;
	}
	if (char === '~') {
		return -1;
	}
	if (/[A-Za-z]/.test(char)) {
		return char.charCodeAt(0);
	}
	return char.charCodeAt(0) + 256;
}

function compareFragment(a, b) {
	let i = 0;
	let j = 0;
	while (i < a.length || j < b.length) {
		let firstDiff = 0;
		while ((i < a.length && !/\d/.test(a[i])) || (j < b.length && !/\d/.test(b[j]))) {
			const ac = i < a.length && !/\d/.test(a[i]) ? a[i] : undefined;
			const bc = j < b.length && !/\d/.test(b[j]) ? b[j] : undefined;
			const diff = order(ac) - order(bc);
			if (diff) {
				return Math.sign(diff);
			}
			i++;
			j++;
		}
		while (a[i] === '0') {
			i++;
		}
		while (b[j] === '0') {
			j++;
		}
		while (i < a.length && /\d/.test(a[i]) && j < b.length && /\d/.test(b[j])) {
			if (!firstDiff) {
				firstDiff = a.charCodeAt(i) - b.charCodeAt(j);
			}
			i++;
			j++;
		}
		if (i < a.length && /\d/.test(a[i])) {
			return 1;
		}
		if (j < b.length && /\d/.test(b[j])) {
			return -1;
		}
		if (firstDiff) {
			return Math.sign(firstDiff);
		}
	}
	return 0;
}

function splitDebian(version) {
	const colon = version.indexOf(':');
	const epoch = colon > 0 ? Number(version.slice(0, colon)) : 0;
	const rest = colon > 0 ? version.slice(colon + 1) : version;
	const dash = rest.lastIndexOf('-');
	return dash > 0
		? { epoch, upstream: rest.slice(0, dash), revision: rest.slice(dash + 1) }
		: { epoch, upstream: rest, revision: '' };
}

export function compareVersions(a, b) {
	const left = splitDebian(a);
	const right = splitDebian(b);
	if (left.epoch !== right.epoch) {
		return Math.sign(left.epoch - right.epoch);
	}
	return (
		compareFragment(left.upstream, right.upstream) || compareFragment(left.revision, right.revision)
	);
}

function read(root, file) {
	return readFileSync(join(root, file), 'utf8');
}

function readVersion(root, file, pattern) {
	if (!existsSync(join(root, file))) {
		throw new ReleaseError(`${file} does not exist`);
	}
	const match = pattern.exec(read(root, file));
	if (!match) {
		throw new ReleaseError(`${file} has no version line`);
	}
	return match[1];
}

// Replaces the version on its line and keeps every other byte of the file
function writeVersion(root, file, pattern, line) {
	const text = read(root, file);
	if (!pattern.test(text)) {
		throw new ReleaseError(`${file} has no version line`);
	}
	writeFileSync(join(root, file), text.replace(pattern, line));
}

export function readMetadata(root) {
	try {
		return JSON.parse(read(root, FILES.metadata));
	} catch (error) {
		throw new ReleaseError(`${FILES.metadata} cannot be read: ${error.message}`);
	}
}

function writeMetadata(root, metadata) {
	const ordered = Object.fromEntries(METADATA_KEYS.map((key) => [key, metadata[key]]));
	writeFileSync(join(root, FILES.metadata), `${JSON.stringify(ordered, null, '\t')}\n`);
}

export function readVersions(root) {
	return {
		control: readVersion(root, FILES.control, CONTROL_RE),
		debian: readVersion(root, FILES.debian, INSTALLER_RE),
		ubuntu: readVersion(root, FILES.ubuntu, INSTALLER_RE),
	};
}

function writeVersions(root, version) {
	writeVersion(root, FILES.control, CONTROL_RE, `Version: ${version}`);
	writeVersion(root, FILES.debian, INSTALLER_RE, `ARCPANEL_INSTALL_VER='${version}'`);
	writeVersion(root, FILES.ubuntu, INSTALLER_RE, `ARCPANEL_INSTALL_VER='${version}'`);
}

export function currentBranch(root) {
	if (process.env.RELEASE_BRANCH !== undefined) {
		return process.env.RELEASE_BRANCH || null;
	}
	try {
		const branch = execFileSync('git', ['rev-parse', '--abbrev-ref', 'HEAD'], {
			cwd: root,
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'ignore'],
		}).trim();
		return branch && branch !== 'HEAD' ? branch : null;
	} catch {
		return null;
	}
}

// The release files of changelog/: X.Y.Z.md
export function releaseFiles(root) {
	const dir = join(root, FILES.changelog);
	if (!existsSync(dir)) {
		return [];
	}
	return readdirSync(dir, { withFileTypes: true })
		.filter((entry) => entry.isFile() && /^\d+\.\d+\.\d+\.md$/.test(entry.name))
		.map((entry) => entry.name.slice(0, -3))
		.sort((a, b) => compareVersions(b, a));
}

function isVersionString(value) {
	return typeof value === 'string' && parseVersion(value) !== null;
}

// Each check returns its failures, one line each (spec §7.2)
export function runChecks(root, { tag = null, branch = currentBranch(root) } = {}) {
	const failures = [];
	let versions;
	let metadata;
	try {
		versions = readVersions(root);
	} catch (error) {
		failures.push(`C1 ${error.message}`);
	}
	try {
		metadata = readMetadata(root);
	} catch (error) {
		failures.push(`C3 ${error.message}`);
	}
	if (!versions || !metadata) {
		return failures;
	}
	const { control } = versions;
	const parsed = parseVersion(control);

	// C1: one version in the control file and both installers
	if (versions.debian !== control || versions.ubuntu !== control) {
		failures.push(
			`C1 versions differ: ${FILES.control} ${control}, ${FILES.debian} ${versions.debian}, ${FILES.ubuntu} ${versions.ubuntu}`,
		);
	}
	if (!parsed) {
		failures.push(`C1 ${control} is not X.Y.Z, X.Y.Z~alpha, X.Y.Z~beta.N or X.Y.Z~rc.N`);
	}

	// C3: the release metadata of E33 §4
	const unknown = Object.keys(metadata).filter((key) => !METADATA_KEYS.includes(key));
	if (unknown.length) {
		failures.push(`C3 ${FILES.metadata} has unknown fields: ${unknown.join(', ')}`);
	}
	const metadataVersion = parseVersion(metadata.version);
	if (!metadataVersion || metadataVersion.pre === 'alpha') {
		failures.push(
			`C3 ${FILES.metadata} version ${metadata.version} is not X.Y.Z, X.Y.Z~beta.N or X.Y.Z~rc.N`,
		);
	}
	if (typeof metadata.security !== 'boolean') {
		failures.push(`C3 ${FILES.metadata} security is not true or false`);
	}
	if (typeof metadata.attention !== 'boolean') {
		failures.push(`C3 ${FILES.metadata} attention is not true or false`);
	}
	const items = metadata.attention_items;
	if (
		!Array.isArray(items) ||
		!items.every((item) => typeof item === 'string' && item.trim() !== '')
	) {
		failures.push(`C3 ${FILES.metadata} attention_items is not a list of sentences`);
	} else if (metadata.attention === true && items.length === 0) {
		failures.push(`C3 ${FILES.metadata} says attention: true without attention_items`);
	}
	if (metadata.min_from !== null && !isVersionString(metadata.min_from)) {
		failures.push(`C3 ${FILES.metadata} min_from ${metadata.min_from} is not a version or null`);
	}

	// C2: src/release.json names the version the line is heading for
	if (metadata.version !== control && `${metadata.version}~alpha` !== control) {
		failures.push(`C2 ${FILES.metadata} says ${metadata.version}, the control file ${control}`);
	}

	// C4: the branch fits the version
	const line = /^v(\d+)\.(\d+)\.x$/.exec(branch ?? '');
	if (parsed && line && (Number(line[1]) !== parsed.major || Number(line[2]) !== parsed.minor)) {
		failures.push(`C4 ${control} is not a version of the line ${branch}`);
	}
	if (parsed && branch === 'develop' && (parsed.patch !== 0 || parsed.pre !== 'alpha')) {
		failures.push(`C4 develop carries ${control}, expected X.Y.0~alpha`);
	}

	// C5: the tag matches the version
	if (tag !== null) {
		const expected = `v${control.replace('~', '-')}`;
		if (tag !== expected) {
			failures.push(
				`C5 tag ${tag} does not match the control version ${control} (expected ${expected})`,
			);
		}
		if (parsed?.pre === 'alpha') {
			failures.push(
				`C5 ${control} is a development version; set the release version before tagging`,
			);
		}
	}

	// C10: the release files
	const lineVersion = metadata.version;
	for (const version of releaseFiles(root)) {
		const file = `${FILES.changelog}/${version}.md`;
		const heading = read(root, file).split(/\r?\n/, 1)[0];
		const match = /^## (\d+\.\d+\.\d+) — \d{4}-\d{2}-\d{2}(?: \([^)]+\))?$/.exec(heading);
		if (!match || match[1] !== version) {
			failures.push(`C10 ${file} does not start with "## ${version} — YYYY-MM-DD"`);
		}
		if (isVersionString(lineVersion) && compareVersions(version, lineVersion) > 0) {
			failures.push(`C10 ${file} is newer than the line's version ${lineVersion}`);
		}
	}
	return failures;
}

function commandCheck(root, args, out) {
	let tag = null;
	for (let i = 0; i < args.length; i++) {
		if (args[i] === '--tag' && args[i + 1]) {
			tag = args[++i];
		} else {
			throw new ReleaseError('usage: check [--tag TAG]');
		}
	}
	const failures = runChecks(root, { tag });
	if (failures.length) {
		for (const failure of failures) {
			out.err(`release: ${failure}`);
		}
		return 1;
	}
	const branch = currentBranch(root);
	out.log(
		`release: ${readVersions(root).control} passes${branch ? ` on ${branch}` : ''}${tag ? ` for ${tag}` : ''}`,
	);
	return 0;
}

export function setVersion(root, version) {
	const parsed = parseVersion(version);
	if (!parsed) {
		throw new ReleaseError(`${version} is not X.Y.Z, X.Y.Z~alpha, X.Y.Z~beta.N or X.Y.Z~rc.N`);
	}
	const metadata = readMetadata(root);
	writeVersions(root, version);
	writeMetadata(root, { ...metadata, version: parsed.pre === 'alpha' ? parsed.base : version });
}

export function openVersion(root, version) {
	const parsed = parseVersion(version);
	if (!parsed || parsed.pre) {
		throw new ReleaseError(`open takes a release version X.Y.Z, not ${version}`);
	}
	writeVersions(root, `${version}~alpha`);
	writeMetadata(root, {
		version,
		security: false,
		attention: false,
		attention_items: [],
		min_from: null,
	});
}

function single(args, usage) {
	if (args.length !== 1) {
		throw new ReleaseError(`usage: ${usage}`);
	}
	return args[0];
}

export function run(argv, { root = defaultRoot(), out = console } = {}) {
	const [command, ...args] = argv;
	try {
		switch (command) {
			case 'check':
				return commandCheck(root, args, { log: out.log, err: out.error ?? out.err });
			case 'version': {
				const version = single(args, 'version VERSION');
				setVersion(root, version);
				out.log(`release: version ${version} written; run npm run release -- check`);
				return 0;
			}
			case 'open': {
				const version = single(args, 'open VERSION');
				openVersion(root, version);
				out.log(`release: ${version}~alpha opened`);
				out.log(`  git commit -am "chore(release): open ${version}"`);
				return 0;
			}
			default:
				throw new ReleaseError('usage: release.mjs check|version|open …');
		}
	} catch (error) {
		if (error instanceof ReleaseError) {
			(out.error ?? out.err)(`release: ${error.message}`);
			return 1;
		}
		throw error;
	}
}

function defaultRoot() {
	return process.env.RELEASE_ROOT || join(dirname(fileURLToPath(import.meta.url)), '..');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	process.exitCode = run(process.argv.slice(2));
}
