// apTerminal: the web terminal page (E7 batch 2 spec §10.4, port of
// web/js/src/webTerminal.js). xterm.js, its WebGL addon and its stylesheet
// are a separate chunk loaded on demand; the shell runs behind the
// /_shell/ WebSocket of the web terminal server, which opens it for a
// single-use ticket the panel signs (E39 W2 spec §8), sent as a subprotocol.

/** Subprotocol prefix the terminal server reads the ticket from (src/deb/web-terminal/ticket.js). */
export const TICKET_PROTOCOL = 'arcpanel.ticket.';

/** The xterm chunk: Terminal, WebglAddon and the stylesheet as text. */
async function loadXterm() {
	const [xterm, webgl, css] = await Promise.all([
		import('@xterm/xterm'),
		import('@xterm/addon-webgl'),
		import('@xterm/xterm/css/xterm.css'),
	]);
	return { Terminal: xterm.Terminal, WebglAddon: webgl.WebglAddon, css: css.default };
}

/** A fresh ticket from the panel; throws when the panel refuses one. */
async function fetchTicket(ticketUrl) {
	const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
	const response = await fetch(ticketUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
	});
	if (!response.ok) {
		throw new Error(`ticket refused (${response.status})`);
	}
	const { ticket } = await response.json();
	if (typeof ticket !== 'string' || ticket === '') {
		throw new Error('no ticket');
	}
	return ticket;
}

function addStylesheet(css) {
	if (!css || document.getElementById('ap-xterm-css')) {
		return;
	}
	const style = document.createElement('style');
	style.id = 'ap-xterm-css';
	style.textContent = css;
	document.head.append(style);
}

export default function apTerminal({ url = '', ticketUrl = '/terminal/ticket', load = loadXterm, messages = {} } = {}) {
	const text = {
		error: messages.error ?? 'Connection error.',
		closed: messages.closed ?? 'Connection closed.',
	};
	return {
		terminal: null,
		socket: null,

		async init() {
			const { Terminal, WebglAddon, css } = await load();
			addStylesheet(css);
			const terminal = new Terminal();
			if (typeof WebGL2RenderingContext !== 'undefined' && WebglAddon) {
				try {
					terminal.loadAddon(new WebglAddon());
				} catch {
					// the DOM renderer of xterm works without WebGL
				}
			}
			terminal.open(this.$refs.screen);
			this.terminal = terminal;

			let ticket;
			try {
				ticket = await fetchTicket(ticketUrl);
			} catch {
				terminal.writeln(text.error);
				return;
			}
			const socket = new WebSocket(url || `wss://${window.location.host}/_shell/`, [TICKET_PROTOCOL + ticket]);
			this.socket = socket;
			socket.addEventListener('open', () => {
				terminal.onData((data) => socket.send(data));
				socket.addEventListener('message', (event) => terminal.write(event.data));
			});
			socket.addEventListener('error', () => {
				terminal.reset();
				terminal.writeln(text.error);
			});
			socket.addEventListener('close', (event) => {
				if (event.wasClean) {
					terminal.reset();
					terminal.writeln(event.reason || text.closed);
				}
			});
		},

		destroy() {
			this.socket?.close();
			this.terminal?.dispose();
			this.socket = null;
			this.terminal = null;
		},
	};
}
