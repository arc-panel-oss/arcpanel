#!/usr/bin/env bats
# REST API v1 (lib/api) switch, single sign-on exception and fixes (E39 W4,
# docs/features/specs/2026-10-16-002-e39-w4-authorization-and-tenancy-design.md §7).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

PANEL="https://127.0.0.1:8083"
V1_ADMIN="arcbatsv1adm"
LIMIT_DIR="/usr/local/arcpanel/web-app/storage/framework/api-v1"

v1_config() {
	v-list-sys-config json | jq -r ".config.$1"
}

# POST to API v1 at URL with an access key; prints the body, then the status
# on a line of its own. Arguments after the command become arg1, arg2, …
v1_post_to() {
	local url="$1" id="$2" secret="$3" cmd="$4" i=1 data=()
	shift 4
	for arg in "$@"; do
		data+=(--data-urlencode "arg$i=$arg")
		i=$((i + 1))
	done
	curl -sk -X POST -w '\n%{http_code}' --data-urlencode "access_key=$id" --data-urlencode "secret_key=$secret" \
		--data-urlencode "returncode=no" --data-urlencode "cmd=$cmd" "${data[@]}" "$url/api/index.php"
}

v1_post() {
	v1_post_to "$PANEL" "$@"
}

# Waits until PHP-FPM serves the current lib/api/index.php (OPcache)
v1_says() {
	local i
	for i in $(seq 1 10); do
		"${@:2}" | grep -qF "$1" && return 0
		sleep 1
	done
	return 1
}

new_key() {
	v-add-access-key "$1" "$2" v1-bats json
}

setup_file() {
	local pass
	pass="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
	v-add-user "$V1_ADMIN" "$pass" "$V1_ADMIN@example.invalid" default > /dev/null
	v-change-user-role "$V1_ADMIN" admin > /dev/null
	ROOT_USER="$(v1_config ROOT_USER)"
	export ROOT_USER
	{
		echo "v-change-sys-config-value API '$(v1_config API)'"
		echo "v-change-sys-config-value API_V1 '$(v1_config API_V1)'"
		echo "v-change-sys-config-value API_SYSTEM '$(v1_config API_SYSTEM)'"
		echo "v-change-sys-config-value API_ALLOWED_IP '$(v1_config API_ALLOWED_IP)'"
	} > "$BATS_FILE_TMPDIR/restore-config"
	v-change-sys-config-value API_SYSTEM 2
	v-change-sys-config-value API_ALLOWED_IP allow-all

	local key
	key="$(new_key "$ROOT_USER" "")"
	ROOT_KEY_ID="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	ROOT_KEY_SECRET="$(echo "$key" | jq -r .SECRET_ACCESS_KEY)"
	key="$(new_key "$ROOT_USER" phpmyadmin-sso)"
	SSO_KEY_ID="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	SSO_KEY_SECRET="$(echo "$key" | jq -r .SECRET_ACCESS_KEY)"
	key="$(new_key "$V1_ADMIN" mail-accounts)"
	ADMIN_KEY_ID="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	ADMIN_KEY_SECRET="$(echo "$key" | jq -r .SECRET_ACCESS_KEY)"
	export ROOT_KEY_ID ROOT_KEY_SECRET SSO_KEY_ID SSO_KEY_SECRET ADMIN_KEY_ID ADMIN_KEY_SECRET
}

teardown_file() {
	local id
	for id in "$ROOT_KEY_ID" "$SSO_KEY_ID" "$ADMIN_KEY_ID"; do
		[ -n "$id" ] && v-delete-access-key "$id" > /dev/null 2>&1
	done
	bash "$BATS_FILE_TMPDIR/restore-config" > /dev/null 2>&1 || true
	v-delete-user "$V1_ADMIN" > /dev/null 2>&1 || true
	rm -f "$LIMIT_DIR"/*
}

setup() {
	# Failed authentications of earlier cases must not reach the limiter
	rm -f "$LIMIT_DIR"/*
	v-change-sys-config-value API_V1 yes
}

@test "API v1 answers only with API_V1=yes" {
	run v1_says '"sysinfo"' v1_post "$ROOT_KEY_ID" "$ROOT_KEY_SECRET" v-list-sys-info json
	assert_success
	v-change-sys-config-value API_V1 no
	run v1_post "$ROOT_KEY_ID" "$ROOT_KEY_SECRET" v-list-sys-info json
	assert_output --partial "API v1 is disabled"
	refute_output --partial '"sysinfo"'
}

@test "With API v1 off the single sign-on key passes from the loopback address only" {
	v-change-sys-config-value API_V1 no
	# Through the gate; the set still limits it to its two commands
	run v1_post "$SSO_KEY_ID" "$SSO_KEY_SECRET" v-list-sys-info json
	refute_output --partial "API v1 is disabled"
	assert_output --partial "permission to run the command"

	# The same key from another address of the server, with a forwarded header
	local ip
	ip="$(hostname -I | awk '{print $1}')"
	[ -n "$ip" ] && [ "$ip" != "127.0.0.1" ] || skip "the server has no address besides the loopback"
	run curl -sk -X POST -H 'X-Forwarded-For: 127.0.0.1' -H 'X-Real-IP: 127.0.0.1' \
		--data-urlencode "access_key=$SSO_KEY_ID" --data-urlencode "secret_key=$SSO_KEY_SECRET" \
		--data-urlencode "cmd=v-delete-database-temp-user" "https://$ip:8083/api/index.php"
	assert_output --partial "API v1 is disabled"
}

@test "The single sign-on key in the hash form passes too" {
	v-change-sys-config-value API_V1 no
	run curl -sk -X POST --data-urlencode "hash=$SSO_KEY_ID:$SSO_KEY_SECRET" --data-urlencode "cmd=v-list-sys-info" \
		"$PANEL/api/index.php"
	refute_output --partial "API v1 is disabled"
	assert_output --partial "permission to run the command"
}

@test "An unknown key and a wrong secret get the same answer" {
	local unknown wrong
	unknown="$(v1_post "AAAAAAAAAAAAAAAAAAAA" "$ROOT_KEY_SECRET" v-list-sys-info json)"
	wrong="$(v1_post "$ROOT_KEY_ID" "$(printf 'x%.0s' $(seq 1 40))" v-list-sys-info json)"
	assert_equal "$unknown" "$wrong"
	run echo "$unknown"
	assert_output --partial "Access key authentication failed"
	refute_output --partial "$ROOT_KEY_ID"
}

@test "Ten failed authentications a minute answer 429" {
	local i
	for i in $(seq 1 10); do
		v1_post "$ROOT_KEY_ID" "$(printf 'y%.0s' $(seq 1 40))" v-list-sys-info json > /dev/null
	done
	# Even the right secret is refused until the window ends
	run v1_post "$ROOT_KEY_ID" "$ROOT_KEY_SECRET" v-list-sys-info json
	assert_equal "${lines[-1]}" "429"
	rm -f "$LIMIT_DIR"/*
	run v1_post "$ROOT_KEY_ID" "$ROOT_KEY_SECRET" v-list-sys-info json
	assert_output --partial '"sysinfo"'
}

@test "Arguments must be contiguous" {
	run curl -sk -X POST --data-urlencode "access_key=$ROOT_KEY_ID" --data-urlencode "secret_key=$ROOT_KEY_SECRET" \
		--data-urlencode "cmd=v-list-user" --data-urlencode "arg1=$ROOT_USER" --data-urlencode "arg3=json" \
		"$PANEL/api/index.php"
	assert_output --partial "arg3 follows a missing argument"
}

@test "A key of another administrator acts only on its own account" {
	# Only keys of ROOT_USER may name another user: the others need user sets,
	# whose commands are bound to the key's user (why N16 was withdrawn)
	run v1_post "$ADMIN_KEY_ID" "$ADMIN_KEY_SECRET" v-list-mail-domains "$ROOT_USER" json
	assert_output --partial "doesn't match the key's user"
	run v1_post "$ADMIN_KEY_ID" "$ADMIN_KEY_SECRET" v-list-mail-domains "$V1_ADMIN" json
	refute_output --partial "doesn't match"
	[[ "${lines[-1]}" =~ ^2[0-9][0-9]$ ]] || fail "status ${lines[-1]}"
	run v-add-access-key "$V1_ADMIN" billing v1-bats json
	assert_failure
	assert_output --partial "Only the admin can run this API"
}

@test "API v1 gives a user key no way to write a line break into a key file (E39 W5 L11)" {
	local user="arcbatsv1usr" pass key id secret before
	pass="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
	v-add-user "$user" "$pass" "$user@example.invalid" default > /dev/null
	key="$(new_key "$user" v2-accounts)"
	id="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	secret="$(echo "$key" | jq -r .SECRET_ACCESS_KEY)"
	before="$(ls /usr/local/arcpanel/data/access-keys | wc -l)"

	run v1_post "$id" "$secret" v-add-access-key "$user" v2-read $'ab\nUSER '"$ROOT_USER"$'\nPERMISSIONS\nzz' json
	refute_output --partial '"ACCESS_KEY_ID"'
	assert_equal "$(ls /usr/local/arcpanel/data/access-keys | wc -l)" "$before"
	run grep -lc "^USER='$ROOT_USER'" $(grep -l "^USER='$user'" /usr/local/arcpanel/data/access-keys/* 2> /dev/null) /dev/null
	refute_output --partial "/data/access-keys/"

	v-delete-access-key "$id" > /dev/null 2>&1
	v-delete-user "$user" > /dev/null 2>&1 || true
}

@test "v-change-sys-api switches keys and refuses unknown versions" {
	run v-change-sys-api enable v1
	assert_success
	assert_equal "$(v1_config API_V1)" "yes"
	run v-change-sys-api enable nonsense
	assert_failure
	run v-change-sys-api remove
	assert_failure
}

@test "v-add-sys-pma-sso refuses to run while API_SYSTEM is 0" {
	[ -d /usr/share/phpmyadmin ] || skip "phpMyAdmin is not installed"
	[ ! -f /usr/share/phpmyadmin/arcpanel-sso.php ] || skip "single sign-on is already set up"
	v-change-sys-config-value API_SYSTEM 0
	run v-add-sys-pma-sso quiet
	v-change-sys-config-value API_SYSTEM 2
	assert_failure 2
	assert_output --partial "API is not enabled"
	assert_file_not_exists /usr/share/phpmyadmin/arcpanel-sso.php
}
