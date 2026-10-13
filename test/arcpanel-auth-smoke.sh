#!/bin/bash
# HTTP smoke test of E39 W2 (docs/features/specs/2026-10-10-002-e39-w2-authentication-and-sessions-design.md)
# against a running ArcPanel server: login limits, trusted hosts, sudo mode,
# sessions ended by a password change, web terminal tickets.
#
# Run as root on a test server, never on production. It counts failed logins
# of a throw-away user from 127.0.0.1 and clears that user's limits at the end.

set -euo pipefail

PANEL="${PANEL:-https://127.0.0.1:8083}"
ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
AUTH_USER="arcauth$RANDOM"
AUTH_PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
NEW_PASS="${AUTH_PASS}Nw2"
JAR="$(mktemp)"
JAR2="$(mktemp)"
PAGE="$(mktemp)"
failures=0

cleanup() {
	"$ARCPANEL/bin/v-delete-user-login-limits" "$AUTH_USER" > /dev/null 2>&1 || true
	"$ARCPANEL/bin/v-delete-user" "$AUTH_USER" > /dev/null 2>&1 || true
	rm -f "$JAR" "$JAR2" "$PAGE"
}
trap cleanup EXIT

ok() { echo "ok   - $1"; }
fail() {
	echo "FAIL - $1"
	failures=$((failures + 1))
}

# check NAME EXPECTED ACTUAL
check() {
	if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (expected '$2', got '$3')"; fi
}

# request JAR METHOD PATH [curl args...] -> prints "<status> <location>"
request() {
	local jar="$1" method="$2" path="$3"
	shift 3
	curl -sk -b "$jar" -c "$jar" -o "$PAGE" -X "$method" -w '%{http_code} %{redirect_url}' "$@" "$PANEL$path"
}

csrf_token() {
	grep -o 'name="_token" value="[^"]*"' "$PAGE" | head -n1 | sed 's/.*value="\([^"]*\)"/\1/'
}

# login JAR PASSWORD -> prints "<status> <location>" of the login post
login() {
	request "$1" GET /login > /dev/null
	request "$1" POST /login --data-urlencode "_token=$(csrf_token)" --data-urlencode "user=$AUTH_USER" --data-urlencode "password=$2"
}

"$ARCPANEL/bin/v-add-user" "$AUTH_USER" "$AUTH_PASS" "$AUTH_USER@example.invalid" default > /dev/null
port="${PANEL##*:}"

# ---------- Trusted hosts (H4) ----------
check "a foreign Host header gets 400" "400" \
	"$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: evil.example:$port" "$PANEL/login")"
check "the panel answers on its own address" "200" "$(curl -sk -o /dev/null -w '%{http_code}' "$PANEL/login")"

# ---------- Login limits (H6, M7) ----------
for attempt in 1 2 3 4 5; do
	login "$JAR" wrong-password > /dev/null
done
check "the sixth attempt for a user name and address answers 429" "429 " "$(login "$JAR" "$AUTH_PASS")"
grep -q 'Too many attempts' "$PAGE" && ok "the login page says why" || fail "the login page says why"
"$ARCPANEL/bin/v-delete-user-login-limits" "$AUTH_USER" > /dev/null
# The pair counter decays within a minute
sleep 61

# ---------- Sudo mode (M8) ----------
check "the user logs in" "302 $PANEL/" "$(login "$JAR" "$AUTH_PASS")"
request "$JAR" GET /account > /dev/null
check "a new password asks for sudo first" "302 $PANEL/sudo" \
	"$(request "$JAR" POST "/users/$AUTH_USER/general" --data-urlencode "_token=$(csrf_token)" \
		--data-urlencode "name=$AUTH_USER" --data-urlencode "email=$AUTH_USER@example.invalid" \
		--data-urlencode 'language=en' --data-urlencode "password=$NEW_PASS")"
request "$JAR" GET /sudo > /dev/null
check "the right password confirms and goes back to the form" "302 $PANEL/account" \
	"$(request "$JAR" POST /sudo --data-urlencode "_token=$(csrf_token)" --data-urlencode "password=$AUTH_PASS")"

# A second session of the same user, from before the change
check "a second session logs in" "302 $PANEL/" "$(login "$JAR2" "$AUTH_PASS")"

request "$JAR" GET /account > /dev/null
check "the password change is applied" "302 $PANEL/account#general" \
	"$(request "$JAR" POST "/users/$AUTH_USER/general" --data-urlencode "_token=$(csrf_token)" \
		--data-urlencode "name=$AUTH_USER" --data-urlencode "email=$AUTH_USER@example.invalid" \
		--data-urlencode 'language=en' --data-urlencode "password=$NEW_PASS")"
check "the session that changed it goes on" "200 " "$(request "$JAR" GET /account)"
check "the other session is ended" "302 $PANEL/login" "$(request "$JAR2" GET /account)"

# A change from the command line ends the remaining session too
"$ARCPANEL/bin/v-change-user-password" "$AUTH_USER" "$AUTH_PASS" > /dev/null
sleep 1
check "a password change on the command line ends the session" "302 $PANEL/login" "$(request "$JAR" GET /account)"

# ---------- Web terminal tickets (M4) ----------
if systemctl is-active --quiet arcpanel-web-terminal && [ -d "$ARCPANEL/web-terminal/node_modules/ws" ]; then
	WS_CLIENT="$(mktemp --suffix=.cjs)"
	cat > "$WS_CLIENT" << 'CLIENT'
// node client.cjs URL ORIGIN [TICKET]: "open USER" once the shell answers, else "refused CODE"
const WebSocket = require(`${process.env.ARCPANEL}/web-terminal/node_modules/ws`);
const [url, origin, ticket] = process.argv.slice(2);
const ws = new WebSocket(url, ticket ? [`arcpanel.ticket.${ticket}`] : [], { origin, rejectUnauthorized: false });
let out = '';
ws.on('open', () => setTimeout(() => ws.send('echo "W2:$(whoami):OK"\r'), 300));
ws.on('message', (data) => {
	out += data.toString();
	const match = out.match(/W2:([a-z0-9]+):OK\r?\n/);
	if (match) {
		console.log(`open ${match[1]}`);
		process.exit(0);
	}
});
ws.on('unexpected-response', (req, res) => {
	console.log(`refused ${res.statusCode}`);
	process.exit(0);
});
ws.on('error', (error) => {
	console.log(`error ${error.message}`);
	process.exit(0);
});
setTimeout(() => {
	console.log('timeout');
	process.exit(0);
}, 8000);
CLIENT
	"$ARCPANEL/bin/v-change-user-shell" "$AUTH_USER" bash > /dev/null
	JAR3="$(mktemp)"
	login "$JAR3" "$AUTH_PASS" > /dev/null
	request "$JAR3" GET /terminal > /dev/null
	csrf="$(grep -o 'name="csrf-token" content="[^"]*"' "$PAGE" | sed 's/.*content="\([^"]*\)"/\1/')"
	ticket() {
		curl -sk -b "$JAR3" -c "$JAR3" -X POST -H "X-CSRF-TOKEN: $csrf" -H 'Accept: application/json' "$PANEL/terminal/ticket" | jq -r .ticket
	}
	origin="https://$(hostname):$port"
	shell_url="wss://${PANEL#https://}/_shell/"
	first="$(ticket)"
	check "a fresh ticket opens a shell as the user" "open $AUTH_USER" "$(ARCPANEL=$ARCPANEL node "$WS_CLIENT" "$shell_url" "$origin" "$first")"
	check "a used ticket is refused" "refused 401" "$(ARCPANEL=$ARCPANEL node "$WS_CLIENT" "$shell_url" "$origin" "$first")"
	check "no ticket is refused" "refused 401" "$(ARCPANEL=$ARCPANEL node "$WS_CLIENT" "$shell_url" "$origin")"
	check "a foreign origin is refused" "refused 403" "$(ARCPANEL=$ARCPANEL node "$WS_CLIENT" "$shell_url" "https://evil.example" "$(ticket)")"
	if ss -ltn | grep -qE '127\.0\.0\.1:8085 '; then ok "the terminal server listens on the loopback address only"; else fail "the terminal server listens on the loopback address only"; fi
	rm -f "$WS_CLIENT" "$JAR3"
else
	echo "skip - web terminal checks (service not running)"
fi

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "all checks passed"
