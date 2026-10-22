// Tests of scripts/release.mjs (docs/features/specs/2026-10-21-001-release-standards-design.md §10).
import {
	existsSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
	compareVersions,
	parseFragment,
	releaseNotes,
	run,
	runChecks,
	writeChangelog,
} from './release.mjs';

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

function fragment(name, front, body) {
	write(`changelog/unreleased/${name}.md`, `---\n${front}\n---\n\n${body}\n`);
}

function rebuild() {
	writeChangelog(root);
}

function check(options = {}) {
	return runChecks(root, { branch: 'v2.3.x', ...options });
}

beforeEach(() => {
	root = mkdtempSync(join(tmpdir(), 'arcpanel-release-'));
	setVersions('2.3.0~alpha');
	setMetadata();
	write('changelog/header.md', '# Changelog\n\nAll notable changes.\n');
	write('changelog/unreleased/.gitkeep', '');
	rebuild();
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
		rebuild();
		expect(check()).toEqual([]);
		write('changelog/2.2.1.md', '## 2.2.2 — 2026-10-20\n');
		rebuild();
		expect(check()).toEqual(['C10 changelog/2.2.1.md does not start with "## 2.2.1 — YYYY-MM-DD"']);
		write('changelog/2.2.1.md', '## 2.2.1 — 2026-10-20\n');
		write('changelog/2.3.1.md', '## 2.3.1 — 2026-10-20\n');
		rebuild();
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

describe('fragments', () => {
	it('parses the front matter and the body', () => {
		expect(
			parseFragment(
				'e40-nftables.md',
				'---\r\ntype: changed\r\nattention: true\r\norder: 10\r\n---\r\n\r\n- One.\r\n- Two.\r\n',
			),
		).toEqual({
			name: 'e40-nftables.md',
			type: 'changed',
			attention: true,
			order: 10,
			body: '- One.\n- Two.',
		});
		expect(
			parseFragment('e33-summary.md', '---\ntype: summary\n---\n\nA release.\n'),
		).toMatchObject({
			attention: false,
			order: 100,
		});
	});

	it('refuses broken fragments', () => {
		const cases = [
			['E40-x.md', '---\ntype: fixed\n---\n\n- x\n', 'the name'],
			['nohyphen.md', '---\ntype: fixed\n---\n\n- x\n', 'the name'],
			['e40-x.md', '- x\n', 'no front matter'],
			['e40-x.md', '---\n---\n\n- x\n', 'no front matter'],
			['e40-x.md', '---\nattention: true\n---\n\n- x\n', 'type must be one of'],
			['e40-x.md', '---\ntype: feature\n---\n\n- x\n', 'type must be one of'],
			['e40-x.md', '---\ntype: fixed\ntype: added\n---\n\n- x\n', 'unknown or repeated'],
			['e40-x.md', '---\ntype: fixed\nscope: e40\n---\n\n- x\n', 'unknown or repeated'],
			['e40-x.md', '---\ntype: fixed\nattention: yes\n---\n\n- x\n', 'attention must be'],
			['e40-x.md', '---\ntype: fixed\norder: first\n---\n\n- x\n', 'order must be'],
			['e40-x.md', '---\ntype: fixed\n---\n\n', 'the body is empty'],
			['e40-x.md', '---\ntype: fixed\n---\n\nA fix.\n', 'top-level bullet'],
			['e40-x.md', '---\ntype: fixed\n---\n\n<!-- hint -->\n', 'top-level bullet'],
			['e40-x.md', '---\ntype: fixed\n---\n\n- x\n\n### Added\n', 'headings'],
			['e40-x.md', '---\ntype: summary\n---\n\n- x\n', 'one paragraph'],
			['e40-x.md', '---\ntype: summary\n---\n\nOne.\n\nTwo.\n', 'one paragraph'],
		];
		for (const [name, text, message] of cases) {
			expect(() => parseFragment(name, text), `${name} ${JSON.stringify(text)}`).toThrow(message);
		}
	});

	it('creates a fragment that fails the check until it is written', () => {
		const result = tool('changelog', 'new', 'e40-ipv6-bans', '--type', 'added', '--attention');
		expect(result.code).toBe(0);
		expect(read('changelog/unreleased/e40-ipv6-bans.md')).toMatch(
			/^---\ntype: added\nattention: true\n---\n\n<!-- /,
		);
		expect(
			check().some((line) => line.startsWith('C6 changelog/unreleased/e40-ipv6-bans.md:')),
		).toBe(true);
		expect(tool('changelog', 'new', 'e40-ipv6-bans', '--type', 'added').output).toContain(
			'exists already',
		);
		expect(tool('changelog', 'new', 'e40-x', '--type', 'feature').code).toBe(1);
		expect(tool('changelog', 'new', 'E40', '--type', 'added').code).toBe(1);
		expect(tool('changelog', 'new', '--type', 'added').code).toBe(1);
	});
});

describe('build and preview', () => {
	it('builds CHANGELOG.md from the header and the release files, newest first', () => {
		write('changelog/2.2.10.md', '## 2.2.10 — 2026-11-02\r\n\r\n### Fixed\r\n\r\n- Ten.\r\n');
		write('changelog/2.2.9.md', '## 2.2.9 — 2026-11-01\n\n### Fixed\n\n- Nine.\n\n');
		expect(tool('changelog', 'build').code).toBe(0);
		expect(read('CHANGELOG.md')).toBe(
			[
				'<!-- Built by `npm run release -- changelog build` from changelog/; do not edit this file by hand. -->',
				'',
				'# Changelog',
				'',
				'All notable changes.',
				'',
				'Changes that are not released yet are in `changelog/unreleased/` of each release line.',
				'',
				'## 2.2.10 — 2026-11-02',
				'',
				'### Fixed',
				'',
				'- Ten.',
				'',
				'## 2.2.9 — 2026-11-01',
				'',
				'### Fixed',
				'',
				'- Nine.',
				'',
			].join('\n'),
		);
	});

	it('previews the next release in the order of the changelog', () => {
		fragment('e40-fix-b', 'type: fixed', '- Fix B.');
		fragment('e40-fix-a', 'type: fixed', '- Fix A.');
		fragment('e40-headline', 'type: added\norder: 10', '- **Headline.**\n- Second line of it.');
		fragment('e44-object', 'type: added', '- Object storage.');
		fragment('e40-summary', 'type: summary', 'What 2.3.0 is about.');
		fragment('e40-move', 'type: breaking', '- The firewall moves.');
		fragment('e40-cve', 'type: security', '- A security fix.');
		setMetadata({ security: true });
		const result = tool('changelog', 'preview');
		expect(result.code).toBe(0);
		expect(result.output).toBe(
			[
				'## 2.3.0 — unreleased',
				'',
				'What 2.3.0 is about.',
				'',
				'### Breaking changes',
				'',
				'- The firewall moves.',
				'',
				'### Security',
				'',
				'- A security fix.',
				'',
				'### Added',
				'',
				'- **Headline.**',
				'- Second line of it.',
				'- Object storage.',
				'',
				'### Fixed',
				'',
				'- Fix A.',
				'- Fix B.',
			].join('\n'),
		);
	});

	it('previews an empty line', () => {
		expect(tool('changelog', 'preview').output).toContain('No fragments');
	});
});

describe('checks of the changelog', () => {
	it('C5 needs the release file and no fragments at a stable tag', () => {
		setVersions('2.3.0');
		expect(check({ tag: 'v2.3.0' })).toEqual([
			'C5 changelog/2.3.0.md does not exist; run npm run release -- cut',
		]);
		write('changelog/2.3.0.md', '## 2.3.0 — 2026-10-28\n\n### Fixed\n\n- A fix.\n');
		rebuild();
		expect(check({ tag: 'v2.3.0' })).toEqual([]);
		fragment('e40-late', 'type: fixed', '- Late.');
		expect(check({ tag: 'v2.3.0' })).toEqual([
			'C5 changelog/unreleased/ still holds fragments at a stable tag',
		]);
	});

	it('C5 leaves the fragments alone at a beta', () => {
		setVersions('2.3.0~beta.1');
		setMetadata({ version: '2.3.0~beta.1' });
		fragment('e40-fix', 'type: fixed', '- A fix.');
		expect(check({ tag: 'v2.3.0-beta.1' })).toEqual([]);
	});

	it('C6 refuses broken fragments and a second summary', () => {
		fragment('e40-a', 'type: summary', 'One.');
		fragment('e40-b', 'type: summary', 'Two.');
		write('changelog/unreleased/Notes.txt', 'x');
		expect(check()).toEqual([
			expect.stringMatching(/^C6 changelog\/unreleased\/Notes.txt: the name/),
			'C6 more than one summary: e40-a.md, e40-b.md',
		]);
	});

	it('C7 ties security fragments to security: true', () => {
		fragment('e40-cve', 'type: security', '- A fix.');
		expect(check()).toEqual(['C7 e40-cve.md needs security: true in src/release.json']);
		setMetadata({ security: true });
		expect(check()).toEqual([]);
		rmSync(join(root, 'changelog/unreleased/e40-cve.md'));
		expect(check()).toEqual([
			'C7 src/release.json says security: true without a security fragment',
		]);
	});

	it('C8 needs attention items for fragments that need attention', () => {
		fragment('e40-move', 'type: changed\nattention: true', '- The firewall moves.');
		expect(check()).toEqual(['C8 e40-move.md needs attention_items in src/release.json']);
		setMetadata({ attention_items: ['The firewall moves.'] });
		expect(check()).toEqual([]);
	});

	it('C8 needs attention: true for a patch release with such fragments', () => {
		setVersions('2.3.1~alpha');
		setMetadata({ version: '2.3.1', attention_items: ['Read this.'] });
		fragment('e40-move', 'type: changed\nattention: true', '- The firewall moves.');
		fragment('e40-gone', 'type: breaking', '- A command is gone.');
		expect(check()).toEqual([
			'C8 a patch release with e40-move.md, e40-gone.md needs attention: true',
		]);
		setMetadata({ version: '2.3.1', attention: true, attention_items: ['Read this.'] });
		expect(check()).toEqual([]);
	});

	it('C9 refuses a CHANGELOG.md that is not the build, line endings aside', () => {
		write('CHANGELOG.md', read('CHANGELOG.md').replace(/\n/g, '\r\n'));
		expect(check()).toEqual([]);
		write('CHANGELOG.md', `${read('CHANGELOG.md')}\n- A hand-written entry.\n`);
		expect(check()).toEqual([
			'C9 CHANGELOG.md differs from the build; run npm run release -- changelog build',
		]);
		rmSync(join(root, 'changelog/header.md'));
		expect(check()).toEqual(['C9 changelog/header.md does not exist']);
	});

	it('C10 refuses other files in changelog/', () => {
		write('changelog/notes.md', 'x');
		write('changelog/.markdownlint-cli2.cjs', 'module.exports = {};');
		expect(check()).toEqual([
			'C10 changelog/notes.md is neither a release file X.Y.Z.md nor header.md',
		]);
	});
});

describe('cut', () => {
	beforeEach(() => {
		setMetadata({ attention: true, attention_items: ['The firewall moves to nftables.'] });
		fragment('e40-move', 'type: changed\nattention: true\norder: 10', '- The firewall moves.');
		fragment('e40-fix', 'type: fixed', '- A fix.');
		fragment('e40-summary', 'type: summary', 'IPv6 in the firewall.');
	});

	it('writes the release file, deletes the fragments and sets the version', () => {
		const result = tool('cut', '--date', '2026-10-28');
		expect(result.code).toBe(0);
		expect(read('changelog/2.3.0.md')).toBe(
			'## 2.3.0 — 2026-10-28\n\nIPv6 in the firewall.\n\n### Changed\n\n- The firewall moves.\n\n### Fixed\n\n- A fix.\n',
		);
		expect(readdirSync(join(root, 'changelog/unreleased'))).toEqual(['.gitkeep']);
		expect(read('src/deb/arcpanel/control')).toContain('Version: 2.3.0\n');
		expect(read('install/arcpanel-install-debian.sh')).toContain("ARCPANEL_INSTALL_VER='2.3.0'");
		expect(JSON.parse(read('src/release.json'))).toMatchObject({
			version: '2.3.0',
			attention: true,
		});
		expect(read('CHANGELOG.md')).toContain('## 2.3.0 — 2026-10-28\n\nIPv6 in the firewall.');
		expect(result.output).toContain(
			'Fragments that need attention (1):\n  e40-move.md: - The firewall moves.',
		);
		expect(result.output).toContain('  - The firewall moves to nftables.');
		expect(result.output).toContain('git commit -m "chore(release): set version 2.3.0"');
		expect(result.output).toContain('git tag -a v2.3.0 -m "ArcPanel 2.3.0 (stable channel)"');
		expect(result.output).toContain('git push origin v2.3.x && git push origin v2.3.0');
		expect(check({ tag: 'v2.3.0' })).toEqual([]);
	});

	it('keeps a security release valid at its tag', () => {
		setMetadata({
			security: true,
			attention: true,
			attention_items: ['The firewall moves to nftables.'],
		});
		fragment('e40-cve', 'type: security', '- A security fix.');
		expect(tool('cut', '--date', '2026-10-28').code).toBe(0);
		expect(check({ tag: 'v2.3.0' })).toEqual([]);
	});

	it('C7 reads the release file at a release version', () => {
		expect(tool('cut', '--date', '2026-10-28').code).toBe(0);
		setMetadata({ security: true, attention: true, attention_items: ['Read this.'] });
		expect(check({ tag: 'v2.3.0' })).toEqual([
			'C7 src/release.json says security: true without a security fragment',
		]);
		setMetadata({ attention: true, attention_items: ['Read this.'] });
		write(
			'changelog/2.3.0.md',
			`${read('changelog/2.3.0.md')}\n### Security\n\n- A security fix.\n`,
		);
		rebuild();
		expect(check({ tag: 'v2.3.0' })).toEqual([
			'C7 changelog/2.3.0.md needs security: true in src/release.json',
		]);
	});

	it('cuts a stable release after a beta', () => {
		setVersions('2.3.0~beta.2');
		setMetadata({ version: '2.3.0~beta.2', attention: true, attention_items: ['Read this.'] });
		expect(tool('cut', '--date', '2026-10-28').code).toBe(0);
		expect(JSON.parse(read('src/release.json')).version).toBe('2.3.0');
	});

	it('refuses a line that fails its checks and changes nothing', () => {
		setMetadata();
		const result = tool('cut', '--date', '2026-10-28');
		expect(result.code).toBe(1);
		expect(result.output).toContain('C8 e40-move.md needs attention_items');
		expect(existsSync(join(root, 'changelog/2.3.0.md'))).toBe(false);
		expect(read('src/deb/arcpanel/control')).toContain('Version: 2.3.0~alpha');
	});

	it('refuses a line without fragments', () => {
		rmSync(join(root, 'changelog/unreleased'), { recursive: true });
		setMetadata();
		expect(tool('cut').output).toContain('nothing to release');
	});

	it('refuses a bad date', () => {
		expect(tool('cut', '--date', '28.10.2026').output).toContain('is not YYYY-MM-DD');
	});
});

describe('release notes', () => {
	beforeEach(() => {
		write(
			'changelog/2.2.2.md',
			'## 2.2.2 — 2026-10-20\n\nUpdates that stay on the line.\n\n### Fixed\n\n- A fix.\n',
		);
		fragment('e40-firewall', 'type: added', '- IPv6 in the firewall.');
		fragment('e40-fix', 'type: fixed', '- A fix of 2.3.');
	});

	it('prints a stable release from its file with a link to the changelog', () => {
		expect(releaseNotes(root, 'v2.2.2')).toBe(
			[
				'Updates that stay on the line.',
				'',
				'### Fixed',
				'',
				'- A fix.',
				'',
				'Full changelog: https://github.com/arc-panel-oss/arcpanel/blob/v2.2.2/CHANGELOG.md',
				'',
			].join('\n'),
		);
	});

	it('adds the attention items of the release', () => {
		write('changelog/2.3.0.md', '## 2.3.0 — 2026-10-28\n\n### Added\n\n- IPv6.\n');
		setVersions('2.3.0');
		setMetadata({ attention: true, attention_items: ['The firewall moves.', 'Check it.'] });
		expect(releaseNotes(root, 'v2.3.0')).toContain(
			'- IPv6.\n\n**Before you update:** The firewall moves. Check it.\n\nFull changelog:',
		);
	});

	it('prints a beta of a .0 from the fragments of the line', () => {
		const result = tool('changelog', 'notes', 'v2.3.0-beta.1');
		expect(result.code).toBe(0);
		expect(result.output).toBe(
			[
				'Changes since the 2.2 line:',
				'',
				'### Added',
				'',
				'- IPv6 in the firewall.',
				'',
				'### Fixed',
				'',
				'- A fix of 2.3.',
			].join('\n'),
		);
	});

	it('prints a release candidate of a patch since the previous patch', () => {
		expect(releaseNotes(root, 'v2.3.2-rc.1')).toMatch(/^Changes since 2\.3\.1:\n\n### Added/);
		expect(releaseNotes(root, 'v3.0.0-rc.1')).toMatch(/^Changes since the 2\.x line:/);
	});

	it('refuses a tag without a release file or of another form', () => {
		expect(tool('changelog', 'notes', 'v2.3.0').output).toContain(
			'changelog/2.3.0.md does not exist',
		);
		expect(tool('changelog', 'notes', 'v2.3.0-alpha').output).toContain('is not vX.Y.Z');
		expect(tool('changelog', 'notes').code).toBe(1);
	});
});
