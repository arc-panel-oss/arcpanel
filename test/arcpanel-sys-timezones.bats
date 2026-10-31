#!/usr/bin/env bats
# v-get-sys-timezones: the [FORMAT] list of the timezones in /usr/share/zoneinfo,
# in the plain, json and shell formats.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

# The zones the command lists, computed the same way it computes them, so the
# test holds on any test server whatever its tzdata package ships.
zones() {
	(cd /usr/share/zoneinfo/ && find -L * -type f -or -type l | sort)
}

@test "v-get-sys-timezones plain prints one zone per line, sorted" {
	run v-get-sys-timezones plain
	assert_success
	assert_equal "$output" "$(zones)"
}

@test "v-get-sys-timezones without a format prints the shell format" {
	run v-get-sys-timezones
	assert_success
	assert_line --index 0 'Timezone'
	assert_line --index 1 --regexp '^--------$'
}

@test "v-get-sys-timezones shell prints the header, then the zones" {
	run v-get-sys-timezones shell
	assert_success
	assert_line --index 0 'Timezone'
	assert_line --index 1 --regexp '^--------$'
	# column -t over a single column keeps one zone per line after the header
	assert_equal "$(tail -n +3 <<< "$output")" "$(zones)"
}

@test "v-get-sys-timezones json prints the zones as a json array" {
	run v-get-sys-timezones json
	assert_success
	echo "$output" | jq -e 'type == "array"' > /dev/null
	assert_equal "$(echo "$output" | jq -r '.[]')" "$(zones)"
	assert_equal "$(echo "$output" | jq -r '.[] | select(. == "UTC")')" "UTC"
}
