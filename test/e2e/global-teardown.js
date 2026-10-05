// Removes the test users again.
import { runOnServer } from './server.js';

export default async function globalTeardown() {
	if (process.env.E2E_KEEP !== '1') {
		runOnServer(['cleanup']);
	}
}
