// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { checkTokens, findProblems } from '../../../scripts/check-tokens.js';

describe('findProblems', () => {
	it('accepts token-only declarations', () => {
		const css = `.ap-card {
	padding: var(--ap-space-4) 0;
	margin-inline: auto;
	gap: calc(var(--ap-space-2) * -1);
	color: var(--ap-fg);
	border: 1px solid var(--ap-border);
	width: 240px;
	background: transparent;
}`;
		expect(findProblems('c.css', css)).toEqual([]);
	});

	it('reports literal colours, pixel spacing and token definitions with line numbers', () => {
		const css = `.a {
	color: #fff;
	background: rgb(0 0 0 / 0.5);
	border-color: white;
	padding: 4px 8px;
	margin-top: -2px;
	--ap-local: 3px;
}`;
		expect(findProblems('c.css', css)).toEqual([
			'c.css:2 literal colour in "color: #fff"',
			'c.css:3 literal colour in "background: rgb(0 0 0 / 0.5)"',
			'c.css:4 literal colour in "border-color: white"',
			'c.css:5 pixel spacing in "padding: 4px 8px"; use --ap-space-*',
			'c.css:6 pixel spacing in "margin-top: -2px"; use --ap-space-*',
			'c.css:7 defines token --ap-local; tokens belong in tokens.css or a theme',
		]);
	});

	it('ignores comments but keeps line numbers', () => {
		const css = `/* color: #fff;
   padding: 4px; */
.a { }
.b {
	color: red;
}`;
		expect(findProblems('c.css', css)).toEqual(['c.css:5 literal colour in "color: red"']);
	});
});

describe('checkTokens', () => {
	it('passes on the panel app stylesheets', async () => {
		expect(await checkTokens()).toEqual([]);
	});
});
