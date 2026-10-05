// Creates the test users and domains on the server before the suite.
import { randomBytes } from 'node:crypto';
import { runOnServer } from './server.js';

export default async function globalSetup() {
	const password = `E2e${randomBytes(12).toString('base64url')}7a`;
	const certificate = JSON.parse(runOnServer(['prepare', password]).trim().split('\n').pop());

	process.env.E2E_PASSWORD = password;
	process.env.E2E_CERTIFICATE = certificate.certificate;
	process.env.E2E_KEY = certificate.key;
}
