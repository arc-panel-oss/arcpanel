#!/usr/bin/env bats
# Per-key address lists and expiry (E43 M2, B6) and the v2-observe set (B3),
# docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §6, §9.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

PANEL="https://127.0.0.1:8083"
KEY_USER="arcbatskr"
KEYS_DIR="/usr/local/arcpanel/data/access-keys"

config() {
	v-list-sys-config json | jq -r ".config.$1"
}

# new_key SETS [ALLOWED_IPS] [EXPIRES]: the json answer of v-add-access-key
new_key() {
	if [ "$#" -gt 1 ]; then
		v-add-access-key "$KEY_USER" "$1" kr-bats "$2" "${3:-}" json
	else
		v-add-access-key "$KEY_USER" "$1" kr-bats json
	fi
}

key_id() { echo "$1" | jq -r .ACCESS_KEY_ID; }
key_secret() { echo "$1" | jq -r .SECRET_ACCESS_KEY; }

# Move the expiry of a key into the past, as time would
expire_key() {
	sed -i "s/^EXPIRES=.*/EXPIRES='$(($(date +%s) - 60))'/" "$KEYS_DIR/$1"
}

# check_v2 ID SECRET IP: runs v-check-access-key-permissions with the secret in a file
check_v2() {
	local file
	file="$(mktemp /tmp/arcbats.XXXXXX)"
	echo "$2" > "$file"
	run v-check-access-key-permissions "$1" "$file" "$3" json
	rm -f "$file"
}

# v2_status ID SECRET: the HTTP status of GET /api/v2/me; the body is in $BATS_FILE_TMPDIR/body
v2_status() {
	curl -sk -o "$BATS_FILE_TMPDIR/body" -w '%{http_code}' -H "Authorization: Bearer $1:$2" -H 'Accept: application/json' "$PANEL/api/v2/me"
}

# v1_post ID SECRET CMD: the body, then the status on its own line
v1_post() {
	curl -sk -X POST -w '\n%{http_code}' --data-urlencode "access_key=$1" --data-urlencode "secret_key=$2" \
		--data-urlencode "returncode=no" --data-urlencode "cmd=$3" --data-urlencode "arg1=$KEY_USER" --data-urlencode "arg2=json" \
		"$PANEL/api/index.php"
}

setup_file() {
	local pass
	pass="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
	v-add-user "$KEY_USER" "$pass" "$KEY_USER@example.invalid" default > /dev/null
	{
		echo "v-change-sys-config-value API '$(config API)'"
		echo "v-change-sys-config-value API_V1 '$(config API_V1)'"
		echo "v-change-sys-config-value API_SYSTEM '$(config API_SYSTEM)'"
		echo "v-change-sys-config-value API_ALLOWED_IP '$(config API_ALLOWED_IP)'"
	} > "$BATS_FILE_TMPDIR/restore-config"
	v-change-sys-config-value API_SYSTEM 2
	v-change-sys-config-value API_ALLOWED_IP allow-all
	v-change-sys-config-value API_V1 yes
	# The panel caches arcpanel.conf for a minute
	runuser -u arcpanelweb -- /usr/local/arcpanel/php/bin/php /usr/local/arcpanel/web-app/artisan cache:clear > /dev/null
}

teardown_file() {
	local id
	for id in $(v-list-access-keys "$KEY_USER" json 2> /dev/null | jq -r 'keys[]'); do
		v-delete-access-key "$id" > /dev/null 2>&1
	done
	v-delete-user "$KEY_USER" > /dev/null 2>&1 || true
	bash "$BATS_FILE_TMPDIR/restore-config"
	runuser -u arcpanelweb -- /usr/local/arcpanel/php/bin/php /usr/local/arcpanel/web-app/artisan cache:clear > /dev/null
}

@test "v-add-access-key stores the address list and the expiry, and the key commands print them" {
	local expires key id
	expires=$(($(date +%s) + 86400))
	key="$(new_key v2-read '203.0.113.7,2001:db8::/48' "$expires")"
	id="$(key_id "$key")"
	assert_equal "$(echo "$key" | jq -c .ALLOWED_IPS)" '["203.0.113.7","2001:db8::/48"]'
	assert_equal "$(echo "$key" | jq -r .EXPIRES)" "$expires"
	run grep -E "^(ALLOWED_IPS|EXPIRES)=" "$KEYS_DIR/$id"
	assert_output "ALLOWED_IPS='203.0.113.7,2001:db8::/48'
EXPIRES='$expires'"
	run v-list-access-keys "$KEY_USER" json
	assert_equal "$(echo "$output" | jq -r ".\"$id\".EXPIRES")" "$expires"
	assert_equal "$(echo "$output" | jq -c ".\"$id\".ALLOWED_IPS")" '["203.0.113.7","2001:db8::/48"]'
}

@test "v-add-access-key keeps the old argument order: FORMAT as the fourth argument" {
	run v-add-access-key "$KEY_USER" v2-read kr-bats json
	assert_success
	assert_equal "$(echo "$output" | jq -r .EXPIRES)" ""
	assert_equal "$(echo "$output" | jq -c .ALLOWED_IPS)" "[]"
	run v-add-access-key "$KEY_USER" v2-read kr-bats
	assert_success
	assert_output --partial "ACCESS_KEY_ID:"
}

@test "v-add-access-key refuses a bad address list" {
	local many
	run v-add-access-key "$KEY_USER" v2-read kr-bats '203.0.113.300' '' json
	assert_failure 2
	run v-add-access-key "$KEY_USER" v2-read kr-bats '203.0.113.0/33' '' json
	assert_failure 2
	run v-add-access-key "$KEY_USER" v2-read kr-bats 'example.com' '' json
	assert_failure 2
	many="$(for i in $(seq 1 21); do printf '198.51.100.%s,' "$i"; done)"
	run v-add-access-key "$KEY_USER" v2-read kr-bats "${many%,}" '' json
	assert_failure 2
}

@test "v-add-access-key refuses an expiry less than an hour or more than three years ahead" {
	run v-add-access-key "$KEY_USER" v2-read kr-bats '' "$(($(date +%s) + 600))" json
	assert_failure 2
	run v-add-access-key "$KEY_USER" v2-read kr-bats '' "$(($(date +%s) + 4 * 366 * 86400))" json
	assert_failure 2
	run v-add-access-key "$KEY_USER" v2-read kr-bats '' 'tomorrow' json
	assert_failure 2
}

@test "Both key checks refuse an expired key like a wrong secret, and log the failure" {
	local key id secret
	key="$(new_key 'v2-read,mail-accounts' '' "$(($(date +%s) + 7200))")"
	id="$(key_id "$key")"
	secret="$(key_secret "$key")"
	check_v2 "$id" "$secret" 127.0.0.1
	assert_success
	expire_key "$id"
	check_v2 "$id" "$secret" 127.0.0.1
	assert_failure 9
	assert_output --partial "expired"
	run v-check-access-key "$id" "$secret" v-list-mail-domains 127.0.0.1 json
	assert_failure 9
	run tail -n 2 /usr/local/arcpanel/log/auth.log
	assert_output --partial "$id 127.0.0.1 failed to login"
}

@test "Both key checks refuse an address outside the key's list and accept one inside" {
	local key id secret
	key="$(new_key 'v2-read,mail-accounts' '198.51.100.0/24,2001:db8:1::/64' '')"
	id="$(key_id "$key")"
	secret="$(key_secret "$key")"
	check_v2 "$id" "$secret" 198.51.100.77
	assert_success
	check_v2 "$id" "$secret" 2001:db8:1::5
	assert_success
	check_v2 "$id" "$secret" 203.0.113.1
	assert_failure 11
	check_v2 "$id" "$secret" 2001:db8:2::5
	assert_failure 11
	run v-check-access-key "$id" "$secret" v-list-mail-domains 198.51.100.1 json
	assert_success
	run v-check-access-key "$id" "$secret" v-list-mail-domains 203.0.113.1 json
	assert_failure 10
	assert_output --partial "IP is not allowed to connect with API"
}

@test "API v2 answers 401 to an expired key and 403 E_API_DISABLED outside the key's list" {
	local key
	key="$(new_key v2-read '' "$(($(date +%s) + 7200))")"
	assert_equal "$(v2_status "$(key_id "$key")" "$(key_secret "$key")")" "200"
	assert_equal "$(jq -r .data.key_expires_at "$BATS_FILE_TMPDIR/body")" "$(date -u -d "@$(echo "$key" | jq -r .EXPIRES)" +%Y-%m-%dT%H:%M:%SZ)"
	expire_key "$(key_id "$key")"
	assert_equal "$(v2_status "$(key_id "$key")" "$(key_secret "$key")")" "401"
	key="$(new_key v2-read '203.0.113.9' '')"
	assert_equal "$(v2_status "$(key_id "$key")" "$(key_secret "$key")")" "403"
	assert_equal "$(jq -r .error.code "$BATS_FILE_TMPDIR/body")" "E_API_DISABLED"
	key="$(new_key v2-read '127.0.0.1' '')"
	assert_equal "$(v2_status "$(key_id "$key")" "$(key_secret "$key")")" "200"
	assert_equal "$(jq -c .data.key_allowed_ips "$BATS_FILE_TMPDIR/body")" '["127.0.0.1"]'
}

@test "API v1 refuses an expired key and an address outside the key's list" {
	local key id secret
	key="$(new_key mail-accounts '127.0.0.1' '')"
	run v1_post "$(key_id "$key")" "$(key_secret "$key")" v-list-mail-domains
	assert_equal "${lines[-1]}" "200"
	key="$(new_key mail-accounts '203.0.113.9' '')"
	run v1_post "$(key_id "$key")" "$(key_secret "$key")" v-list-mail-domains
	assert_output --partial "IP is not allowed to connect with API"
	key="$(new_key mail-accounts '' "$(($(date +%s) + 7200))")"
	id="$(key_id "$key")"
	secret="$(key_secret "$key")"
	expire_key "$id"
	run v1_post "$id" "$secret" v-list-mail-domains
	assert_output --partial "Access key authentication failed"
}

@test "The v2-observe set is installed and leaves out file contents, secrets and restic file listings" {
	run v-list-apis json
	assert_success
	assert_equal "$(echo "$output" | jq -r '."v2-observe".ROLE')" "user"
	assert_equal "$(echo "$output" | jq -r '."v2-observe".API_VERSION')" "2"
	local observe
	observe="$(grep '^COMMANDS=' /usr/local/arcpanel/data/api/v2-observe | cut -d"'" -f2)"
	for cmd in v-open-fs-file v-read-fs-file v-add-fs-download v-search-fs-object v-list-fs-directory-json \
		v-get-fs-file-info v-list-fs-disk-usage v-list-user-secret-store v-list-user-secret v-list-user-files-restic; do
		[[ ",$observe," != *",$cmd,"* ]] || fail "$cmd is in v2-observe"
	done
	[[ ",$observe," == *",v-list-web-domains,"* ]] || fail "v-list-web-domains is not in v2-observe"
}

@test "An observe key reads web domains and is refused file reads" {
	local key id secret file
	key="$(new_key v2-observe)"
	id="$(key_id "$key")"
	secret="$(key_secret "$key")"
	check_v2 "$id" "$secret" 127.0.0.1
	assert_success
	run curl -sk -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $id:$secret" "$PANEL/api/v2/users/$KEY_USER/web-domains"
	assert_output "200"
	run curl -sk -o "$BATS_FILE_TMPDIR/body" -w '%{http_code}' -H "Authorization: Bearer $id:$secret" "$PANEL/api/v2/users/$KEY_USER/files?path=web"
	assert_output "403"
	assert_equal "$(jq -r .error.code "$BATS_FILE_TMPDIR/body")" "E_FORBIDDEN"
}
