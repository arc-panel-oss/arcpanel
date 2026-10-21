#!/usr/bin/env bats
# Access keys from /dev/urandom (E39 W4 M1,
# docs/features/specs/2026-10-16-002-e39-w4-authorization-and-tenancy-design.md §6).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

KEY_USER="arcbatskeys"
ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
ROOT_USER="$(v-list-sys-config json | jq -r .config.ROOT_USER)"

setup_file() {
	local pass
	pass="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
	v-add-user "$KEY_USER" "$pass" "$KEY_USER@example.invalid" default > /dev/null
}

teardown_file() {
	local id
	for id in $(v-list-access-keys "$KEY_USER" json 2> /dev/null | jq -r 'keys[]'); do
		v-delete-access-key "$id" > /dev/null 2>&1
	done
	v-delete-user "$KEY_USER" > /dev/null 2>&1 || true
}

@test "New keys have 20-character IDs and 40-character secrets of the documented alphabets" {
	local i key ids=() secrets=()
	for i in $(seq 1 20); do
		key="$(v-add-access-key "$KEY_USER" mail-accounts keys-bats json)"
		ids+=("$(echo "$key" | jq -r .ACCESS_KEY_ID)")
		secrets+=("$(echo "$key" | jq -r .SECRET_ACCESS_KEY)")
	done
	for i in "${!ids[@]}"; do
		[[ "${ids[$i]}" =~ ^[A-Za-z0-9]{20}$ ]] || fail "bad ID ${ids[$i]}"
		[[ "${secrets[$i]}" =~ ^[A-Za-z0-9_=-]{40}$ ]] || fail "bad secret for ${ids[$i]}"
	done
	# No two alike
	assert_equal "$(printf '%s\n' "${ids[@]}" | sort -u | wc -l)" "20"
	assert_equal "$(printf '%s\n' "${secrets[@]}" | sort -u | wc -l)" "20"
}

@test "v-check-access-key reports the permission sets of the key" {
	local key id secret
	key="$(v-add-access-key "$KEY_USER" mail-accounts keys-bats json)"
	id="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	secret="$(echo "$key" | jq -r .SECRET_ACCESS_KEY)"
	run v-check-access-key "$id" "$secret" v-list-mail-domains 127.0.0.1 json
	assert_success
	assert_equal "$(echo "$output" | jq -r .PERMISSIONS)" "mail-accounts"
	assert_equal "$(echo "$output" | jq -r .USER)" "$KEY_USER"
}

@test "The key generator does not use bash \$RANDOM" {
	# Regression for M1 of the audit: $RANDOM has about 31 bits of state
	run grep -c 'RANDOM' "$(command -v v-add-access-key)"
	assert_output "0"
}

# Access-key comments and forged key files (E39 W5 M1,
# docs/features/specs/2026-10-21-002-e39-w5-resources-and-hardening-design.md §4)

@test "A comment with a control character is refused and writes no key" {
	local before c
	before="$(ls "$ARCPANEL/data/access-keys" | wc -l)"
	for c in $'\n' $'\r' $'\t' $'\v' $'\f'; do
		run v-add-access-key "$KEY_USER" mail-accounts "ab${c}USER admin${c}PERMISSIONS${c}zz" json
		assert_failure
	done
	assert_equal "$(ls "$ARCPANEL/data/access-keys" | wc -l)" "$before"
}

@test "A comment with spaces, dots, dashes and underscores is accepted" {
	run v-add-access-key "$KEY_USER" mail-accounts "deploy key-2.prod_eu" json
	assert_success
	assert_equal "$(echo "$output" | jq -r .COMMENT)" "deploy key-2.prod_eu"
}

@test "A control character in any argument is refused" {
	run v-add-access-key "$KEY_USER" $'mail-accounts\nweb' keys-bats json
	assert_failure
	run v-add-access-key "$KEY_USER" mail-accounts keys-bats $'127.0.0.1\n' '' json
	assert_failure
}

@test "A key file that names USER twice is refused by the readers" {
	local key id secret file
	key="$(v-add-access-key "$KEY_USER" mail-accounts keys-bats json)"
	id="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	secret="$(echo "$key" | jq -r .SECRET_ACCESS_KEY)"
	file="$ARCPANEL/data/access-keys/$id"
	printf "USER='%s'\nPERMISSIONS=''\n" "$ROOT_USER" >> "$file"

	run v-check-access-key "$id" "$secret" v-list-sys-info 127.0.0.1 json
	assert_failure
	local secret_file
	secret_file="$(mktemp -p /tmp)"
	printf '%s' "$secret" > "$secret_file"
	run v-check-access-key-permissions "$id" "$secret_file" 127.0.0.1 json
	rm -f "$secret_file"
	assert_failure
	run v-list-access-key "$id" json
	assert_failure
	# The list skips it instead of failing
	run v-list-access-keys "$KEY_USER" json
	assert_success
	refute_output --partial "$id"
	rm -f "$file"
}

@test "The 2.2.3 upgrade step moves forged key files to the quarantine" {
	local key id good bad step
	step="$BATS_TEST_DIRNAME/../install/upgrade/versions/2.2.3.sh"
	[ -f "$step" ] || fail "missing $step"
	key="$(v-add-access-key "$KEY_USER" mail-accounts keys-bats json)"
	good="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	key="$(v-add-access-key "$KEY_USER" mail-accounts keys-bats json)"
	bad="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	printf "PERMISSIONS=''\n" >> "$ARCPANEL/data/access-keys/$bad"

	# Sourced with an older func/main.sh, the step checks and moves nothing
	run bash -c 'source /etc/profile.d/arcpanel.sh; source "$ARCPANEL/conf/arcpanel.conf"; source "$ARCPANEL/func/main.sh"; unset -f access_key_file_ok; BIN="$ARCPANEL/bin"; source "$1"' _ "$step"
	assert_success
	assert_output --partial "were not checked"
	[ -f "$ARCPANEL/data/access-keys/$good" ] || fail "a key was moved without the check"
	[ -f "$ARCPANEL/data/access-keys/$bad" ] || fail "a key was moved without the check"

	run bash -c 'source /etc/profile.d/arcpanel.sh; source "$ARCPANEL/conf/arcpanel.conf"; source "$ARCPANEL/func/main.sh"; BIN="$ARCPANEL/bin"; source "$1"' _ "$step"
	assert_success
	assert_output --partial "$bad"
	refute_output --partial "$good"
	[ ! -e "$ARCPANEL/data/access-keys/$bad" ] || fail "forged key still in place"
	[ -f "$ARCPANEL/data/access-keys/.quarantine/$bad" ] || fail "forged key not in the quarantine"
	[ -f "$ARCPANEL/data/access-keys/$good" ] || fail "a good key was moved"
	assert_equal "$(stat -c '%U %a' "$ARCPANEL/data/access-keys/.quarantine")" "root 700"
	# Running it again changes nothing
	run bash -c 'source /etc/profile.d/arcpanel.sh; source "$ARCPANEL/conf/arcpanel.conf"; source "$ARCPANEL/func/main.sh"; BIN="$ARCPANEL/bin"; source "$1"' _ "$step"
	assert_success
	[ -f "$ARCPANEL/data/access-keys/$good" ] || fail "a good key was moved"
	rm -f "$ARCPANEL/data/access-keys/.quarantine/$bad"
}
