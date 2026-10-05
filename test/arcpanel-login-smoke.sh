#!/bin/bash
# Smoke test of the panel app against a running ArcPanel server.
#
# Part 1 (E1 slice S1, ARCPANEL_APP_PAGES=no): creates a throw-away user,
# signs in on /app/login and checks that the legacy pages accept the same
# session, then signs out from the legacy side.
# Part 2 (E1 slice S2, ARCPANEL_APP_PAGES=yes): signs in, lands on the
# dashboard, adds a web domain, installs a self-signed certificate, suspends,
# unsuspends and deletes the domain, all through HTTP.
#
# Run as root on a test server, never on production.

set -euo pipefail

PANEL="${PANEL:-https://127.0.0.1:8083}"
HESTIA="${HESTIA:-/usr/local/hestia}"
SMOKE_USER="arcsmoke$RANDOM"
SMOKE_PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
SMOKE_DOMAIN="$SMOKE_USER.example"
JAR="$(mktemp)"
PAGE="$(mktemp)"
CERT_DIR="$(mktemp -d)"
PAGES_BEFORE="$(grep -o "^ARCPANEL_APP_PAGES='[a-z]*'" "$HESTIA/conf/hestia.conf" | cut -d"'" -f2 || true)"
failures=0

cleanup() {
	"$HESTIA/bin/v-delete-user" "$SMOKE_USER" > /dev/null 2>&1 || true
	"$HESTIA/bin/v-change-sys-config-value" ARCPANEL_APP_PAGES "${PAGES_BEFORE:-yes}" > /dev/null 2>&1 || true
	rm -rf "$JAR" "$PAGE" "$CERT_DIR"
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

# request METHOD PATH [curl args...] -> prints "<status> <location>"
request() {
	local method="$1" path="$2"
	shift 2
	curl -sk -b "$JAR" -c "$JAR" -o "$PAGE" -X "$method" -w '%{http_code} %{redirect_url}' "$@" "$PANEL$path"
}

csrf_token() {
	grep -o 'name="_token" value="[^"]*"' "$PAGE" | head -n1 | sed 's/.*value="\([^"]*\)"/\1/'
}

# The panel app caches the server configuration for ARC_SYS_CONFIG_TTL
# seconds; forget it after a switch changed.
switch_pages() {
	"$HESTIA/bin/v-change-sys-config-value" ARCPANEL_APP_PAGES "$1"
	runuser -u hestiaweb -- "$HESTIA/php/bin/php" "$HESTIA/web-app/artisan" cache:forget arc.sys-config > /dev/null
}

# domain_value KEY -> value of KEY in `v-list-web-domain USER DOMAIN shell`
domain_value() {
	"$HESTIA/bin/v-list-web-domain" "$SMOKE_USER" "$SMOKE_DOMAIN" shell 2> /dev/null | awk -v key="$1:" '$1 == key { print $2 }'
}

"$HESTIA/bin/v-add-user" "$SMOKE_USER" "$SMOKE_PASS" "$SMOKE_USER@example.invalid" default > /dev/null
"$HESTIA/bin/v-change-sys-config-value" ARCPANEL_APP_LOGIN yes

# ---------- Part 1: login and session bridge (S1) ----------
switch_pages no

check "legacy /login/ sends guests to the panel app" "302 $PANEL/app/login" "$(request GET /login/)"
check "login form renders" "200 " "$(request GET /app/login)"

token="$(csrf_token)"
check "wrong password is refused" "302 $PANEL/app/login" \
	"$(request POST /app/login --data-urlencode "_token=$token" --data-urlencode "user=$SMOKE_USER" --data-urlencode 'password=wrong-password')"

request GET /app/login > /dev/null
token="$(csrf_token)"
check "correct password lands on the legacy web list" "302 $PANEL/list/web/" \
	"$(request POST /app/login --data-urlencode "_token=$token" --data-urlencode "user=$SMOKE_USER" --data-urlencode "password=$SMOKE_PASS")"

check "legacy page accepts the session without a new login" "200 " "$(request GET /list/web/)"
if grep -q "$SMOKE_USER" "$PAGE"; then ok "legacy page shows the user"; else fail "legacy page shows the user"; fi

check "panel app sees the logged-in user" "302 $PANEL/login/" "$(request GET /app/login)"
check "panel app pages stay reachable by URL" "200 " "$(request GET /app/)"

legacy_token="$(grep -o 'token=[0-9a-f]\{32\}' "$PAGE" | head -n1 | cut -d= -f2 || true)"
if [ -z "$legacy_token" ]; then
	request GET /list/web/ > /dev/null
	legacy_token="$(grep -o 'name="token" value="[0-9a-f]\{32\}"' "$PAGE" | head -n1 | grep -o '[0-9a-f]\{32\}' || true)"
fi
# A browser sends the page it came from; the legacy CSRF check requires it.
check "legacy logout ends the session" "302 $PANEL/login/" \
	"$(request GET "/logout/?token=$legacy_token" -e "$PANEL/list/web/")"
check "panel app treats the user as a guest again" "302 $PANEL/app/login" "$(request GET /app/)"

if grep -q "$SMOKE_USER" "$HESTIA/log/auth.log"; then ok "logins are in the auth log"; else fail "logins are in the auth log"; fi

# ---------- Part 2: dashboard and web domains (S2) ----------
switch_pages yes
rm -f "$JAR"

request GET /app/login > /dev/null
token="$(csrf_token)"
check "login lands on the dashboard" "302 $PANEL/app/" \
	"$(request POST /app/login --data-urlencode "_token=$token" --data-urlencode "user=$SMOKE_USER" --data-urlencode "password=$SMOKE_PASS")"
check "dashboard renders" "200 " "$(request GET /app/)"
if grep -q "Welcome, $SMOKE_USER" "$PAGE"; then ok "dashboard greets the user"; else fail "dashboard greets the user"; fi
check "legacy web list redirects to the panel app" "302 $PANEL/app/web/domains" "$(request GET /list/web/ -e "$PANEL/list/web/")"

check "add form renders" "200 " "$(request GET /app/web/domains/create)"
token="$(csrf_token)"
ip="$(grep -o '<option value="[0-9.]*"' "$PAGE" | head -n1 | sed 's/.*value="\([^"]*\)"/\1/')"
check "domain is added" "302 $PANEL/app/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/edit" \
	"$(request POST /app/web/domains --data-urlencode "_token=$token" --data-urlencode "domain=$SMOKE_DOMAIN" --data-urlencode "ip=$ip")"
check "domain exists on the server" "$SMOKE_DOMAIN" \
	"$("$HESTIA/bin/v-list-web-domains" "$SMOKE_USER" plain | awk '{print $1}' | grep -x "$SMOKE_DOMAIN" || true)"

openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$SMOKE_DOMAIN" \
	-keyout "$CERT_DIR/key.pem" -out "$CERT_DIR/crt.pem" > /dev/null 2>&1
check "edit page renders" "200 " "$(request GET "/app/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/edit")"
token="$(csrf_token)"
check "self-signed certificate is installed" "302 $PANEL/app/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/edit#ssl" \
	"$(request POST "/app/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/ssl" --data-urlencode "_token=$token" --data-urlencode 'ssl=1' \
		--data-urlencode "certificate@$CERT_DIR/crt.pem" --data-urlencode "key@$CERT_DIR/key.pem")"
check "domain has SSL" "yes" "$(domain_value SSL)"
if ! grep -rq "BEGIN PRIVATE KEY" "$PAGE" 2> /dev/null; then ok "no private key in the response"; else fail "no private key in the response"; fi

request GET "/app/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/edit" > /dev/null
token="$(csrf_token)"
request POST "/app/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/suspend" --data-urlencode "_token=$token" > /dev/null
check "domain is suspended" "yes" "$(domain_value SUSPENDED)"
request POST "/app/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/unsuspend" --data-urlencode "_token=$token" > /dev/null
check "domain is unsuspended" "no" "$(domain_value SUSPENDED)"

check "domain is deleted" "302 $PANEL/app/web/domains" \
	"$(request POST "/app/web/domains/$SMOKE_USER/$SMOKE_DOMAIN" --data-urlencode "_token=$token" --data-urlencode '_method=DELETE')"
check "domain is gone from the server" "" \
	"$("$HESTIA/bin/v-list-web-domains" "$SMOKE_USER" plain | awk '{print $1}' | grep -x "$SMOKE_DOMAIN" || true)"

check "another user's domain answers 404" "404 " "$(request GET /app/web/domains/arcadmin/nope.example/edit)"

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "all checks passed"
