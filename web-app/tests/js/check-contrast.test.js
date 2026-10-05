// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { checkContrast, contrast, loadThemes, parseTokens } from '../../../scripts/check-contrast.js';

describe('contrast', () => {
	it('computes WCAG ratios', () => {
		expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 5);
		expect(contrast('#ffffff', '#000000')).toBeCloseTo(21, 5);
		expect(contrast('#777777', '#777777')).toBe(1);
		expect(contrast('#767676', '#ffffff')).toBeCloseTo(4.54, 2);
	});
});

describe('parseTokens', () => {
	it('reads #rrggbb colour tokens only', () => {
		const css = `:root { --ap-bg: #0B0D12; --ap-overlay: rgb(4 5 8 / 0.7); --ap-short: #fff; --other: #123456; }`;
		expect(parseTokens(css)).toEqual({ '--ap-bg': '#0b0d12' });
	});
});

describe('checkContrast', () => {
	it('loads the three themes', async () => {
		const themes = await loadThemes();
		expect(Object.keys(themes)).toEqual(['nocturne', 'light', 'auto (light scheme)']);
		expect(themes.light['--ap-bg']).not.toBe(themes.nocturne['--ap-bg']);
		expect(themes['auto (light scheme)']['--ap-bg']).toBe(themes.light['--ap-bg']);
	});

	it('passes at AA and reports pairs below a stricter minimum', async () => {
		expect(await checkContrast()).toEqual([]);
		const strict = await checkContrast(30);
		expect(strict.length).toBeGreaterThan(0);
		expect(strict[0]).toMatch(/^nocturne: --ap-\S+ on --ap-\S+ is \d+\.\d\d:1, below 30:1$/);
	});
});
