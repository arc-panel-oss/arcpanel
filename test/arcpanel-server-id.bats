#!/usr/bin/env bats
# Server identifier and the key fields of GET /me (E43 M1, B1,
# docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §4).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

CONF=/usr/local/arcpanel/conf/arcpanel.conf
SAVED=/root/.arcbats-server-id.conf
KEY_USER="arcbatssid"
UUID4='^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'

conf_value() {
	grep "^$1=" "$CONF" | tail -n1 | cut -d"'" -f2
}

drop_keys() {
	sed -i -e '/^SERVER_ID=/d' -e '/^SERVER_ID_MACHINE=/d' "$CONF"
}

setup_file() {
	local pass
	grep -E '^SERVER_ID(_MACHINE)?=' "$CONF" > "$SAVED" || true
	pass="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
	v-add-user "$KEY_USER" "$pass" "$KEY_USER@example.invalid" default > /dev/null
}

teardown_file() {
	local id
	for id in $(v-list-access-keys "$KEY_USER" json 2> /dev/null | jq -r 'keys[]'); do
		v-delete-access-key "$id" > /dev/null 2>&1
	done
	v-delete-user "$KEY_USER" > /dev/null 2>&1 || true
	drop_keys
	cat "$SAVED" >> "$CONF"
	rm -f "$SAVED"
}

@test "v-update-sys-server-id creates a random UUID and the machine hash when missing" {
	drop_keys
	run v-update-sys-server-id json
	assert_success
	local id
	id="$(echo "$output" | jq -r .SERVER_ID)"
	[[ "$id" =~ $UUID4 ]] || fail "not a version 4 UUID: $id"
	assert_equal "$(conf_value SERVER_ID)" "$id"
	assert_equal "$(conf_value SERVER_ID_MACHINE)" "$(sha256sum /etc/machine-id | cut -d' ' -f1)"
}

@test "v-update-sys-server-id keeps the identifier while the machine identity is the same" {
	local before
	v-update-sys-server-id > /dev/null
	before="$(conf_value SERVER_ID)"
	run v-update-sys-server-id plain
	assert_success
	assert_output "$before"
	assert_equal "$(grep -c '^SERVER_ID=' "$CONF")" "1"
	run v-update-sys-server-id shell
	assert_output --partial "SERVER_ID:"
	assert_output --partial "$before"
}

@test "v-update-sys-server-id writes a new identifier when the machine identity changed, and logs it" {
	local before
	v-update-sys-server-id > /dev/null
	before="$(conf_value SERVER_ID)"
	# A clone whose /etc/machine-id was regenerated: the stored hash no longer matches
	sed -i "s/^SERVER_ID_MACHINE=.*/SERVER_ID_MACHINE='0000000000000000000000000000000000000000000000000000000000000000'/" "$CONF"
	run v-update-sys-server-id json
	assert_success
	local after
	after="$(echo "$output" | jq -r .SERVER_ID)"
	[[ "$after" =~ $UUID4 ]] || fail "not a version 4 UUID: $after"
	[ "$after" != "$before" ] || fail "the identifier did not change"
	assert_equal "$(conf_value SERVER_ID_MACHINE)" "$(sha256sum /etc/machine-id | cut -d' ' -f1)"
	run grep -F "$after" /usr/local/arcpanel/log/activity.log
	assert_success
	assert_output --partial "$before"
}

@test "The daily queue checks the identifier" {
	sed -i "s/^SERVER_ID_MACHINE=.*/SERVER_ID_MACHINE='1111111111111111111111111111111111111111111111111111111111111111'/" "$CONF"
	run v-update-sys-queue daily
	assert_success
	assert_equal "$(conf_value SERVER_ID_MACHINE)" "$(sha256sum /etc/machine-id | cut -d' ' -f1)"
}

@test "A boot hook checks the identifier" {
	v-update-sys-server-id > /dev/null
	run cat /etc/cron.d/arcpanel-server-id
	assert_success
	assert_output --regexp '^@reboot root .*/usr/local/arcpanel/bin/v-update-sys-server-id'
}

@test "v-list-sys-config prints SERVER_ID" {
	v-update-sys-server-id > /dev/null
	run v-list-sys-config json
	assert_success
	assert_equal "$(echo "$output" | jq -r .config.SERVER_ID)" "$(conf_value SERVER_ID)"
}

@test "v-check-access-key-permissions prints the key's expiry and address list, empty without them" {
	local key id secret file
	key="$(v-add-access-key "$KEY_USER" v2-read sid-bats json)"
	id="$(echo "$key" | jq -r .ACCESS_KEY_ID)"
	secret="$(echo "$key" | jq -r .SECRET_ACCESS_KEY)"
	file="$(mktemp /tmp/arcbats.XXXXXX)"
	echo "$secret" > "$file"
	run v-check-access-key-permissions "$id" "$file" 127.0.0.1 json
	rm -f "$file"
	assert_success
	assert_equal "$(echo "$output" | jq -r .EXPIRES)" ""
	assert_equal "$(echo "$output" | jq -c .ALLOWED_IPS)" "[]"
}

@test "The upgrade step of the release with E43 sets the identifier" {
	run grep -c 'v-update-sys-server-id' /usr/local/arcpanel/install/upgrade/versions/2.2.0.sh
	assert_success
	run grep -c 'v-update-sys-server-id' /usr/local/arcpanel/install/arcpanel-install-ubuntu.sh /usr/local/arcpanel/install/arcpanel-install-debian.sh
	assert_success
}
