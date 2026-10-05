// Vitest for the panel app's Alpine modules (docs/design/05 §7, jsdom).
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
	// npm run test:js runs from the repository root
	root: fileURLToPath(new URL('.', import.meta.url)),
	test: {
		environment: 'jsdom',
		include: ['tests/js/**/*.test.js'],
		restoreMocks: true,
	},
});
