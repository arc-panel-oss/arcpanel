module.exports = {
	// Run markdownlint on MD files
	'*.md': 'markdownlint-cli2 --fix',
	// Run Biome on certain file types
	'*.{ts,js,css}?(x)': 'biome lint --write --no-errors-on-unmatched',
	// Run Prettier where supported, then check user-visible text for the old brand (ADR-0006)
	'*': ['prettier --write --ignore-unknown', 'bash test/brand-check.sh'],
};
