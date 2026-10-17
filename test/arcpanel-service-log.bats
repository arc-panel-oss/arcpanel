#!/usr/bin/env bats
# Service log excerpts (E43 M5, B5):
# docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §8.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel

@test "v-list-sys-service-log prints the last lines of a listed service's journal" {
	# A reload writes lines into the journal of the nginx unit
	systemctl reload nginx
	run v-list-sys-service-log nginx 5 json
	assert_success
	assert_equal "$(echo "$output" | jq -c '[.SERVICE, .UNIT]')" '["nginx","nginx"]'
	assert_equal "$(echo "$output" | jq -r '.LINES | length')" 5
	assert_equal "$(echo "$output" | jq -r '.LINES[]')" "$(journalctl -u nginx -n 5 --no-pager --quiet -o short-iso)"
}

@test "The plain, shell and csv formats print the lines" {
	local json
	json=$(v-list-sys-service-log nginx 3 json)
	run v-list-sys-service-log nginx 3 plain
	assert_output "$(echo "$json" | jq -r '.LINES[]')"
	run v-list-sys-service-log nginx 3
	assert_output "$(echo "$json" | jq -r '.LINES[]')"
	run v-list-sys-service-log nginx 3 csv
	assert_line --index 0 'LINE'
	assert_equal "${#lines[@]}" 4
}

@test "Without LINES the command prints at most 100 lines, and it accepts 1 to 500" {
	run v-list-sys-service-log arcpanel '' json
	assert_success
	[ "$(echo "$output" | jq -r '.LINES | length')" -le 100 ] || fail "more than 100 lines"
	run v-list-sys-service-log arcpanel 500 json
	assert_success
	[ "$(echo "$output" | jq -r '.LINES | length')" -le 500 ] || fail "more than 500 lines"
	for lines in 0 501 abc -5; do
		run v-list-sys-service-log arcpanel "$lines" json
		assert_failure 2
	done
	run v-list-sys-service-log arcpanel 5 xml
	assert_failure 2
}

@test "The command refuses a service that v-list-sys-services does not list" {
	run v-list-sys-service-log arcbats-nosuch
	assert_failure 3
	run v-list-sys-service-log systemd-journald
	assert_failure 3
	run v-list-sys-service-log '../nginx'
	assert_failure 2
}

@test "The web terminal is read from its unit" {
	if ! v-list-sys-services json | jq -e 'has("web-terminal")' > /dev/null; then
		skip "the web terminal is off on this server"
	fi
	run v-list-sys-service-log web-terminal 5 json
	assert_success
	assert_equal "$(echo "$output" | jq -c '[.SERVICE, .UNIT]')" '["web-terminal","arcpanel-web-terminal"]'
}

@test "The firewall, which has no unit, has no lines" {
	local firewall
	firewall=$(v-list-sys-services json | jq -r 'to_entries[] | select(.value.SYSTEM == "firewall") | .key')
	if [ -z "$firewall" ] || systemctl cat "$firewall.service" > /dev/null 2>&1; then
		skip "no firewall without a unit on this server"
	fi
	run v-list-sys-service-log "$firewall" 5 json
	assert_success
	assert_equal "$(echo "$output" | jq -c '.LINES')" '[]'
}

@test "v-list-sys-service-log is in v2-server, v2-read, v2-observe and v2-write" {
	local set
	for set in v2-server v2-read v2-observe v2-write; do
		grep -q "^COMMANDS='\(.*,\)\?v-list-sys-service-log\(,.*\)\?'" "$ARCPANEL/data/api/$set" || fail "v-list-sys-service-log is not in $set"
	done
}
