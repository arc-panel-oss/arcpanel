// Checks that the panel app component styles use design tokens only
// (docs/design/05 §3.1): no literal colours and no pixel spacing outside
// tokens.css and the theme files. Run by build.js; exits non-zero on problems.
import { promises as fs } from 'node:fs';
import { fileURLToPath } from 'node:url';

const cssDir = new URL('../web-app/resources/css/', import.meta.url);
const checkedFiles = ['base.css', 'components.css'];

const colourPattern =
	/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(|\b(?:white|black|red|green|blue|gray|grey|yellow|orange|purple|pink|silver)\b/i;
const spacingProperty = /^(?:margin|padding|gap|row-gap|column-gap)(?:-[a-z-]+)?$/;
const pixelValue = /(?:^|[\s(,])-?\d+(?:\.\d+)?px\b/;

/**
 * Problems in one stylesheet, as "file:line message" strings.
 *
 * @param {string} name
 * @param {string} css
 * @returns {string[]}
 */
export function findProblems(name, css) {
	const problems = [];
	const lines = css
		.replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\n]/g, ' '))
		.split('\n');

	lines.forEach((line, index) => {
		const declaration = line.match(/^\s*([a-z-]+)\s*:\s*(.+?);?\s*$/i);
		if (!declaration) {
			return;
		}
		const [, property, value] = declaration;
		if (property.startsWith('--')) {
			problems.push(
				`${name}:${index + 1} defines token ${property}; tokens belong in tokens.css or a theme`,
			);
			return;
		}
		if (colourPattern.test(value)) {
			problems.push(`${name}:${index + 1} literal colour in "${property}: ${value}"`);
		}
		if (spacingProperty.test(property) && pixelValue.test(value)) {
			problems.push(
				`${name}:${index + 1} pixel spacing in "${property}: ${value}"; use --ap-space-*`,
			);
		}
	});

	return problems;
}

/** @returns {Promise<string[]>} */
export async function checkTokens() {
	const problems = [];
	for (const file of checkedFiles) {
		const css = await fs.readFile(new URL(file, cssDir), 'utf8');
		problems.push(...findProblems(file, css));
	}
	return problems;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	const problems = await checkTokens();
	for (const problem of problems) {
		console.error(problem);
	}
	process.exit(problems.length > 0 ? 1 : 0);
}
