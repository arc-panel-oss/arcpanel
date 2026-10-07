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
CERT_DIR="$(mktemp -d)"
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
	# A queued certificate of a smoke domain
	sed -i "/ $SMOKE_USER\.example\.test /d" "$ARCPANEL/data/queue/letsencrypt.pipe" 2> /dev/null || true
	rm -rf "$BODY" "$CERT_DIR"
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

# wait_v2_task KEY TASK_ID [MAX_SECONDS] — poll GET /tasks/{id}, drive the backup queue
wait_v2_task() {
	local key="$1" task_id="$2" max="${3:-600}" i st
	for i in $(seq 1 "$max"); do
		"$ARCPANEL/bin/v-update-sys-queue" backup > /dev/null 2>&1 || true
		call "$key" GET "/tasks/$task_id" > /dev/null
		st="$(json .data.status)"
		case "$st" in
			succeeded) ok "task $task_id finished"; return 0 ;;
			failed)
				fail "task $task_id failed ($(json '.data.log' | tail -c 500))"
				return 1
				;;
		esac
		sleep 2
	done
	fail "task $task_id timed out after ${max}s"
	return 1
}

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

# Aliases, SSL and Let's Encrypt (spec §6.3)
check "addWebDomainAlias answers 200" 200 "$(call "$WRITER" POST "$WEB/$SMOKE_DOMAIN/aliases" "{\"alias\": \"new.$SMOKE_DOMAIN\"}")"
check "the alias was added" 1 "$(domain_value ALIAS | tr ',' '\n' | grep -cx "new.$SMOKE_DOMAIN")"
check "a repeated alias answers 409" 409 "$(call "$WRITER" POST "$WEB/$SMOKE_DOMAIN/aliases" "{\"alias\": \"new.$SMOKE_DOMAIN\"}")"
check "deleteWebDomainAlias answers 204" 204 "$(call "$WRITER" DELETE "$WEB/$SMOKE_DOMAIN/aliases/new.$SMOKE_DOMAIN")"
check "the alias was deleted" 0 "$(domain_value ALIAS | tr ',' '\n' | grep -cx "new.$SMOKE_DOMAIN" || true)"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$SMOKE_DOMAIN" -keyout "$CERT_DIR/key.pem" -out "$CERT_DIR/crt.pem" > /dev/null 2>&1
ssl_json=$(jq -n --rawfile c "$CERT_DIR/crt.pem" --rawfile k "$CERT_DIR/key.pem" '{certificate: $c, key: $k, force: true}')
check "setWebDomainSsl installs an uploaded certificate" 200 "$(call "$WRITER" PUT "$WEB/$SMOKE_DOMAIN/ssl" "$ssl_json")"
check "the domain has SSL with forced HTTPS" "yes yes" "$(domain_value SSL) $(domain_value SSL_FORCE)"
check "the answer describes the certificate" "$SMOKE_DOMAIN" "$(json .data.ssl.certificate.subject)"
check "setWebDomainSsl changes HSTS alone" 200 "$(call "$WRITER" PUT "$WEB/$SMOKE_DOMAIN/ssl" '{"hsts": true}')"
check "HSTS is on" yes "$(domain_value SSL_HSTS)"
check "deleteWebDomainSsl answers 204" 204 "$(call "$WRITER" DELETE "$WEB/$SMOKE_DOMAIN/ssl")"
check "the domain has no SSL" no "$(domain_value SSL)"
check "requestWebDomainLetsEncrypt answers 202" 202 "$(call "$WRITER" POST "$WEB/$SMOKE_DOMAIN/letsencrypt" '{"key_algorithm": "ecdsa-384"}')"
check "the certificate is queued with its key type" 1 "$(grep -c " $SMOKE_DOMAIN .* no ecdsa-384$" "$ARCPANEL/data/queue/letsencrypt.pipe")"
sed -i "/ $SMOKE_DOMAIN /d" "$ARCPANEL/data/queue/letsencrypt.pipe"
check "deleteWebDomain answers 204" 204 "$(call "$WRITER" DELETE "$WEB/$SMOKE_DOMAIN")"
check "the domain is gone" 404 "$(call "$WRITER" GET "$WEB/$SMOKE_DOMAIN")"

# Batch 2: DNS, mail, databases and cron (spec 2026-10-07-003)
DNS_KEY=$(new_key "$SMOKE_USER" v2-dns)
ZONE="/users/$SMOKE_USER/dns-zones"
check "createDnsZone with only v2-dns answers 201" 201 "$(call "$DNS_KEY" POST "$ZONE" "{\"domain\": \"$SMOKE_DOMAIN\"}")"
check "createDnsRecord answers 201" 201 "$(call "$DNS_KEY" POST "$ZONE/$SMOKE_DOMAIN/records" '{"name": "_acme-challenge", "type": "TXT", "value": "smoke-token"}')"
RECORD_ID="$(json .data.id)"
check "the TXT record is found by name and type" 1 "$(
	call "$DNS_KEY" GET "$ZONE/$SMOKE_DOMAIN/records?name=_acme-challenge&type=TXT" > /dev/null
	json '.meta.total'
)"
check "deleteDnsRecord answers 204" 204 "$(call "$DNS_KEY" DELETE "$ZONE/$SMOKE_DOMAIN/records/$RECORD_ID")"
check "deleteDnsZone answers 204" 204 "$(call "$DNS_KEY" DELETE "$ZONE/$SMOKE_DOMAIN")"

MAIL_KEY=$(new_key "$SMOKE_USER" v2-mail)
MAIL="/users/$SMOKE_USER/mail-domains"
check "createMailDomain with only v2-mail answers 201" 201 "$(call "$MAIL_KEY" POST "$MAIL" "{\"domain\": \"$SMOKE_DOMAIN\"}")"
check "createMailAccount answers 201" 201 "$(call "$MAIL_KEY" POST "$MAIL/$SMOKE_DOMAIN/accounts" '{"account": "info", "password": "SmokePass1"}')"
check "the mail domain needs an MX record" MX "$(
	call "$MAIL_KEY" GET "$MAIL/$SMOKE_DOMAIN/dns-records" > /dev/null
	json '.data[] | select(.type=="MX") | .type'
)"
check "requestMailDomainLetsEncrypt answers 202" 202 "$(call "$MAIL_KEY" POST "$MAIL/$SMOKE_DOMAIN/letsencrypt" '{"key_algorithm": "ecdsa-256"}')"
check "the mail certificate is queued" 1 "$(grep -c " $SMOKE_DOMAIN '' yes " "$ARCPANEL/data/queue/letsencrypt.pipe")"
sed -i "/ $SMOKE_DOMAIN /d" "$ARCPANEL/data/queue/letsencrypt.pipe"
check "deleteMailAccount answers 204" 204 "$(call "$MAIL_KEY" DELETE "$MAIL/$SMOKE_DOMAIN/accounts/info")"
check "deleteMailDomain answers 204" 204 "$(call "$MAIL_KEY" DELETE "$MAIL/$SMOKE_DOMAIN")"

DB_KEY=$(new_key "$SMOKE_USER" v2-db)
DB="/users/$SMOKE_USER/databases"
check "createDatabase with only v2-db answers 201" 201 "$(call "$DB_KEY" POST "$DB" '{"name": "app", "db_user": "app", "password": "SmokePass1"}')"
check "the database name has the owner prefix" "${SMOKE_USER}_app" "$(json .data.name)"
SMOKE_DB="${SMOKE_USER}_app"
DUMP_FILE="$(mktemp)"
dump_code=$(curl -sk -o "$DUMP_FILE" -w '%{http_code}' -X POST -H "Authorization: Bearer $DB_KEY" -H 'Accept: application/json' "$API$DB/$SMOKE_DB/dump")
dump_size=$(stat -c%s "$DUMP_FILE" 2> /dev/null || echo 0)
check "dumpDatabase answers with a gzip body" 200 "$dump_code"
check "the database dump is not empty" 1 "$([ "$dump_size" -gt 100 ] && echo 1 || echo 0)"
rm -f "$DUMP_FILE"
check "deleteDatabase answers 204" 204 "$(call "$DB_KEY" DELETE "$DB/$SMOKE_DB")"

CRON_KEY=$(new_key "$SMOKE_USER" v2-cron)
CRON="/users/$SMOKE_USER/cron-jobs"
check "createCronJob with only v2-cron answers 201" 201 "$(call "$CRON_KEY" POST "$CRON" '{"schedule": "*/5 * * * *", "command": "echo arcsmoke"}')"
CRON_ID="$(json .data.id)"
check "a duplicate cron job answers 409" 409 "$(call "$CRON_KEY" POST "$CRON" '{"schedule": "*/5 * * * *", "command": "echo arcsmoke"}')"
check "the duplicate points at the job" "/api/v2$CRON/$CRON_ID" "$(json .error.existing)"
check "deleteCronJob answers 204" 204 "$(call "$CRON_KEY" DELETE "$CRON/$CRON_ID")"

# Batch 3: tasks, backups and downloads (spec 2026-10-08-001 §8)
WEB="/users/$SMOKE_USER/web-domains"
BK="/users/$SMOKE_USER/backups"
check "a web domain for the backup smoke" 201 "$(call "$WRITER" POST "$WEB" "{\"domain\": \"$SMOKE_DOMAIN\"}")"
le_headers=$(curl -sk -D - -o /dev/null -X POST \
	-H "Authorization: Bearer $WRITER" -H 'Accept: application/json' -H 'Content-Type: application/json' \
	--data '{"key_algorithm": "ecdsa-384"}' "$API$WEB/$SMOKE_DOMAIN/letsencrypt")
check "requestWebDomainLetsEncrypt sends Link rel=monitor" 1 "$(grep -ci 'link:.*tasks/.*rel="monitor"' <<< "$le_headers")"
sed -i "/ $SMOKE_DOMAIN /d" "$ARCPANEL/data/queue/letsencrypt.pipe"

BACKUP_KEY=$(new_key "$SMOKE_USER" v2-backup)
check "createBackup answers 202" 202 "$(call "$BACKUP_KEY" POST "$BK")"
BK_TASK="$(json .data.id)"
if [[ "$BK_TASK" == queue:"$SMOKE_USER":* ]]; then ok "createBackup returns a queue task id"; else fail "createBackup task id (got '$BK_TASK')"; fi
wait_v2_task "$BACKUP_KEY" "$BK_TASK" 900
if [ "$(call "$BACKUP_KEY" GET "/tasks/$BK_TASK")" = 200 ] && [ "$(json .data.status)" = succeeded ]; then
	BK_NAME="$(call "$BACKUP_KEY" GET "$BK" > /dev/null && json '.data[0].name')"
	check "listBackups finds the new archive" "$SMOKE_USER." "${BK_NAME%%.*}."
	BK_DL="$(mktemp)"
	dl_bytes=$(curl -sk -o "$BK_DL" -w '%{size_download}' -H "Authorization: Bearer $BACKUP_KEY" "$API$BK/$BK_NAME/download")
	check "downloadBackup returns bytes" 1 "$([ "${dl_bytes:-0}" -gt 100 ] && echo 1 || echo 0)"
	rm -f "$BK_DL"
	check "restoreBackup of one web domain answers 202" 202 "$(call "$BACKUP_KEY" POST "$BK/$BK_NAME/restore" "{\"web\": [\"$SMOKE_DOMAIN\"]}")"
	RS_TASK="$(json .data.id)"
	wait_v2_task "$BACKUP_KEY" "$RS_TASK" 900 || true
	check "deleteBackup answers 204" 204 "$(call "$BACKUP_KEY" DELETE "$BK/$BK_NAME")"
else
	fail "backup task did not succeed; skipping archive download and restore"
fi

check "the user of the web domains is deleted" 204 "$(call "$ADMIN" DELETE "/users/$SMOKE_USER")"

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "All checks passed"
