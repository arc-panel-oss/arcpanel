#!/usr/bin/env bats
# v-list-sys-shells: the [FORMAT] list of the system shells in /etc/shells,
# in the plain, json and shell formats.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

# The shells the command lists, computed the same way it computes them, so
# the test holds on any test server whatever its /etc/shells ships.
shells() {
	grep -v '#' /etc/shells | awk -F '/' '{print $NF}' | sort -u
}

@test "v-list-sys-shells plain prints one shell per line, sorted unique" {
	run v-list-sys-shells plain
	assert_success
	assert_equal "$output" "$(shells)"
}

@test "v-list-sys-shells without a format prints the shell format" {
	run v-list-sys-shells
	assert_success
	assert_line --index 0 'SHELL'
	assert_line --index 1 --regexp '^-----$'
}

@test "v-list-sys-shells shell prints the header, then the shells" {
	run v-list-sys-shells shell
	assert_success
	assert_line --index 0 'SHELL'
	assert_line --index 1 --regexp '^-----$'
	assert_equal "$(tail -n +3 <<< "$output")" "$(shells)"
}

@test "v-list-sys-shells json prints the shells as a json array" {
	run v-list-sys-shells json
	assert_success
	echo "$output" | jq -e 'type == "array"' > /dev/null
	assert_equal "$(echo "$output" | jq -r '.[]')" "$(shells)"
}
