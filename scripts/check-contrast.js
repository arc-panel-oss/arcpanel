// Checks the contrast of every text/background token pair in every panel
// app theme (docs/design/05 §6): WCAG AA, 4.5:1 for text. Run by build.js;
// exits non-zero on problems.
import { promises as fs } from 'node:fs';
import { fileURLToPath } from 'node:url';

const themesDir = new URL('../web-app/resources/css/themes/', import.meta.url);

const backgrounds = [
	'--ap-bg',
	'--ap-bg-sunken',
	'--ap-surface',
	'--ap-surface-raised',
	'--ap-surface-hover',
];

/** Text token, the backgrounds it is used on. */
const pairs = [
	...['--ap-fg', '--ap-fg-muted', '--ap-fg-subtle', '--ap-accent'].flatMap((text) =>
		backgrounds.map((background) => [text, background]),
	),
	['--ap-accent-fg', '--ap-accent'],
	['--ap-accent', '--ap-accent-subtle'],
	['--ap-fg', '--ap-accent-subtle'],
	...['ok', 'warn', 'danger', 'info'].flatMap((status) => [
		[`--ap-${status}`, `--ap-${status}-subtle`],
		[`--ap-${status}`, '--ap-surface'],
		[`--ap-${status}`, '--ap-bg'],
	]),
];

/** Colour tokens of each theme: nocturne and light files, auto's light block. */
export async function loadThemes() {
	const nocturne = parseTokens(await fs.readFile(new URL('nocturne.css', themesDir), 'utf8'));
	const light = parseTokens(await fs.readFile(new URL('light.css', themesDir), 'utf8'));
	const autoCss = await fs.readFile(new URL('auto.css', themesDir), 'utf8');
	const autoLight = parseTokens(autoCss.slice(autoCss.indexOf('@media')));

	return {
		nocturne,
		light: { ...nocturne, ...light },
		'auto (light scheme)': { ...nocturne, ...autoLight },
	};
}

/**
 * @param {string} css
 * @returns {Record<string, string>}
 */
export function parseTokens(css) {
	const tokens = {};
	for (const match of css.matchAll(/(--ap-[a-z0-9-]+)\s*:\s*(#[0-9a-f]{6})\s*;/gi)) {
		tokens[match[1]] = match[2].toLowerCase();
	}
	return tokens;
}

/**
 * WCAG 2 contrast ratio of two #rrggbb colours.
 *
 * @param {string} a
 * @param {string} b
 */
export function contrast(a, b) {
	const [lighter, darker] = [luminance(a), luminance(b)].sort((x, y) => y - x);
	return (lighter + 0.05) / (darker + 0.05);
}

function luminance(hex) {
	const channels = [1, 3, 5].map((i) => Number.parseInt(hex.slice(i, i + 2), 16) / 255);
	const [r, g, b] = channels.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** @returns {Promise<string[]>} */
export async function checkContrast(minimum = 4.5) {
	const problems = [];
	for (const [theme, tokens] of Object.entries(await loadThemes())) {
		for (const [text, background] of pairs) {
			if (!tokens[text] || !tokens[background]) {
				problems.push(`${theme}: ${text} or ${background} is not defined`);
				continue;
			}
			const ratio = contrast(tokens[text], tokens[background]);
			if (ratio < minimum) {
				problems.push(
					`${theme}: ${text} on ${background} is ${ratio.toFixed(2)}:1, below ${minimum}:1`,
				);
			}
		}
	}
	return problems;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	const problems = await checkContrast();
	for (const problem of problems) {
		console.error(problem);
	}
	process.exit(problems.length > 0 ? 1 : 0);
}
