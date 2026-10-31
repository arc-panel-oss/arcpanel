#!/usr/bin/env bats
# v-list-database-types: the [FORMAT] list of the database types the panel
# is configured for (DB_SYSTEM), in the plain, json and shell formats.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL_CONF=/usr/local/arcpanel/conf/arcpanel.conf

# The types the command lists: DB_SYSTEM from the panel config, split on
# commas, one per line - computed from the config so the test holds on any
# test server whatever database systems it runs.
dbtypes() {
	grep -E '^DB_SYSTEM=' "$ARCPANEL_CONF" | tail -n 1 | cut -d '=' -f 2- \
		| tr -d "'\"" | tr ',' '\n'
}

@test "v-list-database-types plain prints one type per line" {
	run v-list-database-types plain
	assert_success
	assert_equal "$output" "$(dbtypes)"
}

@test "v-list-database-types without a format prints the shell format" {
	run v-list-database-types
	assert_success
	assert_line --index 0 'TYPE'
	assert_line --index 1 --regexp '^----$'
}

@test "v-list-database-types shell prints the header, then the types" {
	run v-list-database-types shell
	assert_success
	assert_line --index 0 'TYPE'
	assert_line --index 1 --regexp '^----$'
	assert_equal "$(tail -n +3 <<< "$output")" "$(dbtypes)"
}

@test "v-list-database-types json prints the types as a json array" {
	run v-list-database-types json
	assert_success
	echo "$output" | jq -e 'type == "array"' > /dev/null
	assert_equal "$(echo "$output" | jq -r '.[]')" "$(dbtypes)"
}
