#!/usr/bin/env bats
# v-list-dns-templates: the [FORMAT] list of the DNS templates in
# $ARCPANEL/data/templates/dns, in the plain, json and shell formats.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
DNSTPL=$ARCPANEL/data/templates/dns

# The templates the command lists, computed the same way it computes them
# (newest first, name cut at the first dot), so the test holds on any test
# server whatever templates it ships.
templates() {
	ls -t "$DNSTPL" | grep '\.tpl' | cut -f 1 -d '.'
}

@test "v-list-dns-templates plain prints one template per line, newest first" {
	run v-list-dns-templates plain
	assert_success
	assert_equal "$output" "$(templates)"
}

@test "v-list-dns-templates without a format prints the shell format" {
	run v-list-dns-templates
	assert_success
	assert_line --index 0 'TEMPLATE'
	assert_line --index 1 --regexp '^--------$'
}

@test "v-list-dns-templates shell prints the header, then the templates" {
	run v-list-dns-templates shell
	assert_success
	assert_line --index 0 'TEMPLATE'
	assert_line --index 1 --regexp '^--------$'
	assert_equal "$(tail -n +3 <<< "$output")" "$(templates)"
}

@test "v-list-dns-templates json prints the templates as a json array" {
	run v-list-dns-templates json
	assert_success
	echo "$output" | jq -e 'type == "array"' > /dev/null
	assert_equal "$(echo "$output" | jq -r '.[]')" "$(templates)"
}
