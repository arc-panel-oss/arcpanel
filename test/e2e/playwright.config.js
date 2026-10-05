// Playwright suite of the panel app (E1-S2 spec §11), against a running test
// server: E2E_BASE_URL=https://<server>:8083 npm run test:e2e
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
	testDir: '.',
	testMatch: '*.spec.js',
	// The specs change server state (domains, preferences): one at a time.
	workers: 1,
	fullyParallel: false,
	timeout: 120_000,
	retries: 0,
	reporter: [['list']],
	globalSetup: './global-setup.js',
	globalTeardown: './global-teardown.js',
	use: {
		baseURL: process.env.E2E_BASE_URL ?? 'https://127.0.0.1:8083',
		ignoreHTTPSErrors: true,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], ignoreHTTPSErrors: true } }],
	outputDir: '../../test-results/e2e',
});
