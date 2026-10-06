import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import apTerminal from '../../resources/js/alpine/terminal.js';

class FakeTerminal {
	constructor() {
		this.written = [];
		this.dataHandler = null;
		FakeTerminal.last = this;
	}
	loadAddon(addon) {
		this.addon = addon;
	}
	open(element) {
		this.element = element;
	}
	onData(handler) {
		this.dataHandler = handler;
	}
	write(text) {
		this.written.push(text);
	}
	writeln(text) {
		this.written.push(`${text}\n`);
	}
	reset() {
		this.written = [];
	}
	dispose() {
		this.disposed = true;
	}
}

class FakeSocket extends EventTarget {
	constructor(url) {
		super();
		this.url = url;
		this.sent = [];
		FakeSocket.last = this;
	}
	send(data) {
		this.sent.push(data);
	}
	close() {
		this.closed = true;
	}
	emit(type, props = {}) {
		this.dispatchEvent(Object.assign(new Event(type), props));
	}
}

const load = async () => ({ Terminal: FakeTerminal, WebglAddon: class {}, css: '.xterm { color: red; }' });

async function start(options = {}) {
	const component = apTerminal({ url: 'wss://panel.example:8083/_shell/', load, ...options });
	component.$refs = { screen: document.createElement('div') };
	await component.init();
	return component;
}

beforeEach(() => {
	vi.stubGlobal('WebSocket', FakeSocket);
});

afterEach(() => {
	vi.unstubAllGlobals();
	document.head.innerHTML = '';
});

describe('apTerminal', () => {
	it('opens the terminal, adds the stylesheet once and connects to the shell', async () => {
		const component = await start();
		expect(FakeTerminal.last.element).toBe(component.$refs.screen);
		expect(FakeSocket.last.url).toBe('wss://panel.example:8083/_shell/');
		expect(document.querySelectorAll('#ap-xterm-css')).toHaveLength(1);
		await start();
		expect(document.querySelectorAll('#ap-xterm-css')).toHaveLength(1);
	});

	it('sends keystrokes and writes messages once the socket is open', async () => {
		await start();
		const terminal = FakeTerminal.last;
		const socket = FakeSocket.last;
		expect(terminal.dataHandler).toBeNull();

		socket.emit('open');
		terminal.dataHandler('ls\r');
		expect(socket.sent).toEqual(['ls\r']);
		socket.emit('message', { data: 'file.txt\r\n' });
		expect(terminal.written).toEqual(['file.txt\r\n']);
	});

	it('shows the legacy messages on errors and clean closes', async () => {
		await start();
		FakeSocket.last.emit('error');
		expect(FakeTerminal.last.written).toEqual(['Connection error.\n']);

		await start();
		FakeSocket.last.emit('close', { wasClean: true, reason: '' });
		expect(FakeTerminal.last.written).toEqual(['Connection closed.\n']);

		await start();
		FakeSocket.last.emit('close', { wasClean: true, reason: 'Session expired' });
		expect(FakeTerminal.last.written).toEqual(['Session expired\n']);

		await start();
		FakeSocket.last.emit('close', { wasClean: false });
		expect(FakeTerminal.last.written).toEqual([]);
	});

	it('closes the socket and disposes the terminal when destroyed', async () => {
		const component = await start();
		component.destroy();
		expect(FakeSocket.last.closed).toBe(true);
		expect(FakeTerminal.last.disposed).toBe(true);
	});
});
