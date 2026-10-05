// Indentation and language of a file for the editor (E3 spec §7.6).

/**
 * The indentation the file uses: a tab, or the most common step of leading
 * spaces (2, 4 or 8); 4 spaces for a file without indented lines.
 *
 * @param {string} text
 * @returns {string}
 */
export function detectIndent(text) {
	let tabs = 0;
	const steps = new Map();
	let previous = 0;
	for (const line of text.split('\n').slice(0, 2000)) {
		if (line.trim() === '') {
			continue;
		}
		if (line.startsWith('\t')) {
			tabs++;
			continue;
		}
		const spaces = line.length - line.trimStart().length;
		const step = Math.abs(spaces - previous);
		if (step === 2 || step === 4 || step === 8) {
			steps.set(step, (steps.get(step) ?? 0) + 1);
		}
		previous = spaces;
	}
	const [best, count] = [...steps.entries()].sort((a, b) => b[1] - a[1])[0] ?? [4, 0];
	return tabs > count ? '\t' : ' '.repeat(best);
}

/**
 * Language key of createEditor() for a file name, '' for plain text.
 *
 * @param {string} name
 */
export function languageFor(name) {
	const lower = name.toLowerCase();
	if (lower === '.htaccess' || lower === '.htpasswd' || lower.endsWith('.htaccess') || lower === 'httpd.conf' || lower.startsWith('apache')) {
		return lower === '.htpasswd' ? '' : 'apache';
	}
	if (lower.startsWith('nginx.conf') || lower.startsWith('nginx.ssl.conf') || lower.endsWith('.nginx')) {
		return 'nginx';
	}
	if (lower === '.env' || lower.startsWith('.env.') || lower.endsWith('.ini') || lower === '.user.ini' || lower.endsWith('.properties') || lower === '.npmrc' || lower === '.editorconfig') {
		return 'ini';
	}
	if (lower === 'dockerfile' || lower === 'makefile') {
		return 'shell';
	}
	const dot = lower.lastIndexOf('.');
	const ext = dot > 0 ? lower.slice(dot + 1) : '';
	return (
		{
			php: 'php',
			phtml: 'php',
			inc: 'php',
			js: 'javascript',
			mjs: 'javascript',
			cjs: 'javascript',
			jsx: 'jsx',
			ts: 'typescript',
			tsx: 'typescript',
			vue: 'html',
			css: 'css',
			scss: 'css',
			less: 'css',
			html: 'html',
			htm: 'html',
			twig: 'html',
			xml: 'html',
			svg: 'html',
			json: 'json',
			webmanifest: 'json',
			yml: 'yaml',
			yaml: 'yaml',
			md: 'markdown',
			markdown: 'markdown',
			sql: 'sql',
			sh: 'shell',
			bash: 'shell',
			zsh: 'shell',
			conf: 'nginx',
			toml: 'ini',
		}[ext] ?? ''
	);
}
