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
