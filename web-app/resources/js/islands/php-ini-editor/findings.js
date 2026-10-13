// Findings of v-check-sys-php-ini (E41 spec §6.3) for the php.ini editor:
// {line, level, directive, message}, line 0 for the file as a whole.

/**
 * The marked lines: line number => level, an error winning over a warning
 * on the same line. Lines outside the document are left out.
 *
 * @param {{line: number, level: string}[]} findings
 * @param {number} lineCount
 * @returns {Map<number, 'error'|'warning'>}
 */
export function markerLines(findings, lineCount) {
	const lines = new Map();
	for (const finding of findings) {
		const line = Number(finding.line);
		if (!Number.isInteger(line) || line < 1 || line > lineCount) {
			continue;
		}
		const level = finding.level === 'warning' ? 'warning' : 'error';
		if (lines.get(line) !== 'error') {
			lines.set(line, level);
		}
	}
	return lines;
}

/**
 * @param {{level: string}[]} findings
 * @returns {{errors: number, warnings: number}}
 */
export function countFindings(findings) {
	let errors = 0;
	let warnings = 0;
	for (const finding of findings) {
		if (finding.level === 'warning') {
			warnings++;
		} else {
			errors++;
		}
	}
	return { errors, warnings };
}
