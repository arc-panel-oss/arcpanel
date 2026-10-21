// Tests of scripts/release.mjs (docs/features/specs/2026-10-21-001-release-standards-design.md §10).
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { compareVersions, run, runChecks } from './release.mjs';

let root;

function write(file, text) {
	mkdirSync(dirname(join(root, file)), { recursive: true });
	writeFileSync(join(root, file), text);
}

function read(file) {
	return readFileSync(join(root, file), 'utf8');
}

function setVersions(control, debian = control, ubuntu = control) {
	write(
		'src/deb/arcpanel/control',
		`Source: arcpanel\nPackage: arcpanel\nVersion: ${control}\nSection: admin\n`,
	);
	write(
		'install/arcpanel-install-debian.sh',
		`#!/bin/bash\n# Debian\nARCPANEL_INSTALL_VER='${debian}'\nexit 0\n`,
	);
	write(
		'install/arcpanel-install-ubuntu.sh',
		`#!/bin/bash\n# Ubuntu\nARCPANEL_INSTALL_VER='${ubuntu}'\nexit 0\n`,
	);
}

function setMetadata(fields) {
	const metadata = {
		version: '2.3.0',
		security: false,
		attention: false,
		attention_items: [],
		min_from: null,
		...fields,
	};
	write('src/release.json', `${JSON.stringify(metadata, null, '\t')}\n`);
}

// Runs the tool and returns its exit code and output
function tool(...argv) {
	const lines = [];
	const code = run(argv, {
		root,
		out: { log: (line) => lines.push(line), error: (line) => lines.push(line) },
	});
	return { code, output: lines.join('\n') };
}

function check(options = {}) {
	return runChecks(root, { branch: 'v2.3.x', ...options });
}

beforeEach(() => {
	root = mkdtempSync(join(tmpdir(), 'arcpanel-release-'));
	setVersions('2.3.0~alpha');
	setMetadata();
});

afterEach(() => {
	rmSync(root, { recursive: true, force: true });
});

describe('version order', () => {
	it('follows dpkg', () => {
		const ordered = [
			'2.3.0~alpha',
			'2.3.0~beta.1',
			'2.3.0~beta.2',
			'2.3.0~beta.10',
			'2.3.0~rc.1',
			'2.3.0',
			'2.3.1',
			'2.10.0',
		];
		for (let i = 0; i < ordered.length - 1; i++) {
			expect(compareVersions(ordered[i], ordered[i + 1])).toBe(-1);
			expect(compareVersions(ordered[i + 1], ordered[i])).toBe(1);
		}
		expect(compareVersions('2.10.0', '2.9.9')).toBe(1);
		expect(compareVersions('2.3.0', '2.3.0')).toBe(0);
		expect(compareVersions('2.3.0~alpha', '2.2.9')).toBe(1);
	});
});

describe('version and open', () => {
	it('writes the four files and keeps the other metadata', () => {
		setMetadata({ attention: true, attention_items: ['Read this.'] });
		expect(tool('version', '2.3.0~beta.1').code).toBe(0);
		expect(read('src/deb/arcpanel/control')).toBe(
			'Source: arcpanel\nPackage: arcpanel\nVersion: 2.3.0~beta.1\nSection: admin\n',
		);
		expect(read('install/arcpanel-install-debian.sh')).toContain(
			"ARCPANEL_INSTALL_VER='2.3.0~beta.1'\nexit 0\n",
		);
		expect(read('install/arcpanel-install-ubuntu.sh')).toContain(
			"ARCPANEL_INSTALL_VER='2.3.0~beta.1'",
		);
		expect(JSON.parse(read('src/release.json'))).toEqual({
			version: '2.3.0~beta.1',
			security: false,
			attention: true,
			attention_items: ['Read this.'],
			min_from: null,
		});
		expect(check()).toEqual([]);
	});

	it('keeps the release version in the metadata for ~alpha', () => {
		expect(tool('version', '2.3.0~alpha').code).toBe(0);
		expect(JSON.parse(read('src/release.json')).version).toBe('2.3.0');
		expect(check()).toEqual([]);
	});

	it('writes the metadata with tabs and a final newline', () => {
		tool('version', '2.3.0');
		expect(read('src/release.json')).toBe(
			'{\n\t"version": "2.3.0",\n\t"security": false,\n\t"attention": false,\n\t"attention_items": [],\n\t"min_from": null\n}\n',
		);
	});

	it('keeps CRLF line endings of the other lines', () => {
		write(
			'src/deb/arcpanel/control',
			'Package: arcpanel\r\nVersion: 2.3.0~alpha\r\nSection: admin\r\n',
		);
		tool('version', '2.3.0');
		expect(read('src/deb/arcpanel/control')).toBe(
			'Package: arcpanel\r\nVersion: 2.3.0\r\nSection: admin\r\n',
		);
	});

	it('refuses a version that is not one of ours', () => {
		for (const version of [
			'2.3',
			'2.3.0-beta.1',
			'2.3.0~beta',
			'2.3.0~beta.0',
			'2.3.0~preview.1',
			'v2.3.0',
		]) {
			const result = tool('version', version);
			expect(result.code).toBe(1);
			expect(result.output).toContain('is not X.Y.Z');
		}
		expect(read('src/deb/arcpanel/control')).toContain('Version: 2.3.0~alpha');
	});

	it('opens the next version', () => {
		setVersions('2.3.0');
		setMetadata({
			attention: true,
			attention_items: ['Read this.'],
			security: true,
			min_from: '2.2.0',
		});
		const result = tool('open', '2.3.1');
		expect(result.code).toBe(0);
		expect(result.output).toContain('chore(release): open 2.3.1');
		expect(read('src/deb/arcpanel/control')).toContain('Version: 2.3.1~alpha');
		expect(read('install/arcpanel-install-ubuntu.sh')).toContain(
			"ARCPANEL_INSTALL_VER='2.3.1~alpha'",
		);
		expect(JSON.parse(read('src/release.json'))).toEqual({
			version: '2.3.1',
			security: false,
			attention: false,
			attention_items: [],
			min_from: null,
		});
	});

	it('opens only a release version', () => {
		expect(tool('open', '2.3.1~alpha').code).toBe(1);
	});
});

describe('check', () => {
	it('passes on a line between tags', () => {
		const result = tool('check');
		expect(result.output).toContain('release: 2.3.0~alpha passes');
		expect(check()).toEqual([]);
	});

	it('C1 refuses installers with another version', () => {
		setVersions('2.3.0~alpha', '2.3.0~alpha', '2.2.3~alpha');
		expect(check()).toEqual([expect.stringMatching(/^C1 versions differ/)]);
	});

	it('C1 refuses a version that is not one of ours', () => {
		setVersions('2.3.0~preview');
		expect(check().some((line) => line.startsWith('C1 2.3.0~preview is not'))).toBe(true);
	});

	it('C2 refuses metadata of another version', () => {
		setMetadata({ version: '2.3.1' });
		expect(check()).toEqual(['C2 src/release.json says 2.3.1, the control file 2.3.0~alpha']);
	});

	it('C2 needs the full version for a beta', () => {
		setVersions('2.3.0~beta.1');
		expect(check()).toEqual(['C2 src/release.json says 2.3.0, the control file 2.3.0~beta.1']);
		setMetadata({ version: '2.3.0~beta.1' });
		expect(check()).toEqual([]);
	});

	it('C3 refuses invalid metadata', () => {
		setMetadata({ attention: true });
		expect(check()).toEqual(['C3 src/release.json says attention: true without attention_items']);
		setMetadata({ security: 'no' });
		expect(check()).toEqual(['C3 src/release.json security is not true or false']);
		setMetadata({ attention_items: [''] });
		expect(check()).toEqual(['C3 src/release.json attention_items is not a list of sentences']);
		setMetadata({ min_from: '2.2' });
		expect(check()).toEqual(['C3 src/release.json min_from 2.2 is not a version or null']);
		setMetadata({ notes: 'x' });
		expect(check()).toEqual(['C3 src/release.json has unknown fields: notes']);
		setMetadata({ version: '2.3.0~alpha' });
		expect(check()).toContain(
			'C3 src/release.json version 2.3.0~alpha is not X.Y.Z, X.Y.Z~beta.N or X.Y.Z~rc.N',
		);
		write('src/release.json', '{');
		expect(check()[0]).toMatch(/^C3 src\/release.json cannot be read/);
	});

	it('C4 checks the version against the branch', () => {
		expect(check({ branch: 'v2.2.x' })).toEqual([
			'C4 2.3.0~alpha is not a version of the line v2.2.x',
		]);
		expect(check({ branch: 'develop' })).toEqual([]);
		expect(check({ branch: 'feature/x' })).toEqual([]);
		expect(check({ branch: null })).toEqual([]);
		setVersions('2.3.1~alpha');
		setMetadata({ version: '2.3.1' });
		expect(check()).toEqual([]);
		expect(check({ branch: 'develop' })).toEqual([
			'C4 develop carries 2.3.1~alpha, expected X.Y.0~alpha',
		]);
	});

	it('C5 compares the tag with the version', () => {
		setVersions('2.3.0~beta.1');
		setMetadata({ version: '2.3.0~beta.1' });
		expect(check({ tag: 'v2.3.0-beta.1' })).toEqual([]);
		expect(check({ tag: 'v2.3.0' })).toEqual([
			'C5 tag v2.3.0 does not match the control version 2.3.0~beta.1 (expected v2.3.0-beta.1)',
		]);
		setVersions('2.3.0~rc.1');
		setMetadata({ version: '2.3.0~rc.1' });
		expect(check({ tag: 'v2.3.0-rc.1' })).toEqual([]);
	});

	it('C5 refuses a tag of a development version', () => {
		expect(check({ tag: 'v2.3.0-alpha' })).toEqual([
			'C5 2.3.0~alpha is a development version; set the release version before tagging',
		]);
	});

	it('C10 checks the release files', () => {
		write('changelog/2.2.2.md', '## 2.2.2 — 2026-10-20\n\n### Fixed\n\n- A fix.\n');
		write('changelog/2.1.1.md', '## 2.1.1 — 2026-10-13 (not published)\n\n### Fixed\n\n- A fix.\n');
		expect(check()).toEqual([]);
		write('changelog/2.2.1.md', '## 2.2.2 — 2026-10-20\n');
		expect(check()).toEqual(['C10 changelog/2.2.1.md does not start with "## 2.2.1 — YYYY-MM-DD"']);
		write('changelog/2.2.1.md', '## 2.2.1 — 2026-10-20\n');
		write('changelog/2.3.1.md', '## 2.3.1 — 2026-10-20\n');
		expect(check()).toEqual(["C10 changelog/2.3.1.md is newer than the line's version 2.3.0"]);
	});

	it('exits 1 with one line per failure', () => {
		setVersions('2.3.0~alpha', '2.3.0~alpha', '2.2.0');
		setMetadata({ version: '2.4.0' });
		const result = tool('check');
		expect(result.code).toBe(1);
		expect(result.output.split('\n')).toHaveLength(2);
	});
});
