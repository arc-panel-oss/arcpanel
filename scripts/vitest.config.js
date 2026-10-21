// Vitest for the release tool (docs/features/specs/2026-10-21-001-release-standards-design.md §10).
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
	// npm run test:release runs from the repository root
	root: fileURLToPath(new URL('.', import.meta.url)),
	test: {
		environment: 'node',
		include: ['release.test.mjs'],
	},
});
