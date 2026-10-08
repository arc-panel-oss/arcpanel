#!/bin/bash
# Smoke test of REST API v2 (E34,
# docs/features/specs/2026-10-07-002-e34-api-v2-design.md) against a
# running ArcPanel server, through nginx and curl only.
#
# Turns the API on for every key and address for the run (API_SYSTEM=2,
# API_ALLOWED_IP=allow-all) and restores both at the end. Creates access
# keys and throw-away users (arcsmoke*), and deletes them.
#
# Batch 4 needs the internet (or NODE_NVM_URL and NODE_RELEASES_URL mirrors):
# it installs nvm and the Node.js LTS for a throw-away user. It installs a
# Docker app only when the server has the Docker engine (DOCKER_SYSTEM) and
# says so otherwise.
#
# Batch 5 uploads 16 MiB of random bytes in three chunks and downloads them
# again as a zip; it skips the file checks when FILE_MANAGER is off.
#
# Batch 6 adds and deletes a firewall rule (when FIREWALL_SYSTEM is on) and
# restarts the cron service; it changes no server setting.
#
# Run as root on a test server, never on production.

set -euo pipefail

PANEL="${PANEL:-https://127.0.0.1:8083}"
ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
API="$PANEL/api/v2"
CONF="$ARCPANEL/conf/arcpanel.conf"
SMOKE_USER="arcsmoke$RANDOM"
SMOKE_PACKAGE="arcsmoke-pkg$RANDOM"
APPS_USER="${SMOKE_USER}a"
FILES_USER="${SMOKE_USER}f"
ACCOUNTS_USER="${SMOKE_USER}c"
APPS_PACKAGE="$SMOKE_PACKAGE-apps"
SMOKE_PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
ROOT_USER=$(grep "^ROOT_USER=" "$CONF" | cut -d"'" -f2)
OLD_API_SYSTEM=$(grep "^API_SYSTEM=" "$CONF" | cut -d"'" -f2 || true)
OLD_API_ALLOWED_IP=$(grep "^API_ALLOWED_IP=" "$CONF" | cut -d"'" -f2 || true)
BODY="$(mktemp)"
CERT_DIR="$(mktemp -d)"
FILES_DIR="$(mktemp -d)"
failures=0

# The panel caches arcpanel.conf for a minute
clear_panel_cache() {
	runuser -u arcpanelweb -- "$ARCPANEL/php/bin/php" "$ARCPANEL/web-app/artisan" cache:clear > /dev/null
}

cleanup() {
	"$ARCPANEL/bin/v-delete-user" "$SMOKE_USER" > /dev/null 2>&1 || true
	# The apps user runs a pm2 daemon under systemd --user (E14)
	if id "$APPS_USER" > /dev/null 2>&1; then
		loginctl disable-linger "$APPS_USER" > /dev/null 2>&1 || true
		systemctl stop "user@$(id -u "$APPS_USER").service" > /dev/null 2>&1 || true
		pkill -9 -u "$APPS_USER" > /dev/null 2>&1 || true
	fi
	"$ARCPANEL/bin/v-delete-user" "$APPS_USER" > /dev/null 2>&1 || true
	"$ARCPANEL/bin/v-delete-user" "$FILES_USER" > /dev/null 2>&1 || true
	"$ARCPANEL/bin/v-delete-user" "$ACCOUNTS_USER" > /dev/null 2>&1 || true
	# A firewall rule left by a failed run (the last one first: ids are positions)
	for rule in $("$ARCPANEL/bin/v-list-firewall" json 2> /dev/null | jq -r 'to_entries[] | select(.value.COMMENT == "arcsmoke") | .key' | sort -rn); do
		"$ARCPANEL/bin/v-delete-firewall-rule" "$rule" > /dev/null 2>&1 || true
	done
	rm -f "/etc/logrotate.d/arcpanel-node-$APPS_USER"
	for package in "$SMOKE_PACKAGE" "$SMOKE_PACKAGE-copy" "$APPS_PACKAGE"; do
		"$ARCPANEL/bin/v-delete-user-package" "$package" > /dev/null 2>&1 || true
		"$ARCPANEL/bin/v-delete-package-tenancy" "$package" > /dev/null 2>&1 || true
	done
	# new_key runs in a command substitution and cannot record its keys: those of the
	# root administrator are found by their comment (the others go with their users)
	for key in $("$ARCPANEL/bin/v-list-access-keys" "$ROOT_USER" json 2> /dev/null | jq -r '.[] | select(.COMMENT == "arcsmoke") | .ACCESS_KEY_ID'); do
		"$ARCPANEL/bin/v-delete-access-key" "$key" > /dev/null 2>&1 || true
	done
	"$ARCPANEL/bin/v-change-sys-config-value" API_SYSTEM "${OLD_API_SYSTEM:-0}"
	"$ARCPANEL/bin/v-change-sys-config-value" API_ALLOWED_IP "$OLD_API_ALLOWED_IP"
	clear_panel_cache
	# A queued certificate of a smoke domain
	sed -i "/ $SMOKE_USER\.example\.test /d; / $APPS_USER\.example\.test /d" "$ARCPANEL/data/queue/letsencrypt.pipe" 2> /dev/null || true
	rm -rf "$BODY" "$CERT_DIR" "$FILES_DIR"
}
trap cleanup EXIT

ok() { echo "ok   - $1"; }
skip() { echo "skip - $1"; }
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
			succeeded)
				ok "task $task_id finished"
				return 0
				;;
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
# Logs (E37 spec §4.1)
check "getWebDomainLogs answers 200 for a v2-read key" 200 "$(call "$READER" GET "$WEB/$SMOKE_DOMAIN/logs?lines=20")"
check "getWebDomainLogs reads the error log by default" "error 20" "$(json '"\(.meta.type) \(.meta.lines)"')"
check "getWebDomainLogs reads the access log" access "$(
	call "$READER" GET "$WEB/$SMOKE_DOMAIN/logs?type=access" > /dev/null
	json '.meta.type'
)"
check "getWebDomainLogs refuses 0 lines" 422 "$(call "$READER" GET "$WEB/$SMOKE_DOMAIN/logs?lines=0")"
check "getWebDomainLogs answers 404 for an unknown domain" 404 "$(call "$READER" GET "$WEB/nope.$SMOKE_DOMAIN/logs")"
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
le_code=$(head -1 <<< "$le_headers" | awk '{print $2}')
check "requestWebDomainLetsEncrypt answers 202" 202 "$le_code"
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
	# A restore without a body restores everything, web domains included
	check "the web domain is deleted before a full restore" 204 "$(call "$WRITER" DELETE "$WEB/$SMOKE_DOMAIN")"
	check "restoreBackup without a body answers 202" 202 "$(call "$BACKUP_KEY" POST "$BK/$BK_NAME/restore")"
	FULL_TASK="$(json .data.id)"
	wait_v2_task "$BACKUP_KEY" "$FULL_TASK" 900 || true
	check "the full restore brings the web domain back" 200 "$(call "$WRITER" GET "$WEB/$SMOKE_DOMAIN")"
	check "deleteBackup answers 204" 204 "$(call "$BACKUP_KEY" DELETE "$BK/$BK_NAME")"
else
	fail "backup task did not succeed; skipping archive download and restore"
fi

check "the user of the web domains is deleted" 204 "$(call "$ADMIN" DELETE "/users/$SMOKE_USER")"

# Batch 4: ports, Node.js and Docker apps (spec 2026-10-08-002 §6)
conf_value() { grep "^$1=" "$CONF" | tail -n1 | cut -d"'" -f2; }
pkg_file="$(mktemp)"
sed "s/^SHELL=.*/SHELL='bash'/" "$ARCPANEL/data/packages/default.pkg" > "$pkg_file"
"$ARCPANEL/bin/v-add-user-package" "$pkg_file" "$APPS_PACKAGE" > /dev/null
rm -f "$pkg_file"
for setting in NODE=yes NODE_VERSIONS=1 'NODE_ALLOWED=*' NODE_APPS=1 PORTS=5 DOCKER=yes DOCKER_APPS=1; do
	"$ARCPANEL/bin/v-change-package-tenancy" "$APPS_PACKAGE" "${setting%%=*}" "${setting#*=}"
done
check "a user for the apps" 201 "$(call "$ADMIN" POST /users "{\"username\": \"$APPS_USER\", \"password\": \"$SMOKE_PASS\", \"email\": \"$APPS_USER@example.invalid\", \"name\": \"Smoke Apps\", \"package\": \"$APPS_PACKAGE\"}")"
APPS_KEY=$(new_key "$APPS_USER" v2-apps)
APPS_WRITER=$(new_key "$APPS_USER" v2-write)

# Ports (§4.5)
PORTS="/users/$APPS_USER/ports"
if [ "$(conf_value PORT_REGISTRY)" = no ]; then
	skip "ports: PORT_REGISTRY is off on this server"
else
	check "getPortRegistry answers 200 for the administrator" 200 "$(call "$ADMIN" GET /system/ports)"
	check "a user key may not read the registry" 403 "$(call "$APPS_KEY" GET /system/ports)"
	check "reservePort with only v2-apps answers 200" 200 "$(call "$APPS_KEY" POST "$PORTS/reserve" '{"proto": "tcp", "comment": "arcsmoke"}')"
	SMOKE_PORT="$(json .data.port)"
	check "the reserved port is manual" manual "$(json .data.app)"
	check "listPorts shows the reserved port" arcsmoke "$(
		call "$APPS_KEY" GET "$PORTS" > /dev/null
		json ".data[] | select(.port == \"$SMOKE_PORT\") | .comment"
	)"
	check "releasePort answers 200" 200 "$(call "$APPS_KEY" POST "$PORTS/$SMOKE_PORT/release")"
	check "the port is free again" null "$(json "[.data[] | select(.start <= ${SMOKE_PORT:-0} and .end >= ${SMOKE_PORT:-0})][0].app")"
fi

# Node.js runtimes and apps (§4.1, §4.2)
NODE="/users/$APPS_USER/node"
APPS_DOMAIN="$APPS_USER.example.test"
NODE_APP="/users/$APPS_USER/node-apps/$APPS_DOMAIN"
check "setNodeEnabled answers 200" 200 "$(call "$APPS_KEY" PUT "$NODE" '{"enabled": true}')"
check "installNodeVersion answers 202" 202 "$(call "$APPS_KEY" POST "$NODE/versions" '{"spec": "lts"}')"
NODE_TASK="$(json .data.id)"
if [[ "$NODE_TASK" == node:"$APPS_USER":* ]] && wait_v2_task "$APPS_KEY" "$NODE_TASK" 600; then
	check "a web domain for the Node.js app" 201 "$(call "$APPS_WRITER" POST "/users/$APPS_USER/web-domains" "{\"domain\": \"$APPS_DOMAIN\"}")"
	app_dir="/home/$APPS_USER/web/$APPS_DOMAIN/public_html"
	cat > "$app_dir/server.js" << 'EOF'
const http = require('http');
http.createServer((req, res) => {
	console.log('arcsmoke request ' + req.url);
	res.end('arcsmoke');
}).listen(process.env.PORT, process.env.HOST);
EOF
	printf '%s\n' '{"name": "arcsmoke", "version": "1.0.0", "private": true, "scripts": {"start": "node server.js"}}' > "$app_dir/package.json"
	chown "$APPS_USER:$APPS_USER" "$app_dir/server.js" "$app_dir/package.json"
	check "createNodeApp answers 201" 201 "$(call "$APPS_KEY" POST "/users/$APPS_USER/node-apps" "{\"domain\": \"$APPS_DOMAIN\", \"start\": \"server.js\"}")"
	check "installNodeApp answers 202" 202 "$(call "$APPS_KEY" POST "$NODE_APP/install")"
	INSTALL_TASK="$(json .data.id)"
	wait_v2_task "$APPS_KEY" "$INSTALL_TASK" 600 || true
	check "startNodeApp answers 200" 200 "$(call "$APPS_KEY" POST "$NODE_APP/start")"
	check "getNodeAppLogs answers 200" 200 "$(call "$APPS_KEY" GET "$NODE_APP/logs?lines=20")"
	check "the log answer has a cursor" 1 "$([ -n "$(json '.meta.next // empty')" ] && echo 1 || echo 0)"
	check "stopNodeApp answers 200" 200 "$(call "$APPS_KEY" POST "$NODE_APP/stop")"
	check "deleteNodeApp answers 204" 204 "$(call "$APPS_KEY" DELETE "$NODE_APP")"
	check "the Node.js app is gone" 404 "$(call "$APPS_KEY" GET "$NODE_APP")"
else
	fail "the Node.js runtime was not installed (task '$NODE_TASK'); skipping the Node.js app"
fi

# Docker catalog and apps (§4.3)
check "listDockerCatalog answers 200" 200 "$(call "$APPS_KEY" GET /docker-catalog)"
check "the catalog lists apps" 1 "$([ "$(json '.data | length')" -gt 0 ] && echo 1 || echo 0)"
DOCKER_SLUG="$(json '[.data[] | select(.admin_only | not)][0].slug')"
if [ -z "$(conf_value DOCKER_SYSTEM)" ]; then
	skip "Docker app install and delete: the Docker engine is not installed (DOCKER_SYSTEM is empty)"
else
	DOCKER_APP="/users/$APPS_USER/docker-apps"
	check "createDockerApp answers 202" 202 "$(call "$APPS_KEY" POST "$DOCKER_APP" "{\"slug\": \"$DOCKER_SLUG\", \"name\": \"arcsmoke\"}")"
	DOCKER_TASK="$(json .data.id)"
	wait_v2_task "$APPS_KEY" "$DOCKER_TASK" 900 || true
	check "getDockerApp answers 200" 200 "$(call "$APPS_KEY" GET "$DOCKER_APP/arcsmoke")"
	check "deleteDockerApp answers 204" 204 "$(call "$APPS_KEY" DELETE "$DOCKER_APP/arcsmoke")"
fi

check "the apps user is deleted" 204 "$(call "$ADMIN" DELETE "/users/$APPS_USER")"

# Batch 5: files (spec 2026-10-08-003 §6)
FILES="/users/$FILES_USER/files"
# put_chunk KEY TOKEN INDEX FILE -> prints the status of uploadChunk
put_chunk() {
	curl -sk -o "$BODY" -w '%{http_code}' -X PUT -H "Authorization: Bearer $1" -H 'Accept: application/json' \
		-H 'Content-Type: application/octet-stream' --data-binary "@$4" "$API$FILES/uploads/$2/$3"
}
if [ "$(conf_value FILE_MANAGER)" != true ]; then
	skip "files: FILE_MANAGER is off on this server"
else
	check "a user for the files" 201 "$(call "$ADMIN" POST /users "{\"username\": \"$FILES_USER\", \"password\": \"$SMOKE_PASS\", \"email\": \"$FILES_USER@example.invalid\", \"name\": \"Smoke Files\", \"package\": \"default\"}")"
	FILES_HOME="$("$ARCPANEL/bin/v-list-user" "$FILES_USER" json | jq -r ".\"$FILES_USER\".HOME")"
	FILES_KEY=$(new_key "$FILES_USER" v2-files)
	OTHER_FILES_KEY=$(new_key "$FILES_USER" v2-files)
	FILES_READER=$(new_key "$FILES_USER" v2-read)
	check "createDirectory with only v2-files answers 201" 201 "$(call "$FILES_KEY" POST "$FILES/directories" '{"path": "tmp", "name": "arcsmoke"}')"

	# Uploads (§4.3): a file a bit over 16 MiB goes in three chunks. The home itself
	# belongs to root (SFTP jail): the smoke works in ~/tmp
	head -c $((16 * 1048576 + 4096)) /dev/urandom > "$FILES_DIR/big.bin"
	big_size=$(stat -c %s "$FILES_DIR/big.bin")
	big_hash=$(sha256sum "$FILES_DIR/big.bin" | cut -d' ' -f1)
	upload_json="{\"path\": \"tmp/arcsmoke\", \"name\": \"big.bin\", \"size\": $big_size, \"modified_at\": 1759658400000}"
	check "startUpload answers 200" 200 "$(call "$FILES_KEY" POST "$FILES/uploads" "$upload_json")"
	UPLOAD_TOKEN="$(json .data.token)"
	chunk_size="$(json .data.chunk_size)"
	check "the file takes three chunks" 3 "$(json .data.chunks)"
	for index in 0 1 2; do
		dd if="$FILES_DIR/big.bin" of="$FILES_DIR/chunk" bs="$chunk_size" skip="$index" count=1 status=none
		check "uploadChunk $index answers 204" 204 "$(put_chunk "$FILES_KEY" "$UPLOAD_TOKEN" "$index" "$FILES_DIR/chunk")"
	done
	check "another key of the same user gets 404 for the session" 404 "$(put_chunk "$OTHER_FILES_KEY" "$UPLOAD_TOKEN" 2 "$FILES_DIR/chunk")"
	check "startUpload again resumes the session" "$UPLOAD_TOKEN" "$(
		call "$FILES_KEY" POST "$FILES/uploads" "$upload_json" > /dev/null
		json .data.token
	)"
	check "the resumed session holds the three chunks" "0,1,2" "$(json '.data.received | join(",")')"
	check "finishUpload answers 201" 201 "$(call "$FILES_KEY" POST "$FILES/uploads/$UPLOAD_TOKEN/finish")"
	check "the uploaded file is in the home" "$big_hash" "$(sha256sum "$FILES_HOME/tmp/arcsmoke/big.bin" | cut -d' ' -f1)"
	check "the uploaded file belongs to the user" "$FILES_USER" "$(stat -c %U "$FILES_HOME/tmp/arcsmoke/big.bin")"
	check "finishUpload again answers 404" 404 "$(call "$FILES_KEY" POST "$FILES/uploads/$UPLOAD_TOKEN/finish")"

	# Reads and changes (§4.1, §4.2)
	check "saveFile creates a text file" 200 "$(call "$FILES_KEY" PUT "$FILES/content" '{"path": "tmp/arcsmoke/note.txt", "content": "hello\n", "hash": null}')"
	check "readFile answers 200" 200 "$(call "$FILES_READER" GET "$FILES/content?path=tmp/arcsmoke/note.txt")"
	check "readFile answers the content" "hello" "$(json .data.content)"
	note_hash="$(json .data.hash)"
	check "saveFile with the hash read answers 200" 200 "$(call "$FILES_KEY" PUT "$FILES/content" "{\"path\": \"tmp/arcsmoke/note.txt\", \"content\": \"hello again\\n\", \"hash\": \"$note_hash\"}")"
	check "saveFile with a stale hash answers 409" E_INUSE "$(
		call "$FILES_KEY" PUT "$FILES/content" "{\"path\": \"tmp/arcsmoke/note.txt\", \"content\": \"lost\", \"hash\": \"$note_hash\"}" > /dev/null
		json .error.code
	)"
	check "copyFiles within the folder answers 200" 200 "$(call "$FILES_KEY" POST "$FILES/copy" '{"paths": ["tmp/arcsmoke/note.txt"], "destination": "tmp/arcsmoke"}')"
	check "the copy gets a free name" "note (1).txt" "$(json '.data.names[0]')"
	check "compressFiles answers 201" 201 "$(call "$FILES_KEY" POST "$FILES/compress" '{"paths": ["tmp/arcsmoke/big.bin", "tmp/arcsmoke/note.txt"], "name": "pack.zip"}')"
	check "extractArchive into a new folder answers 201" 201 "$(call "$FILES_KEY" POST "$FILES/extract" '{"path": "tmp/arcsmoke/pack.zip", "destination": "tmp/arcsmoke/unpacked"}')"
	check "the extracted file is the uploaded one" "$big_hash" "$(sha256sum "$FILES_HOME/tmp/arcsmoke/unpacked/big.bin" | cut -d' ' -f1)"
	check "extractArchive into an existing folder answers 409" 409 "$(call "$FILES_KEY" POST "$FILES/extract" '{"path": "tmp/arcsmoke/pack.zip", "destination": "tmp/arcsmoke/unpacked"}')"

	# Downloads through nginx (§4.4)
	dl_code=$(curl -sk -D "$FILES_DIR/headers" -o "$FILES_DIR/unpacked.zip" -w '%{http_code}' -X POST -H "Authorization: Bearer $FILES_KEY" \
		-H 'Content-Type: application/json' --data '{"paths": ["tmp/arcsmoke/unpacked"]}' "$API$FILES/download")
	check "downloadFiles of a folder answers 200" 200 "$dl_code"
	check "the folder comes as a zip" 1 "$(grep -ci '^content-type: application/zip' "$FILES_DIR/headers")"
	check "the zip is larger than the file" 1 "$([ "$(stat -c %s "$FILES_DIR/unpacked.zip")" -gt "$big_size" ] && echo 1 || echo 0)"
	check "the zip holds the uploaded file" "$big_hash" "$(unzip -p "$FILES_DIR/unpacked.zip" unpacked/big.bin | sha256sum | cut -d' ' -f1)"
	dl_code=$(curl -sk -o "$FILES_DIR/note.txt" -w '%{http_code}' -X POST -H "Authorization: Bearer $FILES_READER" \
		-H 'Content-Type: application/json' --data '{"paths": ["tmp/arcsmoke/note.txt"]}' "$API$FILES/download")
	check "a v2-read key downloads one file" 200 "$dl_code"
	check "the file comes as it is" "hello again" "$(cat "$FILES_DIR/note.txt")"
	check "a path above the home answers 404" 404 "$(call "$FILES_READER" GET "$FILES/content?path=../../etc/passwd")"
	check "a download above the home answers 404" 404 "$(call "$FILES_READER" POST "$FILES/download" '{"paths": ["../../etc/passwd"]}')"

	check "deleteFiles answers 204" 204 "$(call "$FILES_KEY" POST "$FILES/delete" '{"paths": ["tmp/arcsmoke"]}')"
	if [ -e "$FILES_HOME/tmp/arcsmoke" ]; then fail "the folder is still there"; else ok "the folder is gone"; fi
	check "the files user is deleted" 204 "$(call "$ADMIN" DELETE "/users/$FILES_USER")"
fi

# Batch 6: account and server (spec 2026-10-08-004 §7)
check "a user for the account endpoints" 201 "$(call "$ADMIN" POST /users "{\"username\": \"$ACCOUNTS_USER\", \"password\": \"$SMOKE_PASS\", \"email\": \"$ACCOUNTS_USER@example.invalid\", \"name\": \"Smoke Accounts\", \"package\": \"default\"}")"
ACCOUNTS="/users/$ACCOUNTS_USER"
ACCOUNTS_KEY=$(new_key "$ACCOUNTS_USER" v2-read,v2-accounts)

# Logs and statistics (§4.1, §4.2)
check "listUserLogs answers 200" 200 "$(call "$ACCOUNTS_KEY" GET "$ACCOUNTS/logs?limit=5")"
check "the logs are a list" array "$(json '.data | type')"
check "the logs page has its limit" 5 "$(json .meta.limit)"
check "listUserAuthLogs answers 200" 200 "$(call "$ACCOUNTS_KEY" GET "$ACCOUNTS/auth-logs")"
check "listUserStats answers 200" 200 "$(call "$ACCOUNTS_KEY" GET "$ACCOUNTS/stats")"
check "listStats answers 200 to the administrator" 200 "$(call "$ADMIN" GET /stats)"
check "listStats answers 403 to a user" 403 "$(call "$ACCOUNTS_KEY" GET /stats)"

# Notifications (§4.3)
"$ARCPANEL/bin/v-add-user-notification" "$ACCOUNTS_USER" 'Arcsmoke notice' 'A notice of the API v2 smoke test.'
check "listNotifications answers 200" 200 "$(call "$ACCOUNTS_KEY" GET /notifications)"
NOTICE_ID="$(json '[.data[] | select(.title == "Arcsmoke notice")][0].id')"
check "the notice is not acknowledged" false "$(json "[.data[] | select(.id == ${NOTICE_ID:-0})][0].acknowledged")"
check "acknowledgeNotification answers 204" 204 "$(call "$ACCOUNTS_KEY" POST "/notifications/$NOTICE_ID/acknowledge")"
check "the notice is acknowledged" true "$(
	call "$ACCOUNTS_KEY" GET /notifications > /dev/null
	json "[.data[] | select(.id == ${NOTICE_ID:-0})][0].acknowledged"
)"
check "deleteNotification answers 204" 204 "$(call "$ACCOUNTS_KEY" DELETE "/notifications/$NOTICE_ID")"

# SSH keys (§4.4)
ssh-keygen -q -t ed25519 -N '' -C arcsmoke@smoke.test -f "$FILES_DIR/smoke_ed25519"
ssh_json="$(jq -n --arg key "$(cat "$FILES_DIR/smoke_ed25519.pub")" '{key: $key}')"
check "addSshKey answers 201" 201 "$(call "$ACCOUNTS_KEY" POST "$ACCOUNTS/ssh-keys" "$ssh_json")"
check "the same key again answers 409" 409 "$(call "$ACCOUNTS_KEY" POST "$ACCOUNTS/ssh-keys" "$ssh_json")"
check "listSshKeys holds the key" 1 "$(
	call "$ACCOUNTS_KEY" GET "$ACCOUNTS/ssh-keys" > /dev/null
	json '[.data[] | select(.id == "arcsmoke@smoke.test")] | length'
)"
check "deleteSshKey answers 204" 204 "$(call "$ACCOUNTS_KEY" DELETE "$ACCOUNTS/ssh-keys/arcsmoke%40smoke.test")"

# Access keys (§4.6, D62): a v2-read+v2-accounts key gives a v2-read key, which deletes itself
check "createAccessKey answers 201" 201 "$(call "$ACCOUNTS_KEY" POST "$ACCOUNTS/access-keys" '{"permissions": ["v2-read"], "comment": "arcsmoke-child"}')"
CHILD_KEY="$(json '"\(.data.id):\(.data.secret)"')"
check "the new key holds v2-read" v2-read "$(json '.data.permissions | join(",")')"
check "a set the key does not hold answers 403" 403 "$(call "$ACCOUNTS_KEY" POST "$ACCOUNTS/access-keys" '{"permissions": ["v2-web"], "comment": "arcsmoke-refused"}')"
check "listAccessKeys never holds a secret" false "$(
	call "$ACCOUNTS_KEY" GET "$ACCOUNTS/access-keys" > /dev/null
	json '[.data[] | has("secret")] | any'
)"
check "the new key reads its account" 200 "$(call "$CHILD_KEY" GET "$ACCOUNTS")"
check "the new key deletes itself" 204 "$(call "$CHILD_KEY" DELETE "$ACCOUNTS/access-keys/${CHILD_KEY%%:*}")"
check "the deleted key answers 401" 401 "$(call "$CHILD_KEY" GET /me)"

# Firewall rules (§5.3, D59): ids are positions
if [ -z "$(conf_value FIREWALL_SYSTEM)" ]; then
	skip "firewall: FIREWALL_SYSTEM is off on this server"
else
	rule_json='{"action": "ACCEPT", "protocol": "TCP", "port": "65001", "source": "192.0.2.10", "comment": "arcsmoke"}'
	check "createFirewallRule answers 201" 201 "$(call "$ADMIN" POST /system/firewall/rules "$rule_json")"
	RULE_ID="$(json .data.id)"
	check "the same rule again answers 409" 409 "$(call "$ADMIN" POST /system/firewall/rules "$rule_json")"
	check "moveFirewallRule up answers 200" 200 "$(call "$ADMIN" POST "/system/firewall/rules/$RULE_ID/move" '{"direction": "up"}')"
	check "the moved rule takes the position before" "$((RULE_ID - 1))" "$(json .data.id)"
	check "deleteFirewallRule answers 204" 204 "$(call "$ADMIN" DELETE "/system/firewall/rules/$((RULE_ID - 1))")"
	check "the rule is gone" 0 "$("$ARCPANEL/bin/v-list-firewall" json | jq '[.[] | select(.COMMENT == "arcsmoke")] | length')"
fi

# Services (§5.1)
if "$ARCPANEL/bin/v-list-sys-services" json | jq -e 'has("cron")' > /dev/null; then
	check "restartService cron answers 200" 200 "$(call "$ADMIN" POST /system/services/cron/restart)"
	check "cron runs again" true "$(json .data.running)"
else
	skip "services: this server lists no cron service"
fi
check "stopService of arcpanel answers 403" 403 "$(call "$ADMIN" POST /system/services/arcpanel/stop)"

# Server settings (§5.7, D58): no secret in the answer; stored values are compared here, never printed
check "getServerSettings answers 200" 200 "$(call "$ADMIN" GET /system/settings)"
check "the settings hold no PHPMYADMIN_KEY field" false "$(json '[.. | objects | has("PHPMYADMIN_KEY")] | any')"
pma_key="$(conf_value PHPMYADMIN_KEY)"
if [ -n "$pma_key" ] && grep -qF -- "$pma_key" "$BODY"; then fail "the settings hold the phpMyAdmin key"; else ok "the settings hold no phpMyAdmin key"; fi
if grep -q 'PRIVATE KEY' "$BODY"; then fail "the settings hold a private key"; else ok "the settings hold no private key"; fi
check "the panel key answers a set flag" boolean "$(json '.data.ssl.key.set | type')"
check "a key may not change the API switches" 403 "$(call "$ADMIN" PATCH /system/settings/security '{"API_SYSTEM": "0"}')"

check "the accounts user is deleted" 204 "$(call "$ADMIN" DELETE "$ACCOUNTS")"

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "All checks passed"
