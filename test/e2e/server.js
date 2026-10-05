// Runs test/e2e/prepare.sh on the test server: through WSL from a Windows
// checkout (E2E_WSL_DISTRO, default arcpanel-test), or directly when the
// suite runs on the server itself (E2E_LOCAL=1).
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('./prepare.sh', import.meta.url));

/** @param {string[]} args */
export function runOnServer(args) {
	const source = readFileSync(script, 'utf8').replace(/\r\n/g, '\n');
	if (process.env.E2E_LOCAL === '1' || process.platform !== 'win32') {
		return execFileSync('bash', ['-s', '--', ...args], { input: source, encoding: 'utf8' });
	}
	const distro = process.env.E2E_WSL_DISTRO ?? 'arcpanel-test';
	return execFileSync('wsl.exe', ['-d', distro, '-u', 'root', '--', 'bash', '-s', '--', ...args], {
		input: source,
		encoding: 'utf8',
	});
}
