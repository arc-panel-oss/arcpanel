#!/bin/bash
# Smoke test of REST API v2 (E34,
# docs/features/specs/2026-10-07-002-e34-api-v2-design.md) against a
# running ArcPanel server, through nginx and curl only.
#
# Turns the API on for every key and address for the run (API_SYSTEM=2,
# API_ALLOWED_IP=allow-all) and restores both at the end. Creates access
# keys and throw-away users (arcsmoke*), and deletes them.
#
# Run as root on a test server, never on production.

set -euo pipefail

PANEL="${PANEL:-https://127.0.0.1:8083}"
ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
API="$PANEL/api/v2"
CONF="$ARCPANEL/conf/arcpanel.conf"
SMOKE_USER="arcsmoke$RANDOM"
SMOKE_PACKAGE="arcsmoke-pkg$RANDOM"
SMOKE_PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
ROOT_USER=$(grep "^ROOT_USER=" "$CONF" | cut -d"'" -f2)
OLD_API_SYSTEM=$(grep "^API_SYSTEM=" "$CONF" | cut -d"'" -f2 || true)
OLD_API_ALLOWED_IP=$(grep "^API_ALLOWED_IP=" "$CONF" | cut -d"'" -f2 || true)
BODY="$(mktemp)"
KEYS=()
failures=0

# The panel caches arcpanel.conf for a minute
clear_panel_cache() {
	runuser -u arcpanelweb -- "$ARCPANEL/php/bin/php" "$ARCPANEL/web-app/artisan" cache:clear > /dev/null
}

cleanup() {
	"$ARCPANEL/bin/v-delete-user" "$SMOKE_USER" > /dev/null 2>&1 || true
	for package in "$SMOKE_PACKAGE" "$SMOKE_PACKAGE-copy"; do
		"$ARCPANEL/bin/v-delete-user-package" "$package" > /dev/null 2>&1 || true
		"$ARCPANEL/bin/v-delete-package-tenancy" "$package" > /dev/null 2>&1 || true
	done
	for key in "${KEYS[@]}"; do
		"$ARCPANEL/bin/v-delete-access-key" "${key%%:*}" > /dev/null 2>&1 || true
	done
	"$ARCPANEL/bin/v-change-sys-config-value" API_SYSTEM "${OLD_API_SYSTEM:-0}"
	"$ARCPANEL/bin/v-change-sys-config-value" API_ALLOWED_IP "$OLD_API_ALLOWED_IP"
	clear_panel_cache
	rm -f "$BODY"
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

# new_key USER SETS -> "ID:SECRET"
new_key() {
	local key
	key=$("$ARCPANEL/bin/v-add-access-key" "$1" "$2" arcsmoke json | jq -r '"\(.ACCESS_KEY_ID):\(.SECRET_ACCESS_KEY)"')
	KEYS+=("$key")
	echo "$key"
}

# call KEY METHOD PATH [JSON] -> prints the status; the answer is in $BODY
call() {
	local key="$1" method="$2" path="$3" data="${4:-}"
	local args=(-sk -o "$BODY" -w '%{http_code}' -X "$method" -H "Authorization: Bearer $key" -H 'Accept: application/json')
	if [ -n "$data" ]; then
		args+=(-H 'Content-Type: application/json' --data "$data")
	fi
	curl "${args[@]}" "$API$path"
}

# json FILTER -> value of the last answer
json() { jq -r "$1" "$BODY"; }

"$ARCPANEL/bin/v-change-sys-config-value" API_SYSTEM 2
"$ARCPANEL/bin/v-change-sys-config-value" API_ALLOWED_IP allow-all
clear_panel_cache

ADMIN=$(new_key "$ROOT_USER" '')

# Foundation (spec §4)
check "GET /me answers for the administrator's key" 200 "$(call "$ADMIN" GET /me)"
check "GET /me: unrestricted" '*' "$(json '.data.commands | join(",")')"
check "a wrong secret answers 401" 401 "$(call "${ADMIN%%:*}:$(printf 'x%.0s' {1..40})" GET /me)"
check "401 carries the error code" E_UNAUTHENTICATED "$(json .error.code)"
check "an unknown path answers 404 with the error body" E_NOTEXIST "$(
	call "$ADMIN" GET /nothing > /dev/null
	json .error.code
)"
headers=$(curl -sk -D - -o /dev/null -H "Authorization: Bearer $ADMIN" -H 'X-Request-Id: smoke-1' "$API/me")
check "X-Request-Id is echoed" 1 "$(grep -ci '^x-request-id: smoke-1' <<< "$headers")"
check "ArcPanel-Version is sent" 1 "$(grep -ci '^arcpanel-version:' <<< "$headers")"

# Users (spec §6.1)
user_json="{\"username\": \"$SMOKE_USER\", \"password\": \"$SMOKE_PASS\", \"email\": \"$SMOKE_USER@example.invalid\", \"name\": \"Smoke User\", \"package\": \"default\"}"
check "createUser dry run answers 200" 200 "$(call "$ADMIN" POST '/users?dry_run=1' "$user_json")"
check "the dry run plans v-add-user" v-add-user "$(json '.data.steps[0].command')"
check "the dry run masks the password" '******' "$(json '.data.steps[0].args[1]')"
if "$ARCPANEL/bin/v-list-user" "$SMOKE_USER" > /dev/null 2>&1; then fail "the dry run created the user"; else ok "the dry run created nothing"; fi
check "createUser answers 201" 201 "$(call "$ADMIN" POST /users "$user_json")"
check "the new user is a user" user "$(json .data.role)"
check "a repeated createUser answers 409" 409 "$(call "$ADMIN" POST /users "$user_json")"
check "getUser answers 200" 200 "$(call "$ADMIN" GET "/users/$SMOKE_USER")"

READER=$(new_key "$SMOKE_USER" v2-read)
WRITER=$(new_key "$SMOKE_USER" v2-write)
check "a v2-read key reads its own account" 200 "$(call "$READER" GET "/users/$SMOKE_USER")"
check "a v2-read key may not change it" 403 "$(call "$READER" PATCH "/users/$SMOKE_USER" '{"name": "Changed"}')"
check "the refusal names the command" v-change-user-name "$(json '.error.commands | join(",")')"
check "the name did not change" "Smoke User" "$("$ARCPANEL/bin/v-list-user" "$SMOKE_USER" json | jq -r ".\"$SMOKE_USER\".NAME")"
check "a user key may not list users" 403 "$(call "$READER" GET /users)"
check "another user answers 404" 404 "$(call "$READER" GET "/users/$ROOT_USER")"
v1_exit=$(curl -sk -D - -o /dev/null -X POST --data "hash=$READER&cmd=v-list-sys-config&arg1=json" "$PANEL/api/" | grep -i '^arcpanel-exit-code:' | tr -dc '0-9')
check "API v1 refuses the commands of v2-read (E_FORBIDEN)" 10 "$v1_exit"
check "a v2-write key changes the name" 200 "$(call "$WRITER" PATCH "/users/$SMOKE_USER" '{"name": "Smoke Changed"}')"
check "the name changed" "Smoke Changed" "$("$ARCPANEL/bin/v-list-user" "$SMOKE_USER" json | jq -r ".\"$SMOKE_USER\".NAME")"
check "changeUserPassword answers 204" 204 "$(call "$WRITER" PUT "/users/$SMOKE_USER/password" "{\"password\": \"${SMOKE_PASS}B2\"}")"
check "suspendUser answers 200" 200 "$(call "$ADMIN" POST "/users/$SMOKE_USER/suspend")"
check "the user is suspended" true "$(json .data.suspended)"
check "a suspended owner's key answers 403" 403 "$(call "$WRITER" GET /me)"
check "unsuspendUser answers 200" 200 "$(call "$ADMIN" POST "/users/$SMOKE_USER/unsuspend")"
check "deleteUser answers 204" 204 "$(call "$ADMIN" DELETE "/users/$SMOKE_USER")"
check "the user is gone" 404 "$(call "$ADMIN" GET "/users/$SMOKE_USER")"

# Packages (spec §6.2)
package_value() { "$ARCPANEL/bin/v-list-user-package" "$1" json | jq -r ".\"$1\".$2"; }
check "createPackage dry run answers 200" 200 "$(call "$ADMIN" POST '/packages?dry_run=1' "{\"name\": \"$SMOKE_PACKAGE\"}")"
if "$ARCPANEL/bin/v-list-user-package" "$SMOKE_PACKAGE" > /dev/null 2>&1; then fail "the dry run created the package"; else ok "the dry run created no package"; fi
check "createPackage answers 201" 201 "$(call "$ADMIN" POST /packages "{\"name\": \"$SMOKE_PACKAGE\", \"limits\": {\"web_domains\": 3, \"disk_mb\": null}}")"
check "the package has the given limit" 3 "$(package_value "$SMOKE_PACKAGE" WEB_DOMAINS)"
check "null is unlimited" unlimited "$(package_value "$SMOKE_PACKAGE" DISK_QUOTA)"
check "fields left out take the form defaults" 5 "$(package_value "$SMOKE_PACKAGE" WEB_ALIASES)"
check "getPackage answers 200" 200 "$(call "$ADMIN" GET "/packages/$SMOKE_PACKAGE")"
check "getPackage: unlimited is null" null "$(json .data.limits.disk_mb)"
check "updatePackage answers 200" 200 "$(call "$ADMIN" PATCH "/packages/$SMOKE_PACKAGE" '{"limits": {"web_domains": 4}}')"
check "the limit changed" 4 "$(package_value "$SMOKE_PACKAGE" WEB_DOMAINS)"
check "the other limits kept their value" unlimited "$(package_value "$SMOKE_PACKAGE" DISK_QUOTA)"
check "copyPackage answers 201" 201 "$(call "$ADMIN" POST "/packages/$SMOKE_PACKAGE/copy" "{\"name\": \"$SMOKE_PACKAGE-copy\"}")"
check "deletePackage of the copy answers 204" 204 "$(call "$ADMIN" DELETE "/packages/$SMOKE_PACKAGE-copy")"
check "a user on the package" 201 "$(call "$ADMIN" POST /users "{\"username\": \"$SMOKE_USER\", \"password\": \"$SMOKE_PASS\", \"email\": \"$SMOKE_USER@example.invalid\", \"name\": \"Smoke User\", \"package\": \"$SMOKE_PACKAGE\"}")"
check "a package with users answers 409" 409 "$(call "$ADMIN" DELETE "/packages/$SMOKE_PACKAGE")"
check "the user is deleted" 204 "$(call "$ADMIN" DELETE "/users/$SMOKE_USER")"
check "deletePackage answers 204" 204 "$(call "$ADMIN" DELETE "/packages/$SMOKE_PACKAGE")"
check "the package is gone" 404 "$(call "$ADMIN" GET "/packages/$SMOKE_PACKAGE")"

# Web domains (spec §6.3)
SMOKE_DOMAIN="$SMOKE_USER.example.test"
WEB="/users/$SMOKE_USER/web-domains"
domain_value() { "$ARCPANEL/bin/v-list-web-domain" "$SMOKE_USER" "$SMOKE_DOMAIN" json | jq -r ".\"$SMOKE_DOMAIN\".$1"; }
check "a user for the web domains" 201 "$(call "$ADMIN" POST /users "$user_json")"
READER=$(new_key "$SMOKE_USER" v2-read)
WRITER=$(new_key "$SMOKE_USER" v2-write)
check "createWebDomain dry run answers 200" 200 "$(call "$WRITER" POST "$WEB?dry_run=1" "{\"domain\": \"$SMOKE_DOMAIN\"}")"
check "the dry run plans v-add-web-domain" v-add-web-domain "$(json '.data.steps[0].command')"
if "$ARCPANEL/bin/v-list-web-domain" "$SMOKE_USER" "$SMOKE_DOMAIN" > /dev/null 2>&1; then fail "the dry run created the domain"; else ok "the dry run created no domain"; fi
check "a v2-read key may not create a domain" 403 "$(call "$READER" POST "$WEB" "{\"domain\": \"$SMOKE_DOMAIN\"}")"
check "createWebDomain answers 201" 201 "$(call "$WRITER" POST "$WEB" "{\"domain\": \"$SMOKE_DOMAIN\", \"aliases\": [\"www.$SMOKE_DOMAIN\", \"alt.$SMOKE_DOMAIN\"]}")"
check "the domain has the given aliases" "alt.$SMOKE_DOMAIN,www.$SMOKE_DOMAIN" "$(domain_value ALIAS | tr ',' '\n' | sort | paste -sd,)"
check "a repeated createWebDomain answers 409" 409 "$(call "$WRITER" POST "$WEB" "{\"domain\": \"$SMOKE_DOMAIN\"}")"
check "listWebDomains lists it" "$SMOKE_DOMAIN" "$(
	call "$READER" GET "$WEB" > /dev/null
	json '.data[0].domain'
)"
check "getWebDomain answers 200 for a v2-read key" 200 "$(call "$READER" GET "$WEB/$SMOKE_DOMAIN")"
check "another user's domains answer 404" 404 "$(call "$READER" GET "/users/$ROOT_USER/web-domains")"
if [ "$(grep "^POLICY_USER_EDIT_WEB_TEMPLATES=" "$CONF" | cut -d"'" -f2)" = yes ]; then
	check "a user key changes templates with POLICY_USER_EDIT_WEB_TEMPLATES" 200 "$(call "$WRITER" PATCH "$WEB/$SMOKE_DOMAIN" '{"templates": {"web": "default"}}')"
else
	check "a user key may not change templates" 403 "$(call "$WRITER" PATCH "$WEB/$SMOKE_DOMAIN" '{"templates": {"web": "default"}}')"
fi
if [ -n "$(grep "^PROXY_SYSTEM=" "$CONF" | cut -d"'" -f2)" ]; then
	check "updateWebDomain answers 200 for the administrator" 200 "$(call "$ADMIN" PATCH "$WEB/$SMOKE_DOMAIN" '{"proxy_extensions": ["css", "js"]}')"
	check "the proxy extensions changed" "css,js" "$(domain_value PROXY_EXT)"
	check "purgeWebDomainCache answers 200" 200 "$(call "$WRITER" POST "$WEB/$SMOKE_DOMAIN/purge-cache")"
fi
check "suspendWebDomain answers 200" 200 "$(call "$WRITER" POST "$WEB/$SMOKE_DOMAIN/suspend")"
check "the domain is suspended" yes "$(domain_value SUSPENDED)"
check "unsuspendWebDomain answers 200" 200 "$(call "$WRITER" POST "$WEB/$SMOKE_DOMAIN/unsuspend")"
check "the domain is unsuspended" false "$(json .data.suspended)"
check "a user key may not rebuild" 403 "$(call "$WRITER" POST "$WEB/$SMOKE_DOMAIN/rebuild")"
check "rebuildWebDomain answers 200 for the administrator" 200 "$(call "$ADMIN" POST "$WEB/$SMOKE_DOMAIN/rebuild")"
check "deleteWebDomain answers 204" 204 "$(call "$WRITER" DELETE "$WEB/$SMOKE_DOMAIN")"
check "the domain is gone" 404 "$(call "$WRITER" GET "$WEB/$SMOKE_DOMAIN")"
check "the user of the web domains is deleted" 204 "$(call "$ADMIN" DELETE "/users/$SMOKE_USER")"

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "All checks passed"
