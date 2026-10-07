// @vitest-environment node
// The OpenAPI generator of the REST API (docs/features/specs/2026-10-07-001-e29-openapi-design.md).
import { describe, expect, it } from 'vitest';
import {
	buildDocument,
	buildOperation,
	parseHeader,
	parsePermissionSet,
	tagFor,
	toYaml,
	userArgPosition,
	validateDocument,
} from '../../../scripts/build-openapi.js';

const shellHeader = [
	'#!/bin/bash',
	'# info: add web domain',
	'# options: USER DOMAIN [IP] [RESTART] [ALIASES]',
	'# labels: web',
	'#',
	'# example: v-add-web-domain admin "my site.com" 192.18.22.43 yes www.my-site.com',
	'# example: v-add-web-domain admin other.com',
	'#',
	'# This function adds virtual host to a server.',
	'# The alias of www.domain.tld is added.',
	'',
	'#----------------------------------------------------------#',
	'#                Variables & Functions                     #',
	'user=$1',
].join('\n');

const phpHeader = [
	'#!/usr/local/arcpanel/php/bin/php',
	'<?php',
	'//# info: list the Quick Install applications',
	'//# options: [FORMAT]',
	'//#',
	'//# example: v-list-quick-install-apps json',
	'//#',
	'//# This function lists the installers.',
	'',
	'require_once __DIR__;',
].join('\n');

function command(name, text, extra = {}) {
	return { name, header: parseHeader(text), userArg: userArgPosition(text), ...extra };
}

describe('parseHeader', () => {
	it('reads the summary, options, first example and description of a shell command', () => {
		const header = parseHeader(shellHeader);

		expect(header.summary).toBe('Add web domain');
		expect(header.options).toEqual([
			{ name: 'USER', required: true, variadic: false },
			{ name: 'DOMAIN', required: true, variadic: false },
			{ name: 'IP', required: false, variadic: false },
			{ name: 'RESTART', required: false, variadic: false },
			{ name: 'ALIASES', required: false, variadic: false },
		]);
		expect(header.example).toEqual(['admin', 'my site.com', '192.18.22.43', 'yes', 'www.my-site.com']);
		expect(header.description).toBe('This function adds virtual host to a server.\nThe alias of www.domain.tld is added.');
	});

	it('reads the //# prefix of PHP commands', () => {
		const header = parseHeader(phpHeader);

		expect(header.summary).toBe('List the Quick Install applications');
		expect(header.options).toEqual([{ name: 'FORMAT', required: false, variadic: false }]);
		expect(header.example).toEqual(['json']);
		expect(header.description).toBe('This function lists the installers.');
	});

	it('accepts CRLF line endings', () => {
		expect(parseHeader(shellHeader.replaceAll('\n', '\r\n'))).toEqual(parseHeader(shellHeader));
	});

	it('treats NONE, a missing options line and a trailing # as no arguments', () => {
		expect(parseHeader('#!/bin/bash\n# info: x\n# options: NONE\n').options).toEqual([]);
		expect(parseHeader('#!/bin/bash\n# info: x\n# options: none\n').options).toEqual([]);
		expect(parseHeader('#!/bin/bash\n# info: x\n').options).toEqual([]);
		expect(parseHeader('#!/bin/bash\n# info: x\n# options: USER DATA #\n').options.map((o) => o.name)).toEqual([
			'USER',
			'DATA',
		]);
	});

	it('keeps variadic options and bracketed groups as one option', () => {
		const header = parseHeader('#!/bin/bash\n# info: x\n# options: action [user] [options ...] [PATH...] [files|db|both]\n');

		expect(header.options).toEqual([
			{ name: 'action', required: true, variadic: false },
			{ name: 'user', required: false, variadic: false },
			{ name: 'options', required: false, variadic: true },
			{ name: 'PATH', required: false, variadic: true },
			{ name: 'files|db|both', required: false, variadic: false },
		]);
	});

	it('returns an empty summary when the info line is missing', () => {
		expect(parseHeader('#!/bin/bash\n# options: USER\n').summary).toBe('');
	});
});

describe('userArgPosition', () => {
	it('finds USER the way the API does, case-insensitive and without brackets', () => {
		expect(userArgPosition('#!/bin/bash\n# options: ID USER [FORMAT]\n')).toBe(2);
		expect(userArgPosition('#!/bin/bash\n# options: action [user] [domain]\n')).toBe(2);
		expect(userArgPosition('#!/bin/bash\n# options: [FORMAT]\n')).toBe(0);
	});

	it('ignores the //# header of PHP commands, as the API does', () => {
		expect(userArgPosition('<?php\n//# options: USER DOMAIN\n')).toBe(0);
	});
});

describe('parsePermissionSet', () => {
	it('reads ROLE and COMMANDS and drops duplicates', () => {
		expect(parsePermissionSet("ROLE='user'\nCOMMANDS='v-list-web-domains,v-purge-nginx-cache,v-list-web-domains'\n")).toEqual({
			role: 'user',
			commands: ['v-list-web-domains', 'v-purge-nginx-cache'],
		});
	});
});

describe('tagFor', () => {
	it('maps the object word after the verb to a tag', () => {
		expect(tagFor('v-add-web-domain')).toBe('Web');
		expect(tagFor('v-list-users')).toBe('Users');
		expect(tagFor('v-list-databases')).toBe('Databases');
		expect(tagFor('v-list-sys-config')).toBe('System');
	});

	it('throws on an object word that the map does not know', () => {
		expect(() => tagFor('v-add-unicorn-horn')).toThrow(/unicorn/);
	});
});

describe('buildOperation', () => {
	const permissionSets = { 'purge-nginx-cache': { role: 'user', commands: ['v-add-web-domain'] } };

	it('describes one command as a POST on its pseudo-path', () => {
		const { path, operation, schema } = buildOperation(command('v-add-web-domain', shellHeader), { permissionSets });

		expect(path).toBe('/api/#v-add-web-domain');
		expect(operation.operationId).toBe('v-add-web-domain');
		expect(operation.tags).toEqual(['Web']);
		expect(operation.summary).toBe('Add web domain');
		expect(operation['x-arcpanel-permission-sets']).toEqual(['purge-nginx-cache']);
		expect(operation['x-arcpanel-user-arg']).toBe(1);
		expect(operation.description).toContain('`arg1`');
		const ref = { $ref: '#/components/schemas/v-add-web-domain' };
		expect(operation.requestBody.content['application/x-www-form-urlencoded'].schema).toEqual(ref);
		expect(operation.requestBody.content['application/json'].schema).toEqual(ref);

		const args = schema.allOf[1];
		expect(args.required).toEqual(['cmd', 'arg1', 'arg2']);
		expect(args.properties.cmd.enum).toEqual(['v-add-web-domain']);
		expect(args.properties.arg1.title).toBe('USER');
		expect(args.properties.arg2.examples).toEqual(['my site.com']);
		expect(args.properties.arg4.enum).toEqual(['yes', 'no']);
		expect(Object.keys(args.properties)).not.toContain('arg6');
	});

	it('gives [FORMAT] its values and json as the example', () => {
		const { schema } = buildOperation(command('v-list-quick-install-apps', phpHeader), { permissionSets: {} });

		expect(schema.allOf[1].properties.arg1.enum).toEqual(['json', 'plain', 'csv', 'shell']);
		expect(schema.allOf[1].properties.arg1.examples).toEqual(['json']);
	});

	it('repeats a variadic option up to arg13', () => {
		const text = '#!/bin/bash\n# info: zip\n# options: USER ARCHIVE PATH [PATH...]\n';
		const { schema } = buildOperation(command('v-add-fs-zip', text), { permissionSets: {} });
		const names = Object.keys(schema.allOf[1].properties).filter((key) => key.startsWith('arg'));

		expect(names).toHaveLength(13);
		expect(schema.allOf[1].properties.arg13.title).toBe('PATH');
		expect(schema.allOf[1].properties.arg13.examples).toEqual(['']);
		expect(schema.allOf[1].required).toEqual(['cmd', 'arg1', 'arg2', 'arg3']);
	});

	it('adds the fixtures as JSON response examples', () => {
		const fixtures = { bob: { 'example.com': { IP: '10.0.0.10' } } };
		const { operation } = buildOperation(command('v-list-quick-install-apps', phpHeader), { permissionSets: {}, fixtures });
		const json = operation.responses['200'].content['application/json'];

		expect(json.examples.bob.value).toEqual(fixtures.bob);
	});

	it('leaves out x-arcpanel-user-arg when the API does not check a user argument', () => {
		const { operation } = buildOperation(command('v-list-quick-install-apps', phpHeader), { permissionSets: {} });

		expect(operation).not.toHaveProperty('x-arcpanel-user-arg');
	});
});

describe('buildDocument and validateDocument', () => {
	const commands = [command('v-add-web-domain', shellHeader), command('v-list-quick-install-apps', phpHeader)];
	const permissionSets = { 'purge-nginx-cache': { role: 'user', commands: ['v-add-web-domain'] } };

	it('builds a valid OpenAPI 3.1 document', () => {
		const doc = buildDocument({ commands, permissionSets, fixtures: {}, version: '2.0.0' });

		expect(doc.openapi).toBe('3.1.0');
		expect(doc.info.version).toBe('2.0.0');
		expect(Object.keys(doc.paths)).toEqual(['/api/#v-add-web-domain', '/api/#v-list-quick-install-apps']);
		expect(doc.tags.map((tag) => tag.name)).toEqual(['Web', 'Quick Install']);
		expect(doc.info.description).toContain('purge-nginx-cache');
		expect(doc.components.schemas.Credentials.properties.secret_key.examples).toEqual(['']);
		expect(validateDocument(doc, { commands, permissionSets })).toEqual([]);
	});

	it('reports a reference that does not resolve', () => {
		const doc = buildDocument({ commands, permissionSets, fixtures: {}, version: '2.0.0' });
		doc.paths['/api/#v-add-web-domain'].post.responses['401'] = { $ref: '#/components/responses/Nope' };

		expect(validateDocument(doc, { commands, permissionSets })).toEqual([
			'unresolved reference #/components/responses/Nope',
		]);
	});

	it('reports a command without info line and a permission set naming an unknown command', () => {
		const broken = [...commands, command('v-add-web-thing', '#!/bin/bash\n# options: USER\n')];
		const sets = { billing: { role: 'admin', commands: ['v-add-web-domain', 'v-no-such-command'] } };
		const doc = buildDocument({ commands: broken, permissionSets: sets, fixtures: {}, version: '2.0.0' });

		expect(validateDocument(doc, { commands: broken, permissionSets: sets })).toEqual([
			'v-add-web-thing: no "info:" line in the header',
			'permission set billing: unknown command v-no-such-command',
		]);
	});
});

describe('toYaml', () => {
	it('writes block YAML and quotes scalars that would change type or meaning', () => {
		const yaml = toYaml({
			plain: 'Add web domain',
			numberish: '8083',
			boolish: 'yes',
			colon: 'a: b',
			hash: 'x #y',
			dash: '- item',
			empty: '',
			number: 3,
			flag: true,
			list: ['one', { two: 2 }],
			emptyList: [],
			emptyMap: {},
			multi: 'line one\nline two',
			'/api/#v-add-user': { $ref: '#/x' },
		});

		expect(yaml).toBe(
			[
				'plain: Add web domain',
				'numberish: "8083"',
				'boolish: "yes"',
				'colon: "a: b"',
				'hash: "x #y"',
				'dash: "- item"',
				'empty: ""',
				'number: 3',
				'flag: true',
				'list:',
				'  - one',
				'  - two: 2',
				'emptyList: []',
				'emptyMap: {}',
				'multi: |-',
				'  line one',
				'  line two',
				'"/api/#v-add-user":',
				'  $ref: "#/x"',
				'',
			].join('\n'),
		);
	});
});
