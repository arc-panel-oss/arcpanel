#!/usr/bin/env bats
# Community links in the installers' closing message
# (docs/features/specs/2026-11-01-001-community-touchpoints-design.md §3).
# Reads the repository only, no server needed.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"

# The closing message from "Documentation:" to the line before "Note:", without CR
community_block() {
	tr -d '\r' < "$REPO_ROOT/install/$1" | sed -n '/^Documentation:  /,/^Note: /p' | sed '$d'
}

@test "installer message: both installers print the same community block" {
	ubuntu=$(community_block arcpanel-install-ubuntu.sh)
	debian=$(community_block arcpanel-install-debian.sh)
	[ -n "$ubuntu" ]
	assert_equal "$ubuntu" "$debian"
}

@test "installer message: it links questions, bug reports, show and tell and the repository" {
	run community_block arcpanel-install-ubuntu.sh
	assert_line 'Documentation:  https://arcpanel.org/docs'
	assert_line 'Questions:      https://github.com/arc-panel-oss/arcpanel/discussions/categories/q-a'
	assert_line 'Bug reports:    https://github.com/arc-panel-oss/arcpanel/issues'
	assert_line 'Show and tell:  https://github.com/arc-panel-oss/arcpanel/discussions/categories/show-and-tell'
	assert_line 'If ArcPanel is useful to you, a star on GitHub helps others find it:'
	assert_line 'https://github.com/arc-panel-oss/arcpanel'
}
