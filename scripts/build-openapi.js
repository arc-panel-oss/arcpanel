// Generates the OpenAPI 3.1 document of the REST API at /api/ (lib/api/index.php)
// from the headers of the v-* commands, the permission sets and the panel's
// command fixtures (docs/features/specs/2026-10-07-001-e29-openapi-design.md).
// Writes docs/api/openapi.yaml; with --check, exits non-zero when that file is
// missing, stale or invalid.
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const binDir = path.join(root, 'bin');
const setsDir = path.join(root, 'install/common/api');
const fixturesDir = path.join(root, 'web-app/tests/Fixtures/commands');
const outputFile = path.join(root, 'docs/api/openapi.yaml');

// The API passes at most 13 arguments (lib/api/index.php)
const maxArgs = 13;

// Tags in display order, with the object words (the word after the verb) they hold
const tags = [
	{
		name: 'Users',
		words: ['user', 'users'],
		description: 'System users, their packages, notifications and logs',
	},
	{
		name: 'Resellers',
		words: ['reseller'],
		description: 'Resellers and the trees of users they own (E11)',
	},
	{
		name: 'Packages',
		words: ['package'],
		description: 'Tenancy settings of hosting packages (E11)',
	},
	{
		name: 'Access keys',
		words: ['access', 'api', 'apis'],
		description: 'API access keys, legacy API keys and permission sets',
	},
	{
		name: 'Web',
		words: ['web', 'domain', 'proxy', 'nginx', 'fastcgi', 'site'],
		description: 'Web domains, templates, caches and proxies',
	},
	{
		name: "Let's Encrypt",
		words: ['letsencrypt', 'ssl', 'host'],
		description: 'Certificates for domains, mail and the panel host',
	},
	{
		name: 'DNS',
		words: ['dns', 'dnssec', 'remote'],
		description: 'DNS domains, records, DNSSEC and the DNS cluster',
	},
	{
		name: 'Mail',
		words: ['mail'],
		description: 'Mail domains, accounts, DKIM and the mail server',
	},
	{
		name: 'Databases',
		words: ['database', 'databases'],
		description: 'Databases, database users and database hosts',
	},
	{ name: 'Cron', words: ['cron'], description: 'Cron jobs and the cron service' },
	{ name: 'Backups', words: ['backup', 'file'], description: 'Backup hosts and restic snapshots' },
	{ name: 'Files', words: ['fs'], description: 'File operations run as the user (E3)' },
	{
		name: 'Firewall',
		words: ['firewall'],
		description: 'Firewall rules, chains, bans and IP lists',
	},
	{
		name: 'Node.js',
		words: ['node'],
		description: 'Node.js runtimes, global packages and apps (E13, E14)',
	},
	{
		name: 'App processes',
		words: ['app'],
		description: 'Long-running commands of web domains run as the user by systemd (E42)',
	},
	{
		name: 'Docker',
		words: ['docker'],
		description: 'Docker apps from the catalog, run as the user (E4)',
	},
	{
		name: 'Quick Install',
		words: ['quick', 'install'],
		description: 'Quick Install web applications',
	},
	{
		name: 'System',
		words: [
			'sys',
			'system',
			'service',
			'ftp',
			'rrd',
			'all',
			'white',
			'action',
			'default',
			'cli',
			'command',
			'object',
			'password',
			'queue',
		],
		description: 'Server configuration, services, packages, updates, logs and searches',
	},
	{
		name: 'Other',
		words: ['tmp', 'cpanel', 'directadmin'],
		description: 'Imports from other panels and API helpers',
	},
];

// Commands the API runs itself, without a script in bin/ (lib/api/index.php)
const builtinCommands = [
	{
		name: 'v-make-tmp-file',
		text: [
			'# info: write a temporary file',
			'# options: CONTENT FILE_NAME',
			'#',
			'# example: v-make-tmp-file "@ IN A 192.0.2.10" dns-records.tmp',
			'#',
			'# Built into the API script, not a command in bin/: writes CONTENT and a',
			'# newline to /tmp/FILE_NAME (the base name only). The DNS cluster uses it',
			'# to hand data files to the commands it runs next.',
		].join('\n'),
	},
];

// Exit codes of func/main.sh and lib/api/helpers.php, with the HTTP status of exit_code_to_http_code()
const exitCodes = [
	[0, 'OK', '200, or 204 without output'],
	[1, 'E_ARGS', '400'],
	[2, 'E_INVALID', '422'],
	[3, 'E_NOTEXIST', '400'],
	[4, 'E_EXISTS', '400'],
	[5, 'E_SUSPENDED', '401'],
	[6, 'E_UNSUSPENDED', '401'],
	[7, 'E_INUSE', '400'],
	[8, 'E_LIMIT', '400'],
	[9, 'E_PASSWORD', '401'],
	[10, 'E_FORBIDDEN', '401'],
	[11, 'E_DISABLED', '400'],
	[12, 'E_PARSING', '400'],
	[13, 'E_DISK', '400'],
	[14, 'E_LA', '400'],
	[15, 'E_CONNECT', '400'],
	[16, 'E_FTP', '400'],
	[17, 'E_DB', '400'],
	[18, 'E_RRD', '400'],
	[19, 'E_UPDATE', '400'],
	[20, 'E_RESTART', '400'],
	[21, 'E_API_DISABLED', '401'],
];

const argEnums = { FORMAT: ['json', 'plain', 'csv', 'shell'], RESTART: ['yes', 'no'] };

/**
 * Split a command line the way a shell would, honouring single and double quotes.
 *
 * @param {string} line
 * @returns {string[]}
 */
function splitWords(line) {
	const words = [];
	const pattern = /"((?:[^"\\]|\\.)*)"|'([^']*)'|(\S+)/g;
	for (const match of line.matchAll(pattern)) {
		words.push(match[1] !== undefined ? match[1].replace(/\\(.)/g, '$1') : (match[2] ?? match[3]));
	}
	return words;
}

/**
 * The options of an "options:" line: NAME is required, [NAME] optional, NAME... repeats.
 *
 * @param {string} text
 * @returns {{name: string, required: boolean, variadic: boolean}[]}
 */
function parseOptions(text) {
	const tokens = text.split(/\s+/).filter((token) => token !== '' && token !== '#');
	if (tokens.length === 1 && /^none$/i.test(tokens[0])) {
		return [];
	}
	const groups = [];
	for (let i = 0; i < tokens.length; i++) {
		let token = tokens[i];
		while (token.startsWith('[') && !token.endsWith(']') && i + 1 < tokens.length) {
			token += ` ${tokens[++i]}`;
		}
		groups.push(token);
	}
	return groups.map((group) => {
		const required = !group.startsWith('[');
		let name = group.replace(/^\[|\]$/g, '').trim();
		const variadic = name.endsWith('...');
		if (variadic) {
			name = name.slice(0, -3).trim();
		}
		return { name, required, variadic };
	});
}

/**
 * The header of a command: the comment lines at the top, prefixed "# " (shell) or "//# " (PHP).
 *
 * @param {string} text
 * @returns {{summary: string, options: {name: string, required: boolean, variadic: boolean}[], example: string[]|null, description: string}}
 */
export function parseHeader(text) {
	const lines = text.replace(/\r\n?/g, '\n').split('\n');
	let start = 0;
	while (
		start < lines.length &&
		(lines[start].startsWith('#!') || lines[start].startsWith('<?php'))
	) {
		start++;
	}
	const header = { summary: '', options: [], example: null, description: '' };
	const description = [];
	for (const line of lines.slice(start)) {
		const comment = line.match(/^(?:\/\/)?#(?: (.*)|$)/);
		if (!comment) {
			break;
		}
		const content = (comment[1] ?? '').trimEnd();
		if (/^-{3,}/.test(content)) {
			break;
		}
		const field = content.match(/^(info|options|example|labels):\s*(.*)$/);
		if (!field) {
			description.push(content.trim());
		} else if (field[1] === 'info') {
			header.summary = field[2].charAt(0).toUpperCase() + field[2].slice(1);
		} else if (field[1] === 'options') {
			header.options = parseOptions(field[2]);
		} else if (field[1] === 'example' && header.example === null) {
			const words = splitWords(field[2]);
			header.example = words[0]?.startsWith('v-') ? words.slice(1) : null;
		}
	}
	header.description = description.join('\n').trim();
	return header;
}

/**
 * Position of the USER argument as the API computes it (search_command_arg_position in
 * func/main.sh): words of the "# options:" lines, brackets stripped, case-insensitive, last match.
 * PHP commands ("//# options:") answer 0, so the API checks no user argument for them.
 *
 * @param {string} text
 * @returns {number}
 */
export function userArgPosition(text) {
	let position = 0;
	let count = 0;
	for (const line of text.replace(/\r\n?/g, '\n').split('\n')) {
		const options = line.match(/^# options: (.+)/);
		if (!options) {
			continue;
		}
		for (const word of options[1].split(' ').filter((item) => item !== '')) {
			count++;
			if (word.replace(/^[\s[]*|[\s\]]*$/g, '').toUpperCase() === 'USER') {
				position = count;
			}
		}
	}
	return position;
}

/**
 * A permission set file of install/common/api/: ROLE, the comma-separated COMMANDS and
 * API_VERSION ('2' for the sets of REST API v2, which this API refuses; '1' when absent).
 *
 * @param {string} text
 * @returns {{role: string, commands: string[], apiVersion: string}}
 */
export function parsePermissionSet(text) {
	const value = (key) => text.match(new RegExp(`^${key}='([^']*)'`, 'm'))?.[1] ?? '';
	const commands = value('COMMANDS')
		.split(',')
		.map((item) => item.trim())
		.filter((item) => item !== '');
	return {
		role: value('ROLE'),
		commands: [...new Set(commands)],
		apiVersion: value('API_VERSION') || '1',
	};
}

/**
 * The tag of a command, from the object word after its verb.
 *
 * @param {string} name
 * @returns {string}
 */
export function tagFor(name) {
	const word = name.split('-')[2] ?? '';
	const tag = tags.find((item) => item.words.includes(word));
	if (!tag) {
		throw new Error(`${name}: object word "${word}" has no tag in scripts/build-openapi.js`);
	}
	return tag.name;
}

/**
 * The argument fields of a command, arg1…arg13, one per option (a repeating option fills the rest).
 *
 * @param {{name: string, required: boolean, variadic: boolean}[]} options
 * @returns {{field: string, name: string, required: boolean, repeat: boolean}[]}
 */
function argFields(options) {
	const fields = [];
	for (const option of options) {
		const copies = option.variadic ? maxArgs - fields.length : 1;
		for (let i = 0; i < copies && fields.length < maxArgs; i++) {
			fields.push({
				field: `arg${fields.length + 1}`,
				name: option.name,
				required: option.required && !option.variadic,
				repeat: option.variadic,
			});
		}
	}
	return fields;
}

/**
 * One command as an operation on its pseudo-path, with the schema of its request body.
 *
 * @param {{name: string, header: ReturnType<typeof parseHeader>, userArg: number}} command
 * @param {{permissionSets: Record<string, {role: string, commands: string[]}>, fixtures?: Record<string, unknown>}} context
 * @returns {{path: string, operation: object, schema: object}}
 */
export function buildOperation(command, { permissionSets, fixtures = {} }) {
	const { name, header, userArg } = command;
	const fields = argFields(header.options);
	const sets = Object.keys(permissionSets)
		.filter((set) => permissionSets[set].commands.includes(name))
		.sort();

	const properties = { cmd: { type: 'string', enum: [name], default: name } };
	for (const [index, field] of fields.entries()) {
		const property = {
			type: 'string',
			title: field.name,
			description: `\`${field.name}\`${field.repeat ? ', repeatable' : ''}${field.required ? '' : ', optional'}`,
		};
		const enumValues = argEnums[field.name.toUpperCase()];
		if (enumValues) {
			property.enum = enumValues;
		}
		const example = field.name.toUpperCase() === 'FORMAT' ? 'json' : header.example?.[index];
		// An empty example keeps Swagger UI from filling the field with "string"
		if (!enumValues || example !== undefined) {
			property.examples = [example ?? ''];
		}
		properties[field.field] = property;
	}
	properties.returncode = { $ref: '#/components/schemas/ReturnCode' };
	const schema = {
		allOf: [
			{ $ref: '#/components/schemas/Credentials' },
			{
				type: 'object',
				required: ['cmd', ...fields.filter((field) => field.required).map((field) => field.field)],
				properties,
			},
		],
	};

	const parts = [];
	if (header.description !== '') {
		parts.push(header.description);
	}
	if (fields.length === 0) {
		parts.push('**Arguments:** none.');
	} else {
		const rows = fields
			.filter((field, index) => !field.repeat || fields.findIndex((item) => item.repeat) === index)
			.map((field) => {
				const label = field.repeat ? `\`${field.field}\`…\`arg${maxArgs}\`` : `\`${field.field}\``;
				return `| ${label} | \`${field.name}\` | ${field.required ? 'yes' : 'no'} |`;
			});
		parts.push(
			['**Arguments**', '', '| Field | Option | Required |', '| --- | --- | --- |', ...rows].join(
				'\n',
			),
		);
	}
	if (userArg > 0) {
		parts.push(
			`**User argument:** with the access key of a user other than the admin, \`arg${userArg}\` must be the key's user.`,
		);
	}
	if (sets.length > 0) {
		parts.push(`**Permission sets:** ${sets.map((set) => `\`${set}\``).join(', ')}.`);
	}

	const json = {};
	const text = {};
	for (const [label, value] of Object.entries(fixtures)) {
		(typeof value === 'string' ? text : json)[label] = { value };
	}
	const content = { 'text/plain': { schema: { type: 'string' } } };
	if (Object.keys(text).length > 0) {
		content['text/plain'].examples = text;
	}
	const listsJson = fields.some((field) => field.name.toUpperCase() === 'FORMAT');
	if (listsJson || Object.keys(json).length > 0) {
		content['application/json'] = { schema: { type: 'object' } };
		if (Object.keys(json).length > 0) {
			content['application/json'].examples = json;
		}
	}

	const ref = { $ref: `#/components/schemas/${name}` };
	const operation = {
		operationId: name,
		tags: [tagFor(name)],
		summary: header.summary || name,
		description: parts.join('\n\n'),
	};
	if (sets.length > 0) {
		operation['x-arcpanel-permission-sets'] = sets;
	}
	if (userArg > 0) {
		operation['x-arcpanel-user-arg'] = userArg;
	}
	operation.requestBody = {
		required: true,
		content: {
			'application/x-www-form-urlencoded': { schema: ref },
			'application/json': { schema: ref },
		},
	};
	operation.responses = {
		200: {
			description:
				'The output of the command; `OK` for an empty output with legacy authentication.',
			headers: { 'ArcPanel-Exit-Code': { $ref: '#/components/headers/ArcPanel-Exit-Code' } },
			content,
		},
		204: { $ref: '#/components/responses/NoContent' },
		400: { $ref: '#/components/responses/BadRequest' },
		401: { $ref: '#/components/responses/Unauthorized' },
		405: { $ref: '#/components/responses/InvalidRequest' },
		422: { $ref: '#/components/responses/Unprocessable' },
	};
	return { path: `/api/#${name}`, operation, schema };
}

/**
 * The info.description of the document: how the API works, exit codes and permission sets.
 *
 * @param {Record<string, {role: string, commands: string[]}>} permissionSets the sets of this API
 * @param {string[]} v2Sets names of the sets of REST API v2
 * @returns {string}
 */
function overview(permissionSets, v2Sets) {
	const sets = Object.keys(permissionSets)
		.sort()
		.map((set) => {
			const { role, commands } = permissionSets[set];
			return `| \`${set}\` | ${role} | ${commands.map((item) => `\`${item}\``).join(', ')} |`;
		});
	const codes = exitCodes.map(([code, name, http]) => `| ${code} | \`${name}\` | ${http} |`);
	return [
		'The REST API runs the `v-*` commands of ArcPanel, the same commands that the panel and the command line use. ' +
			'Every state change and every validation happens in the command; the API only authenticates the caller and passes the arguments on.',
		'**Every call runs a real command on the server, as root.** Use a test server to try the API.',
		'## One endpoint',
		'All calls are `POST /api/` with a form (`application/x-www-form-urlencoded`) or JSON body. ' +
			'The field `cmd` names the command, `arg1`…`arg13` are its arguments in the order of the command header, ' +
			'and `returncode=yes` answers with the exit code instead of the output.',
		'This document describes each command as its own operation on the path `/api/#<command>`, so that each one has its own form. ' +
			'The part after `#` is a URL fragment and is never sent: a client sends every operation to `/api/`. ' +
			'The document is generated from the command headers by `scripts/build-openapi.js`; do not edit it by hand.',
		'## Enabling the API',
		'- `API_SYSTEM` in `arcpanel.conf`: `0` off, `1` access keys of the admin only, `2` access keys of every user.\n' +
			'- `API`: `yes` enables the legacy authentication.\n' +
			'- `API_ALLOWED_IP`: `allow-all` or a comma-separated list of the client addresses allowed to connect.',
		'## Authentication',
		'Credentials travel in the request body, next to `cmd` (the `Credentials` schema):\n\n' +
			'1. **Access key** (recommended): `access_key` and `secret_key`, created with `v-add-access-key`.\n' +
			'2. **Access key in one field:** `hash` set to `ACCESS_KEY:SECRET_KEY`.\n' +
			'3. **Legacy, deprecated:** `user` (the admin user) and `password`, or `hash` set to a legacy API key of `v-generate-api-key`.',
		'An access key of the admin without permission sets may run every command. Any other key runs only the commands of its permission sets ' +
			"(`PERMISSIONS` of the key). For the key of a user other than the admin, the user argument of the command must be the key's user " +
			'(`x-arcpanel-user-arg` names its position; commands written in PHP have none).',
		'## Responses',
		'With an access key, a command that succeeds answers `200` with its output (`application/json` when the output is JSON) or `204` without output; ' +
			'a command that fails answers the HTTP status of its exit code, with the error message as `text/plain`. ' +
			'The header `ArcPanel-Exit-Code` carries the exit code. ' +
			'With legacy authentication only authentication errors change the status; the output of a failed command comes back with `200`.',
		['| Exit code | Name | HTTP status |', '| --- | --- | --- |', ...codes].join('\n'),
		'## Permission sets',
		'Permission sets are files in `$ARCPANEL/data/api/`, installed from `install/common/api/`. Each operation lists the sets that include it (`x-arcpanel-permission-sets`).',
		['| Set | Role | Commands |', '| --- | --- | --- |', ...sets].join('\n'),
		...(v2Sets.length > 0
			? [
					`The sets ${v2Sets.map((set) => `\`${set}\``).join(' and ')} belong to REST API v2 (\`/api/v2/\`, documented in \`docs/api/v2/openapi.json\`): ` +
						'this API ignores them, so a key with only those sets may run no command here.',
				]
			: []),
	].join('\n\n');
}

/**
 * The OpenAPI document of all commands.
 *
 * @param {{commands: {name: string, header: ReturnType<typeof parseHeader>, userArg: number}[], permissionSets: Record<string, {role: string, commands: string[]}>, fixtures: Record<string, Record<string, unknown>>, version: string}} input
 * @returns {object}
 */
export function buildDocument({ commands, permissionSets: allSets, fixtures, version }) {
	// The sets of REST API v2 are refused by this API (get_apis_commands of func/main.sh)
	const permissionSets = Object.fromEntries(
		Object.entries(allSets).filter(([, set]) => set.apiVersion !== '2'),
	);
	const v2Sets = Object.keys(allSets)
		.filter((name) => !(name in permissionSets))
		.sort();
	const sorted = [...commands].sort((a, b) => a.name.localeCompare(b.name));
	const paths = {};
	const schemas = {};
	for (const command of sorted) {
		const {
			path: key,
			operation,
			schema,
		} = buildOperation(command, { permissionSets, fixtures: fixtures[command.name] });
		paths[key] = { post: operation };
		schemas[command.name] = schema;
	}
	const used = new Set(Object.values(paths).map((item) => item.post.tags[0]));

	const errorResponse = (description) => ({
		description,
		headers: { 'ArcPanel-Exit-Code': { $ref: '#/components/headers/ArcPanel-Exit-Code' } },
		content: { 'text/plain': { schema: { type: 'string' } } },
	});

	// An empty example keeps Swagger UI from filling the credentials with "string"
	const credential = (property) => ({ type: 'string', ...property, examples: [''] });

	return {
		openapi: '3.1.0',
		info: {
			title: 'ArcPanel REST API',
			version,
			description: overview(permissionSets, v2Sets),
			license: { name: 'GPL-3.0-or-later', identifier: 'GPL-3.0-or-later' },
		},
		servers: [
			{
				url: 'https://{host}:{port}',
				description: 'An ArcPanel server',
				variables: {
					host: { default: 'panel.example.com', description: 'Host name of the server' },
					port: { default: '8083', description: 'Port of the panel' },
				},
			},
		],
		tags: tags
			.filter((tag) => used.has(tag.name))
			.map((tag) => ({ name: tag.name, description: tag.description })),
		paths,
		components: {
			schemas: {
				Credentials: {
					type: 'object',
					description:
						'Send `access_key` and `secret_key`, or one of the other forms of the overview.',
					properties: {
						access_key: credential({ description: 'ID of the access key (20 characters).' }),
						secret_key: credential({
							format: 'password',
							description: 'Secret of the access key (40 characters).',
						}),
						hash: credential({
							format: 'password',
							description:
								'`ACCESS_KEY:SECRET_KEY` in one field, or a legacy API key (deprecated).',
						}),
						user: credential({
							deprecated: true,
							description: 'Legacy authentication: the admin user.',
						}),
						password: credential({
							format: 'password',
							deprecated: true,
							description: 'Legacy authentication: the password of the admin user.',
						}),
					},
				},
				ReturnCode: {
					type: 'string',
					enum: ['no', 'yes'],
					default: 'no',
					description: '`yes` answers with the exit code of the command instead of its output.',
				},
				...schemas,
			},
			headers: {
				'ArcPanel-Exit-Code': {
					description: 'Exit code of the command or of the API (see the table of the overview).',
					schema: { type: 'integer' },
				},
			},
			responses: {
				NoContent: {
					description: 'The command succeeded and printed nothing (access-key authentication).',
				},
				BadRequest: errorResponse('The command failed, or the API is disabled.'),
				Unauthorized: errorResponse(
					'Authentication failed, the key may not run the command, or the user is suspended.',
				),
				InvalidRequest: errorResponse('The request body is empty or has no credentials.'),
				Unprocessable: errorResponse('The command or an argument is invalid.'),
			},
		},
	};
}

/**
 * Problems of the generated document: commands without a summary, permission sets that
 * name an unknown command, duplicate operation ids and references that do not resolve.
 *
 * @param {object} doc
 * @param {{commands: {name: string, header: {summary: string}}[], permissionSets: Record<string, {commands: string[]}>}} input
 * @returns {string[]}
 */
export function validateDocument(doc, { commands, permissionSets }) {
	const problems = [];
	const names = new Set(commands.map((command) => command.name));
	for (const command of commands) {
		if (command.header.summary === '') {
			problems.push(`${command.name}: no "info:" line in the header`);
		}
	}
	for (const set of Object.keys(permissionSets).sort()) {
		for (const name of permissionSets[set].commands) {
			if (!names.has(name)) {
				problems.push(`permission set ${set}: unknown command ${name}`);
			}
		}
	}
	const ids = new Set();
	for (const item of Object.values(doc.paths)) {
		if (ids.has(item.post.operationId)) {
			problems.push(`duplicate operationId ${item.post.operationId}`);
		}
		ids.add(item.post.operationId);
	}
	const resolve = (ref) =>
		ref
			.slice(2)
			.split('/')
			.map((part) => part.replaceAll('~1', '/').replaceAll('~0', '~'))
			.reduce((node, part) => (node && Object.hasOwn(node, part) ? node[part] : undefined), doc);
	const unresolved = new Set();
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk);
		} else if (node !== null && typeof node === 'object') {
			if (typeof node.$ref === 'string' && resolve(node.$ref) === undefined) {
				unresolved.add(node.$ref);
			}
			Object.values(node).forEach(walk);
		}
	};
	walk(doc);
	for (const ref of unresolved) {
		problems.push(`unresolved reference ${ref}`);
	}
	return problems;
}

const reserved = /^(?:y|n|yes|no|on|off|true|false|null|~)$/i;

/**
 * A YAML scalar: plain when that is unambiguous, otherwise double-quoted (a JSON string is valid YAML).
 *
 * @param {unknown} value
 * @returns {string}
 */
function scalar(value) {
	if (value === null) {
		return 'null';
	}
	if (typeof value !== 'string') {
		return String(value);
	}
	const plain =
		/^[A-Za-z_/$][A-Za-z0-9_ .,/()'`=+@-]*$/.test(value) &&
		!value.endsWith(' ') &&
		!reserved.test(value);
	return plain ? value : JSON.stringify(value);
}

/**
 * Block-style YAML of plain data (objects, arrays, strings, numbers, booleans, null).
 *
 * @param {unknown} value
 * @param {number} [indent]
 * @returns {string}
 */
export function toYaml(value, indent = 0) {
	const pad = ' '.repeat(indent);
	const entry = (prefix, item) => {
		if (Array.isArray(item)) {
			return item.length === 0 ? `${prefix} []\n` : `${prefix}\n${toYaml(item, indent + 2)}`;
		}
		if (item !== null && typeof item === 'object') {
			return Object.keys(item).length === 0
				? `${prefix} {}\n`
				: `${prefix}\n${toYaml(item, indent + 2)}`;
		}
		if (
			typeof item === 'string' &&
			item.includes('\n') &&
			!item.startsWith(' ') &&
			!item.includes('\t')
		) {
			const lines = item
				.split('\n')
				.map((line) => (line === '' ? '' : `${pad}  ${line.trimEnd()}`));
			return `${prefix} |-\n${lines.join('\n')}\n`;
		}
		return `${prefix} ${scalar(item)}\n`;
	};
	if (Array.isArray(value)) {
		return value
			.map((item) => {
				if (
					item !== null &&
					typeof item === 'object' &&
					!Array.isArray(item) &&
					Object.keys(item).length > 0
				) {
					return `${pad}- ${toYaml(item, indent + 2).slice(indent + 2)}`;
				}
				return entry(`${pad}-`, item);
			})
			.join('');
	}
	return Object.entries(value)
		.map(([key, item]) => entry(`${pad}${scalar(key)}:`, item))
		.join('');
}

async function readCommands() {
	const commands = [];
	for (const name of (await fs.readdir(binDir)).filter((file) => file.startsWith('v-')).sort()) {
		const text = await fs.readFile(path.join(binDir, name), 'utf8');
		commands.push({ name, header: parseHeader(text), userArg: userArgPosition(text) });
	}
	for (const { name, text } of builtinCommands) {
		commands.push({ name, header: parseHeader(text), userArg: 0 });
	}
	return commands;
}

// Set files named apart from their set (E39 W4 D13): no repository path
// contains "secrets", so this set ships as v2-secret-store
const setFiles = { 'v2-secret-store': 'v2-secrets' };

async function readPermissionSets() {
	const sets = {};
	const files = Object.fromEntries(
		(await fs.readdir(setsDir)).map((file) => [setFiles[file] ?? file, file]),
	);
	for (const name of Object.keys(files).sort()) {
		sets[name] = parsePermissionSet(await fs.readFile(path.join(setsDir, files[name]), 'utf8'));
	}
	return sets;
}

async function readFixtures() {
	const fixtures = {};
	for (const command of await fs.readdir(fixturesDir)) {
		const dir = path.join(fixturesDir, command);
		for (const file of (await fs.readdir(dir)).sort()) {
			const content = (await fs.readFile(path.join(dir, file), 'utf8')).replace(/\r\n?/g, '\n');
			const label = path.basename(file, path.extname(file));
			fixtures[command] ??= {};
			if (file.endsWith('.json')) {
				fixtures[command][label] = JSON.parse(content);
			} else if (file.endsWith('.txt')) {
				fixtures[command][label] = content.trimEnd();
			}
		}
	}
	return fixtures;
}

/**
 * The inputs of buildDocument, read from the repository.
 *
 * @returns {Promise<Parameters<typeof buildDocument>[0]>}
 */
export async function collect() {
	const [commands, permissionSets, fixtures, pkg] = await Promise.all([
		readCommands(),
		readPermissionSets(),
		readFixtures(),
		fs.readFile(path.join(root, 'package.json'), 'utf8').then(JSON.parse),
	]);
	return { commands, permissionSets, fixtures, version: pkg.version };
}

/**
 * Build the document from the repository and format it with Prettier.
 *
 * @returns {Promise<{yaml: string, problems: string[]}>}
 */
export async function generate() {
	const input = await collect();
	let doc;
	try {
		doc = buildDocument(input);
	} catch (error) {
		return { yaml: '', problems: [error.message] };
	}
	const problems = validateDocument(doc, input);
	const prettier = await import('prettier');
	const options = await prettier.resolveConfig(outputFile, { editorconfig: true });
	const banner =
		'# Generated by scripts/build-openapi.js from the headers of bin/v-*. Do not edit.\n';
	const yaml = await prettier.format(banner + toYaml(doc), { ...options, filepath: outputFile });
	return { yaml, problems };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	const check = process.argv.includes('--check');
	const { yaml, problems } = await generate();
	if (problems.length === 0 && check) {
		const current = await fs.readFile(outputFile, 'utf8').catch(() => null);
		if (current === null) {
			problems.push('docs/api/openapi.yaml is missing: run npm run docs:api');
		} else if (current.replace(/\r\n?/g, '\n') !== yaml) {
			problems.push('docs/api/openapi.yaml is stale: run npm run docs:api');
		}
	}
	for (const problem of problems) {
		console.error(problem);
	}
	if (problems.length === 0 && !check) {
		await fs.mkdir(path.dirname(outputFile), { recursive: true });
		await fs.writeFile(outputFile, yaml);
		console.log(`docs/api/openapi.yaml: ${yaml.split('\n').length} lines`);
	}
	process.exit(problems.length > 0 ? 1 : 0);
}
