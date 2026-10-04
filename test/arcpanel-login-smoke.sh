#!/bin/bash
# Smoke test of the panel app login against a running ArcPanel server
# (E1 slice S1). Creates a throw-away user, signs in on /app/login and checks
# that the legacy pages accept the same session, then signs out from the
# legacy side. Run as root on a test server, never on production.

set -euo pipefail

PANEL="${PANEL:-https://127.0.0.1:8083}"
HESTIA="${HESTIA:-/usr/local/hestia}"
SMOKE_USER="arcsmoke$RANDOM"
SMOKE_PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
JAR="$(mktemp)"
PAGE="$(mktemp)"
failures=0

cleanup() {
	"$HESTIA/bin/v-delete-user" "$SMOKE_USER" > /dev/null 2>&1 || true
	rm -f "$JAR" "$PAGE"
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

"$HESTIA/bin/v-add-user" "$SMOKE_USER" "$SMOKE_PASS" "$SMOKE_USER@example.invalid" default > /dev/null
"$HESTIA/bin/v-change-sys-config-value" ARCPANEL_APP_LOGIN yes

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
check "panel app root hands over to the legacy landing page" "302 $PANEL/login/" "$(request GET /app/)"

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

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "all checks passed"
