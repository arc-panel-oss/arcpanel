// Web terminal tickets (E39 W2 spec §8, D13). The panel signs
// "<payload>.<signature>" (web-app/app/Services/Terminal/TerminalTickets.php):
// the payload is base64url JSON {u: user, exp: Unix time, n: 32 hex nonce},
// the signature base64url HMAC-SHA256 of the payload part with the key of
// $ARCPANEL/data/web-terminal/ticket.key. A ticket is accepted once, before
// it expires.

import { createHmac, timingSafeEqual } from 'node:crypto';

/** Subprotocol prefix the page sends the ticket in. */
export const TICKET_PROTOCOL = 'arcpanel.ticket.';

/** Longest life a ticket may claim, in seconds; the panel gives 30. */
const MAX_LIFETIME = 60;

const BASE64URL = /^[A-Za-z0-9_-]+$/;
// System user names as the panel creates them
const USER = /^[A-Za-z0-9][A-Za-z0-9._-]{0,28}[A-Za-z0-9]$/;
const NONCE = /^[0-9a-f]{32}$/;

/**
 * The key as bytes from the hex text of the key file, or null when it is not
 * 32 bytes of hex.
 *
 * @param {string} text
 * @returns {Buffer|null}
 */
export function parseKey(text) {
	const hex = String(text).trim();
	return /^[0-9a-f]{64}$/.test(hex) ? Buffer.from(hex, 'hex') : null;
}

/**
 * A verifier that remembers the nonces it accepted until they expire.
 *
 * @param {Buffer} key
 * @param {() => number} now milliseconds, for tests
 * @returns {(ticket: unknown) => string|null} the user of a valid ticket, else null
 */
export function createVerifier(key, now = () => Date.now()) {
	const seen = new Map();

	return function verify(ticket) {
		const seconds = Math.floor(now() / 1000);
		for (const [nonce, exp] of seen) {
			if (exp < seconds) {
				seen.delete(nonce);
			}
		}

		if (typeof ticket !== 'string' || ticket.length > 1024) {
			return null;
		}
		const parts = ticket.split('.');
		if (parts.length !== 2 || !BASE64URL.test(parts[0]) || !BASE64URL.test(parts[1])) {
			return null;
		}
		const expected = createHmac('sha256', key).update(parts[0]).digest();
		const given = Buffer.from(parts[1], 'base64url');
		if (given.length !== expected.length || !timingSafeEqual(given, expected)) {
			return null;
		}

		let payload;
		try {
			payload = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'));
		} catch {
			return null;
		}
		const { u: user, exp, n: nonce } = payload ?? {};
		if (
			typeof user !== 'string' ||
			!USER.test(user) ||
			!Number.isInteger(exp) ||
			exp < seconds ||
			exp > seconds + MAX_LIFETIME ||
			typeof nonce !== 'string' ||
			!NONCE.test(nonce) ||
			seen.has(nonce)
		) {
			return null;
		}
		seen.set(nonce, exp);
		return user;
	};
}

/**
 * The ticket of a Sec-WebSocket-Protocol header value, or null.
 *
 * @param {string|undefined} header
 * @returns {string|null}
 */
export function ticketFromProtocols(header) {
	if (typeof header !== 'string') {
		return null;
	}
	const protocols = header.split(',').map((item) => item.trim());
	const match = protocols.find((item) => item.startsWith(TICKET_PROTOCOL));
	return match ? match.slice(TICKET_PROTOCOL.length) : null;
}
