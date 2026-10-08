// Rules of the file manager that need no Vue (E3 spec §7): paths relative to
// the home directory, sizes, modes, selection, keyboard and file kinds.

/** Path segments of a relative path ("" is the home directory) */
export function segments(path) {
	return String(path ?? '')
		.split('/')
		.filter((part) => part !== '' && part !== '.');
}

export function joinPath(dir, name) {
	return [...segments(dir), ...segments(name)].join('/');
}

export function parentPath(path) {
	return segments(path).slice(0, -1).join('/');
}

export function baseName(path) {
	const parts = segments(path);
	return parts.length > 0 ? parts[parts.length - 1] : '';
}

/** Whether `inner` is `outer` or below it */
export function isInside(outer, inner) {
	const a = segments(outer);
	const b = segments(inner);
	return a.length <= b.length && a.every((part, index) => b[index] === part);
}

/**
 * Why a copy or move cannot go into DEST, or null: the folder is read-only
 * for the user (writable false; unknown counts as writable), a move into the
 * folder the entries are already in, or a folder into itself.
 *
 * @param {{dest: string, writable?: boolean, source: string, paths: string[], move: boolean}} pick
 * @returns {'read-only'|'same-folder'|'inside'|null}
 */
export function pickRefusal({ dest, writable, source, paths, move }) {
	if (writable === false) {
		return 'read-only';
	}
	if (move && dest === source) {
		return 'same-folder';
	}
	if (paths.some((p) => isInside(p, dest))) {
		return 'inside';
	}
	return null;
}

/**
 * Breadcrumb of a path: [{name, path}] from the home directory down.
 *
 * @param {string} path
 */
export function breadcrumb(path) {
	const crumbs = [];
	let current = '';
	for (const part of segments(path)) {
		current = current === '' ? part : `${current}/${part}`;
		crumbs.push({ name: part, path: current });
	}
	return crumbs;
}

/** A file name the commands accept: no slash, not . or .., no control character */
export function isValidName(name) {
	return (
		typeof name === 'string' &&
		name !== '' &&
		name !== '.' &&
		name !== '..' &&
		name.length <= 255 &&
		!name.includes('/') &&
		// biome-ignore lint/suspicious/noControlCharactersInRegex: control characters are what is refused
		!/[\u0000-\u001f\u007f]/.test(name)
	);
}

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

export function formatSize(bytes) {
	let value = Number(bytes) || 0;
	let unit = 0;
	while (value >= 1024 && unit < UNITS.length - 1) {
		value /= 1024;
		unit++;
	}
	return unit === 0 ? `${value} B` : `${value >= 10 ? Math.round(value) : Math.round(value * 10) / 10} ${UNITS[unit]}`;
}

/** "0755" → "rwxr-xr-x" (special bits shown as s, S, t, T) */
export function modeString(mode) {
	const value = Number.parseInt(String(mode), 8) || 0;
	const triple = (bits, special, char) => {
		const r = bits & 4 ? 'r' : '-';
		const w = bits & 2 ? 'w' : '-';
		let x = bits & 1 ? 'x' : '-';
		if (special) {
			x = bits & 1 ? char : char.toUpperCase();
		}
		return r + w + x;
	};
	return (
		triple((value >> 6) & 7, value & 0o4000, 's') +
		triple((value >> 3) & 7, value & 0o2000, 's') +
		triple(value & 7, value & 0o1000, 't')
	);
}

export function isValidMode(mode) {
	return /^[0-7]?[0-7]{3}$/.test(String(mode));
}

/** Octal mode → {owner: {r, w, x}, group: {…}, other: {…}} */
export function modeBits(mode) {
	const value = Number.parseInt(String(mode), 8) || 0;
	const bits = (shift) => ({ r: Boolean(value & (4 << shift)), w: Boolean(value & (2 << shift)), x: Boolean(value & (1 << shift)) });
	return { owner: bits(6), group: bits(3), other: bits(0) };
}

/** Back to four octal digits; the special bits of `previous` are kept */
export function bitsMode(bits, previous = '0') {
	const digit = (b) => (b.r ? 4 : 0) + (b.w ? 2 : 0) + (b.x ? 1 : 0);
	const special = (Number.parseInt(String(previous), 8) || 0) >> 9;
	return `${special}${digit(bits.owner)}${digit(bits.group)}${digit(bits.other)}`;
}

/** File name extension in lower case, "" without one (".htaccess" has none) */
export function extension(name) {
	const dot = name.lastIndexOf('.');
	return dot > 0 ? name.slice(dot + 1).toLowerCase() : '';
}

const IMAGE = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'ico', 'bmp'];
const VIDEO = ['mp4', 'webm', 'ogv', 'mov', 'm4v'];
const AUDIO = ['mp3', 'ogg', 'oga', 'wav', 'flac', 'm4a', 'aac'];
const ARCHIVE = ['zip', 'gz', 'tgz', 'bz2', 'tbz', 'xz', 'tar', '7z', 'rar'];
const CODE = [
	'php', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue', 'css', 'scss', 'less', 'html', 'htm', 'json', 'xml',
	'yml', 'yaml', 'sh', 'bash', 'py', 'rb', 'go', 'sql', 'conf', 'ini', 'toml', 'twig', 'blade',
];
const TEXT = ['txt', 'md', 'markdown', 'log', 'csv', 'env', 'htaccess', 'gitignore', 'example', 'dist', 'lock'];
const TEXT_NAMES = ['.htaccess', '.env', '.gitignore', '.htpasswd', '.user.ini', 'Dockerfile', 'Makefile', 'README', 'LICENSE', '.npmrc', '.nvmrc', '.editorconfig'];

/** image, video, audio, archive, code, text, pdf, other, or dir */
export function kindOf(entry) {
	if (entry.dir) {
		return 'dir';
	}
	const name = entry.name ?? '';
	const ext = extension(name);
	if (IMAGE.includes(ext)) return 'image';
	if (VIDEO.includes(ext)) return 'video';
	if (AUDIO.includes(ext)) return 'audio';
	if (ext === 'pdf') return 'pdf';
	if (ARCHIVE.includes(ext)) return 'archive';
	if (CODE.includes(ext)) return 'code';
	if (TEXT.includes(ext) || TEXT_NAMES.includes(name) || name.startsWith('.env') || name.startsWith('nginx.conf')) return 'text';
	return 'other';
}

export function iconFor(entry) {
	switch (kindOf(entry)) {
		case 'dir':
			return 'folder';
		case 'image':
			return 'image';
		case 'archive':
			return 'archive';
		case 'code':
			return 'file-code';
		case 'video':
		case 'audio':
			return 'play';
		default:
			return 'file';
	}
}

/** Archives v-extract-fs-archive unpacks */
export function isExtractable(name) {
	return /\.(zip|tar\.gz|tgz|tar\.bz2?|tbz2?|tar|gz|bz2|7z|rar)$/i.test(name);
}

/**
 * Whether the editor opens a file: text MIME types (or what kindOf calls
 * code or text when the MIME is not known yet) up to the edit limit.
 */
export function isEditable(entry, editMax) {
	if (entry.dir || entry.type === 'o' || (entry.size ?? 0) > editMax) {
		return false;
	}
	const mime = entry.mime ?? '';
	if (mime !== '') {
		return (
			mime.startsWith('text/') ||
			mime === 'inode/x-empty' ||
			mime === 'application/x-empty' ||
			/^application\/(json|xml|javascript|x-php|x-httpd-php|x-sh|x-shellscript|x-yaml|yaml|sql|toml|x-wine-extension-ini)$/.test(mime) ||
			mime.endsWith('+xml') ||
			mime.endsWith('+json')
		);
	}
	return ['code', 'text'].includes(kindOf(entry)) || (entry.size ?? 0) === 0;
}

/** What the preview dialog can show inline */
export function previewKind(entry) {
	const kind = kindOf(entry);
	if (kind === 'image' && extension(entry.name) === 'svg') {
		// SVG can carry scripts: shown through <img>, which never runs them
		return 'image';
	}
	return ['image', 'video', 'audio', 'pdf'].includes(kind) ? kind : null;
}

/** First free "name (n).ext" among taken names, as the server picks it */
export function freeName(name, taken) {
	const set = new Set(taken);
	const dot = name.lastIndexOf('.');
	const [base, ext] = dot > 0 ? [name.slice(0, dot), name.slice(dot)] : [name, ''];
	for (let n = 1; ; n++) {
		const candidate = `${base} (${n})${ext}`;
		if (!set.has(candidate)) {
			return candidate;
		}
	}
}

/**
 * Sort entries: folders first, then by key (name, size, mtime), names
 * compared without case, like v-list-fs-directory-json.
 */
export function sortEntries(entries, key = 'name', order = 'asc') {
	const byName = (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true });
	const compare = {
		name: byName,
		size: (a, b) => a.size - b.size || byName(a, b),
		mtime: (a, b) => a.mtime - b.mtime || byName(a, b),
	}[key] ?? byName;
	const sorted = [...entries].sort((a, b) => (order === 'desc' ? compare(b, a) : compare(a, b)));
	return [...sorted.filter((e) => e.dir), ...sorted.filter((e) => !e.dir)];
}

/**
 * Selection after a click on `name`: plain click selects one, Ctrl/Cmd
 * toggles, Shift selects the range from the anchor.
 *
 * @param {string[]} names  visible names in order
 * @param {Set<string>} selected
 * @param {string|null} anchor
 * @param {string} name
 * @param {{ctrl?: boolean, shift?: boolean}} keys
 * @returns {{selected: Set<string>, anchor: string|null}}
 */
export function select(names, selected, anchor, name, { ctrl = false, shift = false } = {}) {
	if (shift && anchor !== null && names.includes(anchor)) {
		const [from, to] = [names.indexOf(anchor), names.indexOf(name)].sort((a, b) => a - b);
		const range = new Set(ctrl ? selected : []);
		for (const item of names.slice(from, to + 1)) {
			range.add(item);
		}
		return { selected: range, anchor };
	}
	if (ctrl) {
		const next = new Set(selected);
		if (next.has(name)) {
			next.delete(name);
		} else {
			next.add(name);
		}
		return { selected: next, anchor: name };
	}
	return { selected: new Set([name]), anchor: name };
}

/**
 * Keyboard shortcuts of the list (E3 spec §7.2) → action name, or null.
 *
 * @param {{key: string, ctrlKey?: boolean, metaKey?: boolean, shiftKey?: boolean, altKey?: boolean}} event
 */
export function shortcut(event) {
	const mod = Boolean(event.ctrlKey || event.metaKey);
	if (event.altKey) {
		return null;
	}
	if (mod) {
		return { a: 'selectAll', c: 'copy', x: 'cut', v: 'paste' }[event.key.toLowerCase()] ?? null;
	}
	return (
		{
			Enter: 'open',
			Backspace: 'up',
			F2: 'rename',
			Delete: 'delete',
			'/': 'search',
			Escape: 'clear',
			ArrowDown: 'next',
			ArrowUp: 'previous',
			Home: 'first',
			End: 'last',
		}[event.key] ?? null
	);
}

/** Disk use for the status bar: {used, quota, percent} in MB, quota null when unlimited */
export function quotaUse(quota) {
	const used = Number(quota?.used ?? 0);
	const limit = quota?.quota === null || quota?.quota === undefined ? null : Number(quota.quota);
	return { used, quota: limit, percent: limit ? Math.min(100, Math.round((used / limit) * 100)) : null };
}

/** "Oct 5 10:04", or "Oct 5 2025" for older years, in the browser's locale */
export function formatTime(mtime, now = Date.now(), locale = undefined) {
	const date = new Date(Number(mtime) * 1000);
	const sameYear = date.getFullYear() === new Date(now).getFullYear();
	const options = sameYear ? { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } : { year: 'numeric', month: 'short', day: 'numeric' };
	return new Intl.DateTimeFormat(locale, options).format(date);
}

/** Name of a zip for a selection: the one name, or the folder's */
export function zipName(names, folder) {
	if (names.length === 1) {
		return `${names[0]}.zip`;
	}
	return `${baseName(folder) || 'files'}.zip`;
}
