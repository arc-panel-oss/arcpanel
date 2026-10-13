#!/usr/bin/env node

import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { spawn } from 'node-pty';
import { WebSocketServer } from 'ws';
import { createVerifier, parseKey, TICKET_PROTOCOL, ticketFromProtocols } from './ticket.js';

const hostname = execSync('hostname', { silent: true }).toString().trim();
const systemIPs = JSON.parse(
	execSync(`${process.env.ARCPANEL}/bin/v-list-sys-ips json`, { silent: true }).toString(),
);
const { config } = JSON.parse(
	execSync(`${process.env.ARCPANEL}/bin/v-list-sys-config json`, { silent: true }).toString(),
);

// The panel signs a single-use ticket for the account of the session after
// its own checks (E39 W2 spec §8); this server only checks the ticket.
const keyFile = `${process.env.ARCPANEL}/data/web-terminal/ticket.key`;
let key = null;
try {
	key = parseKey(readFileSync(keyFile, 'utf8'));
} catch {
	key = null;
}
if (key === null) {
	console.error(`Missing or invalid ${keyFile}, run v-add-sys-web-terminal-key`);
	process.exit(1);
}
const verifyTicket = createVerifier(key);

const wss = new WebSocketServer({
	// Only the panel's nginx reaches the server (/_shell/)
	host: '127.0.0.1',
	port: Number.parseInt(config.WEB_TERMINAL_PORT, 10),
	verifyClient: (info, cb) => {
		const origin = info.origin || info.req.headers.origin;
		let matches = origin === `https://${hostname}:${config.BACKEND_PORT}`;

		if (!matches) {
			for (const ip of Object.keys(systemIPs)) {
				if (origin === `https://${ip}:${config.BACKEND_PORT}`) {
					matches = true;
					break;
				}
			}
		}

		if (!matches) {
			cb(false, 403, 'Forbidden');
			return;
		}

		const user = verifyTicket(ticketFromProtocols(info.req.headers['sec-websocket-protocol']));
		if (user === null) {
			cb(false, 401, 'Unauthorized');
			return;
		}
		info.req.arcpanelUser = user;
		cb(true);
	},
	// Answer with the ticket protocol the page asked for
	handleProtocols: (protocols) => {
		for (const protocol of protocols) {
			if (protocol.startsWith(TICKET_PROTOCOL)) {
				return protocol;
			}
		}
		return false;
	},
});

wss.on('connection', (ws, req) => {
	wss.clients.add(ws);

	// Set by the local nginx only: the server listens on 127.0.0.1
	const remoteIP = req.headers['x-real-ip'] || req.socket.remoteAddress;
	const username = req.arcpanelUser;
	if (typeof username !== 'string' || username.length === 0) {
		ws.close(1000, 'You are not authenticated.');
		return;
	}
	console.log(`New connection from ${remoteIP} for ${username}`);

	// Get user info
	const passwd = readFileSync('/etc/passwd').toString();
	const userline = passwd.split('\n').find((line) => line.startsWith(`${username}:`));
	if (!userline) {
		console.error(`User ${username} not found, refusing connection`);
		ws.close(1000, 'You are not allowed to access this server.');
		return;
	}
	const [, , uid, gid, , homedir, shell] = userline.split(':');

	if (shell.endsWith('nologin')) {
		console.error(`User ${username} has no shell, refusing connection`);
		ws.close(1000, 'You have no shell access.');
		return;
	}

	// Spawn shell as logged in user
	const pty = spawn(shell, [], {
		name: 'xterm-color',
		uid: Number.parseInt(uid, 10),
		gid: Number.parseInt(gid, 10),
		cwd: homedir,
		env: {
			SHELL: shell,
			TERM: 'xterm-color',
			USER: username,
			HOME: homedir,
			PWD: homedir,
			ARCPANEL: process.env.ARCPANEL,
		},
	});
	console.log(`New pty (${pty.pid}): ${shell} as ${username} (${uid}:${gid}) in ${homedir}`);

	// Send/receive data from websocket/pty
	pty.on('data', (data) => ws.send(data));
	ws.on('message', (data) => pty.write(data));

	// Ensure pty is killed when websocket is closed and vice versa
	pty.on('exit', () => {
		console.log(`Ended pty (${pty.pid})`);
		if (ws.OPEN) {
			ws.close();
		}
	});
	ws.on('close', () => {
		console.log(`Ended connection from ${remoteIP} for ${username}`);
		pty.kill();
		wss.clients.delete(ws);
	});
});
