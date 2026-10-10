// Tests of the web terminal tickets: node --test (from src/deb/web-terminal).
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { test } from 'node:test';
import { createVerifier, parseKey, TICKET_PROTOCOL, ticketFromProtocols } from './ticket.js';

const KEY_HEX = 'ab'.repeat(32);
const KEY = parseKey(`${KEY_HEX}\n`);
const NOW = 1_800_000_000_000;

/** A ticket signed the way the panel signs it. */
function sign(payload, key = KEY) {
	const part = Buffer.from(JSON.stringify(payload)).toString('base64url');
	return `${part}.${createHmac('sha256', key).update(part).digest('base64url')}`;
}

function payload(overrides = {}) {
	return { u: 'bob', exp: NOW / 1000 + 30, n: '0123456789abcdef0123456789abcdef', ...overrides };
}

test('accepts a valid ticket once', () => {
	const verify = createVerifier(KEY, () => NOW);
	const ticket = sign(payload());
	assert.equal(verify(ticket), 'bob');
	assert.equal(verify(ticket), null);
});

test('accepts the same nonce again only after it expired and was forgotten', () => {
	let now = NOW;
	const verify = createVerifier(KEY, () => now);
	assert.equal(verify(sign(payload())), 'bob');
	now += 31_000;
	// expired: refused for its expiry, not remembered forever
	assert.equal(verify(sign(payload())), null);
	assert.equal(verify(sign(payload({ exp: now / 1000 + 30 }))), 'bob');
});

test('refuses expired, far-future, forged and malformed tickets', () => {
	const verify = createVerifier(KEY, () => NOW);
	assert.equal(verify(sign(payload({ exp: NOW / 1000 - 1 }))), null);
	assert.equal(verify(sign(payload({ exp: NOW / 1000 + 3600 }))), null);
	assert.equal(verify(sign(payload(), parseKey('cd'.repeat(32)))), null);
	const [part, signature] = sign(payload()).split('.');
	const other = Buffer.from(JSON.stringify(payload({ u: 'root' }))).toString('base64url');
	assert.equal(verify(`${other}.${signature}`), null);
	for (const bad of [
		undefined,
		'',
		'abc',
		`${part}.`,
		`${part}.${signature}.x`,
		`${part}.@@@`,
		'a'.repeat(2000),
	]) {
		assert.equal(verify(bad), null);
	}
});

test('refuses bad users and nonces', () => {
	const verify = createVerifier(KEY, () => NOW);
	for (const u of ['', '-bob', '../etc', 'bob root', 'a'.repeat(31), 42]) {
		assert.equal(verify(sign(payload({ u, n: 'f'.repeat(32) }))), null);
	}
	assert.equal(verify(sign(payload({ n: 'not-a-nonce' }))), null);
	assert.equal(verify(sign(payload({ exp: '1800000030' }))), null);
});

test('reads the key and the ticket of the subprotocol header', () => {
	assert.equal(parseKey('short'), null);
	assert.equal(parseKey(KEY_HEX.toUpperCase()), null);
	assert.equal(KEY.length, 32);
	assert.equal(ticketFromProtocols(`chat, ${TICKET_PROTOCOL}abc.def`), 'abc.def');
	assert.equal(ticketFromProtocols('chat'), null);
	assert.equal(ticketFromProtocols(undefined), null);
});
