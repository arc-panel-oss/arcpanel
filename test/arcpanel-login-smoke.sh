#!/bin/bash
# Smoke test of the panel app against a running ArcPanel server.
#
# Part 1: signs in at the root URL, checks the login page and signs out.
# Part 2 (E1 slice S2): signs in, lands on the dashboard, adds a web domain,
# installs a self-signed certificate, suspends, unsuspends and deletes the
# domain, all through HTTP; an old /list/web/ bookmark still works.
# Part 3 (E11): a reseller signs in, creates a customer, logs in as it,
# returns, and deletes it; other tenants answer 404.
#
# Run as root on a test server, never on production.

set -euo pipefail

PANEL="${PANEL:-https://127.0.0.1:8083}"
ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
SMOKE_USER="arcsmoke$RANDOM"
SMOKE_PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
SMOKE_DOMAIN="$SMOKE_USER.example"
SMOKE_RESELLER="arcsmr$RANDOM"
SMOKE_CUSTOMER="arcsmc$RANDOM"
SMOKE_PACKAGE="arcsmoke-plan"
JAR="$(mktemp)"
PAGE="$(mktemp)"
CERT_DIR="$(mktemp -d)"
failures=0

cleanup() {
	"$ARCPANEL/bin/v-delete-user" "$SMOKE_USER" > /dev/null 2>&1 || true
	"$ARCPANEL/bin/v-delete-user" "$SMOKE_CUSTOMER" > /dev/null 2>&1 || true
	"$ARCPANEL/bin/v-delete-user" "$SMOKE_RESELLER" > /dev/null 2>&1 || true
	"$ARCPANEL/bin/v-delete-user-package" "$SMOKE_PACKAGE" > /dev/null 2>&1 || true
	"$ARCPANEL/bin/v-delete-package-tenancy" "$SMOKE_PACKAGE" > /dev/null 2>&1 || true
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

# domain_value KEY -> value of KEY in `v-list-web-domain USER DOMAIN shell`
domain_value() {
	"$ARCPANEL/bin/v-list-web-domain" "$SMOKE_USER" "$SMOKE_DOMAIN" shell 2> /dev/null | awk -v key="$1:" '$1 == key { print $2 }'
}

"$ARCPANEL/bin/v-add-user" "$SMOKE_USER" "$SMOKE_PASS" "$SMOKE_USER@example.invalid" default > /dev/null

# ---------- Part 1: login and logout ----------
check "root URL sends guests to the login page" "302 $PANEL/login" "$(request GET /)"
check "login form renders" "200 " "$(request GET /login)"

token="$(csrf_token)"
check "wrong password is refused" "302 $PANEL/login" \
	"$(request POST /login --data-urlencode "_token=$token" --data-urlencode "user=$SMOKE_USER" --data-urlencode 'password=wrong-password')"

request GET /login > /dev/null
token="$(csrf_token)"
check "correct password lands on the dashboard" "302 $PANEL/" \
	"$(request POST /login --data-urlencode "_token=$token" --data-urlencode "user=$SMOKE_USER" --data-urlencode "password=$SMOKE_PASS")"
check "login page sends the logged-in user to the dashboard" "302 $PANEL/" "$(request GET /login)"

request GET / > /dev/null
token="$(csrf_token)"
check "logout ends the session" "302 $PANEL/login" "$(request POST /logout --data-urlencode "_token=$token")"
check "the user is a guest again" "302 $PANEL/login" "$(request GET /)"

if grep -q "$SMOKE_USER" "$ARCPANEL/log/auth.log"; then ok "logins are in the auth log"; else fail "logins are in the auth log"; fi

# ---------- Part 2: dashboard and web domains (S2) ----------
rm -f "$JAR"

request GET /login > /dev/null
token="$(csrf_token)"
check "login lands on the dashboard" "302 $PANEL/" \
	"$(request POST /login --data-urlencode "_token=$token" --data-urlencode "user=$SMOKE_USER" --data-urlencode "password=$SMOKE_PASS")"
check "dashboard renders" "200 " "$(request GET /)"
if grep -q "Welcome, $SMOKE_USER" "$PAGE"; then ok "dashboard greets the user"; else fail "dashboard greets the user"; fi
check "an old /list/web/ bookmark redirects to the panel page" "301 $PANEL/web/domains" "$(request GET /list/web/)"

check "add form renders" "200 " "$(request GET /web/domains/create)"
token="$(csrf_token)"
ip="$(grep -o '<option value="[0-9.]*"' "$PAGE" | head -n1 | sed 's/.*value="\([^"]*\)"/\1/')"
check "domain is added" "302 $PANEL/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/edit" \
	"$(request POST /web/domains --data-urlencode "_token=$token" --data-urlencode "domain=$SMOKE_DOMAIN" --data-urlencode "ip=$ip")"
check "domain exists on the server" "$SMOKE_DOMAIN" \
	"$("$ARCPANEL/bin/v-list-web-domains" "$SMOKE_USER" plain | awk '{print $1}' | grep -x "$SMOKE_DOMAIN" || true)"

openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$SMOKE_DOMAIN" \
	-keyout "$CERT_DIR/key.pem" -out "$CERT_DIR/crt.pem" > /dev/null 2>&1
check "edit page renders" "200 " "$(request GET "/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/edit")"
token="$(csrf_token)"
check "self-signed certificate is installed" "302 $PANEL/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/edit#ssl" \
	"$(request POST "/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/ssl" --data-urlencode "_token=$token" --data-urlencode 'ssl=1' \
		--data-urlencode "certificate@$CERT_DIR/crt.pem" --data-urlencode "key@$CERT_DIR/key.pem")"
check "domain has SSL" "yes" "$(domain_value SSL)"
if ! grep -rq "BEGIN PRIVATE KEY" "$PAGE" 2> /dev/null; then ok "no private key in the response"; else fail "no private key in the response"; fi

request GET "/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/edit" > /dev/null
token="$(csrf_token)"
request POST "/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/suspend" --data-urlencode "_token=$token" > /dev/null
check "domain is suspended" "yes" "$(domain_value SUSPENDED)"
request POST "/web/domains/$SMOKE_USER/$SMOKE_DOMAIN/unsuspend" --data-urlencode "_token=$token" > /dev/null
check "domain is unsuspended" "no" "$(domain_value SUSPENDED)"

check "domain is deleted" "302 $PANEL/web/domains" \
	"$(request POST "/web/domains/$SMOKE_USER/$SMOKE_DOMAIN" --data-urlencode "_token=$token" --data-urlencode '_method=DELETE')"
check "domain is gone from the server" "" \
	"$("$ARCPANEL/bin/v-list-web-domains" "$SMOKE_USER" plain | awk '{print $1}' | grep -x "$SMOKE_DOMAIN" || true)"

check "another user's domain answers 404" "404 " "$(request GET /web/domains/arcadmin/nope.example/edit)"

# ---------- Part 3: resellers (E11) ----------
"$ARCPANEL/bin/v-copy-user-package" default "$SMOKE_PACKAGE" > /dev/null
"$ARCPANEL/bin/v-change-package-tenancy" "$SMOKE_PACKAGE" USERS 5
"$ARCPANEL/bin/v-change-package-tenancy" "$SMOKE_PACKAGE" RESELLER_PACKAGES default
"$ARCPANEL/bin/v-add-user" "$SMOKE_RESELLER" "$SMOKE_PASS" "$SMOKE_RESELLER@example.invalid" "$SMOKE_PACKAGE" > /dev/null
"$ARCPANEL/bin/v-change-user-role" "$SMOKE_RESELLER" reseller
rm -f "$JAR"

request GET /login > /dev/null
token="$(csrf_token)"
check "reseller lands on the dashboard" "302 $PANEL/" \
	"$(request POST /login --data-urlencode "_token=$token" --data-urlencode "user=$SMOKE_RESELLER" --data-urlencode "password=$SMOKE_PASS")"
check "reseller dashboard renders" "200 " "$(request GET /)"
if grep -q "Add Customer" "$PAGE"; then ok "reseller dashboard offers new customers"; else fail "reseller dashboard offers new customers"; fi
check "packages page renders" "200 " "$(request GET /packages)"

check "customer wizard renders" "200 " "$(request GET /users/create)"
token="$(csrf_token)"
check "customer is created" "302 $PANEL/users/$SMOKE_CUSTOMER" \
	"$(request POST /users --data-urlencode "_token=$token" --data-urlencode "username=$SMOKE_CUSTOMER" \
		--data-urlencode "password=$SMOKE_PASS" --data-urlencode "email=$SMOKE_CUSTOMER@example.invalid" --data-urlencode 'name=Smoke Customer' \
		--data-urlencode 'language=en' --data-urlencode 'package=default')"
check "customer belongs to the reseller" "$SMOKE_RESELLER" \
	"$("$ARCPANEL/bin/v-list-user-owners" plain | awk -v u="$SMOKE_CUSTOMER" '$1 == u { print $3 }')"
check "customer page renders" "200 " "$(request GET "/users/$SMOKE_CUSTOMER")"
check "customers list renders" "200 " "$(request GET /users)"
if grep -q "$SMOKE_CUSTOMER" "$PAGE" && ! grep -q ">$SMOKE_USER<" "$PAGE"; then ok "customers list shows only the tree"; else fail "customers list shows only the tree"; fi
check "another tenant answers 404" "404 " "$(request GET "/users/$SMOKE_USER")"

token="$(csrf_token)"
check "reseller logs in as the customer" "302 $PANEL/" "$(request POST "/impersonate/$SMOKE_CUSTOMER" --data-urlencode "_token=$token")"
check "customer dashboard renders" "200 " "$(request GET /)"
if grep -q "Welcome, $SMOKE_CUSTOMER" "$PAGE"; then ok "acts as the customer"; else fail "acts as the customer"; fi
if grep -q "Your provider" "$PAGE"; then ok "customer sees its provider"; else fail "customer sees its provider"; fi
token="$(csrf_token)"
check "reseller returns" "302 $PANEL/" "$(request POST /impersonate/stop --data-urlencode "_token=$token")"
request GET / > /dev/null
if grep -q "Welcome, $SMOKE_RESELLER" "$PAGE"; then ok "back in the reseller account"; else fail "back in the reseller account"; fi

request GET "/users/$SMOKE_CUSTOMER" > /dev/null
token="$(csrf_token)"
check "customer is deleted" "302 $PANEL/users" \
	"$(request POST "/users/$SMOKE_CUSTOMER" --data-urlencode "_token=$token" --data-urlencode '_method=DELETE')"
check "customer is gone from the server" "" "$("$ARCPANEL/bin/v-list-user-owners" plain | awk -v u="$SMOKE_CUSTOMER" '$1 == u')"

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "all checks passed"
